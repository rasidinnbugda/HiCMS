<?php

declare(strict_types=1);

namespace HiCMS\Auth;

use HiCMS\Database\Connection;
use HiCMS\Events\Auth\LoggedIn;
use HiCMS\Events\Dispatcher;
use HiCMS\Http\Csrf;
use HiCMS\Model\User;
use HiCMS\Repository\AuditLogRepository;
use HiCMS\Repository\UserRepository;
use HiCMS\Support\Dates;
use HiCMS\Support\Str;

/**
 * Kimlik doğrulama.
 *
 * Oturum PHP oturumunda tutulur; "beni hatırla" için ayrı bir çerez kullanılır.
 * Çerez `seçici:doğrulayıcı` biçimindedir — seçici veritabanında açık, doğrulayıcı
 * yalnızca hash'li saklanır. Böylece veritabanı sızsa bile çerezler taklit
 * edilemez.
 *
 * Başarısız denemeler `login_attempts` tablosuna yazılır ve IP başına kademeli
 * gecikme uygulanır.
 */
final class Auth
{
    private const SESSION_USER = 'hicms.user';
    private const SESSION_META = 'hicms.session_meta';
    private const COOKIE_NAME  = 'hicms_remember';

    private ?User $user = null;

    private bool $resolved = false;

    public function __construct(
        private readonly UserRepository $users,
        private readonly Roles $roles,
        private readonly Connection $db,
        private readonly Dispatcher $events,
        private readonly Csrf $csrf,
        private readonly AuditLogRepository $audit,
        private readonly bool $secureCookies = false,
        private readonly string $cookiePath = '/',
    ) {
    }

    /**
     * Oturumu başlatır. Çıktı basılmadan önce çağrılmalıdır.
     */
    public function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if (headers_sent()) {
            return;
        }

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => $this->cookiePath,
            'httponly' => true,
            'secure'   => $this->secureCookies,
            'samesite' => 'Lax',
        ]);

        session_name('hicms_session');
        session_start();
    }

    /**
     * Oturumdaki kullanıcıyı döndürür.
     */
    public function user(): ?User
    {
        if ($this->resolved) {
            return $this->user;
        }

        $this->resolved = true;

        $id = (int) ($_SESSION[self::SESSION_USER] ?? 0);

        if ($id > 0) {
            $user = $this->users->find($id);

            if ($user !== null && $user->isActive()) {
                return $this->user = $user;
            }

            // Kullanıcı silinmiş veya askıya alınmış: oturumu temizle.
            unset($_SESSION[self::SESSION_USER]);
        }

        return $this->user = $this->attemptRemember();
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function id(): int
    {
        return $this->user()?->id ?? 0;
    }

    /**
     * İzin denetimi. Giriş yapılmamışsa her zaman false.
     */
    public function can(string $capability): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        return $this->roles->roleCan($user->role, $capability);
    }

    /**
     * İçeriği düzenleyebilir mi? Sahiplik denetimini birleştirir.
     */
    public function canEdit(int $authorId): bool
    {
        if ($this->can('content.edit_others')) {
            return true;
        }

        return $this->can('content.edit_own') && $authorId === $this->id() && $authorId > 0;
    }

    public function roles(): Roles
    {
        return $this->roles;
    }

    /**
     * Giriş denemesi.
     *
     * @return array{ok: bool, error: string, wait: int}
     */
    public function attempt(
        string $identifier,
        string $password,
        bool $remember,
        string $ip,
        string $userAgent = '',
    ): array {
        $wait = $this->throttleSeconds($ip, $identifier);

        if ($wait > 0) {
            return [
                'ok'    => false,
                'error' => Str::format('Çok fazla başarısız deneme. %d saniye sonra tekrar deneyin.', $wait),
                'wait'  => $wait,
            ];
        }

        $user = $this->users->findByIdentifier($identifier);
        $ok   = $user !== null && $user->isActive() && $this->users->verifyPassword($user, $password);

        $this->recordAttempt($ip, $identifier, $ok);

        if (!$ok || $user === null) {
            $this->events->emit('auth.login_failed', $identifier, $ip);

            return ['ok' => false, 'error' => 'Kullanıcı adı veya şifre hatalı.', 'wait' => 0];
        }

        $this->login($user, $remember, $ip, $userAgent);

        return ['ok' => true, 'error' => '', 'wait' => 0];
    }

    /**
     * Kullanıcıyı oturuma yazar.
     */
    public function login(User $user, bool $remember = false, string $ip = '', string $userAgent = ''): void
    {
        $this->startSession();

        // Oturum sabitleme koruması: kimliği yenile.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $_SESSION[self::SESSION_USER] = $user->id;
        $_SESSION[self::SESSION_META] = [
            'ip'         => $ip,
            'user_agent' => substr($userAgent, 0, 120),
            'started_at' => time(),
        ];

        $this->csrf->rotate();

        $this->user     = $user;
        $this->resolved = true;

        $this->users->touchLogin($user->id);

        if ($remember) {
            $this->issueRememberToken($user, $userAgent);
        }

        $this->audit->record(
            action: 'auth.login',
            userId: $user->id,
            actor: $user->displayName,
            summary: 'Panele giriş yapıldı',
            ip: $ip,
        );

        $this->events->dispatch(new LoggedIn($user, $remember, $ip));
    }

    public function logout(): void
    {
        $user = $this->user();

        $this->clearRememberToken();

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }

        if ($user !== null) {
            $this->audit->record(
                action: 'auth.logout',
                userId: $user->id,
                actor: $user->displayName,
                summary: 'Panelden çıkış yapıldı',
            );
        }

        $this->user     = null;
        $this->resolved = true;
    }

    /* ---------------------------------------------------------------------
     * "Beni hatırla"
     * ------------------------------------------------------------------ */

    private function issueRememberToken(User $user, string $userAgent = ''): void
    {
        $selector  = Str::random(9);
        $validator = Str::random(32);
        $expires   = time() + (60 * 60 * 24 * 30);

        $this->db->insert('user_tokens', [
            'user_id'        => $user->id,
            'selector'       => $selector,
            'validator_hash' => hash('sha256', $validator),
            'user_agent'     => substr($userAgent, 0, 250),
            'expires_at'     => date('Y-m-d H:i:s', $expires),
            'created_at'     => Dates::stamp(),
        ]);

        if (!headers_sent()) {
            setcookie(self::COOKIE_NAME, $selector . ':' . $validator, [
                'expires'  => $expires,
                'path'     => $this->cookiePath,
                'httponly' => true,
                'secure'   => $this->secureCookies,
                'samesite' => 'Lax',
            ]);
        }
    }

    private function attemptRemember(): ?User
    {
        $cookie = (string) ($_COOKIE[self::COOKIE_NAME] ?? '');

        if ($cookie === '' || !str_contains($cookie, ':')) {
            return null;
        }

        [$selector, $validator] = explode(':', $cookie, 2);

        if (!$this->db->tableExists('user_tokens')) {
            return null;
        }

        $row = $this->db->builder('user_tokens')->where('selector', $selector)->first();

        if ($row === null) {
            $this->clearRememberToken();

            return null;
        }

        if (strtotime((string) $row['expires_at']) < time()) {
            $this->db->delete('user_tokens', ['id' => $row['id']]);
            $this->clearRememberToken();

            return null;
        }

        if (!hash_equals((string) $row['validator_hash'], hash('sha256', $validator))) {
            // Geçersiz doğrulayıcı: çerez çalınmış olabilir, kullanıcının tüm
            // hatırlama anahtarlarını iptal et.
            $this->db->delete('user_tokens', ['user_id' => (int) $row['user_id']]);
            $this->clearRememberToken();

            return null;
        }

        $user = $this->users->find((int) $row['user_id']);

        if ($user === null || !$user->isActive()) {
            return null;
        }

        // Anahtarı döndür (tek kullanımlık): eskisini sil, yenisini ver.
        $this->db->delete('user_tokens', ['id' => (int) $row['id']]);

        $this->startSession();
        $_SESSION[self::SESSION_USER] = $user->id;
        $this->issueRememberToken($user, (string) ($row['user_agent'] ?? ''));

        return $user;
    }

    private function clearRememberToken(): void
    {
        $cookie = (string) ($_COOKIE[self::COOKIE_NAME] ?? '');

        if ($cookie !== '' && str_contains($cookie, ':')) {
            [$selector] = explode(':', $cookie, 2);

            if ($this->db->tableExists('user_tokens')) {
                $this->db->delete('user_tokens', ['selector' => $selector]);
            }
        }

        if (!headers_sent()) {
            setcookie(self::COOKIE_NAME, '', [
                'expires'  => time() - 3600,
                'path'     => $this->cookiePath,
                'httponly' => true,
                'secure'   => $this->secureCookies,
                'samesite' => 'Lax',
            ]);
        }

        unset($_COOKIE[self::COOKIE_NAME]);
    }

    /* ---------------------------------------------------------------------
     * Hız sınırlama
     * ------------------------------------------------------------------ */

    /**
     * Kalan bekleme süresi (saniye). 0 ise deneme yapılabilir.
     */
    public function throttleSeconds(string $ip, string $identifier = ''): int
    {
        if (!$this->db->tableExists('login_attempts')) {
            return 0;
        }

        $window = Dates::stamp('-15 minutes');

        $failures = $this->db->builder('login_attempts')
            ->where('ip', $ip)
            ->where('successful', 0)
            ->whereRaw('attempted_at > :window', ['window' => $window])
            ->count();

        if ($failures < 5) {
            return 0;
        }

        // 5. denemeden sonra kademeli gecikme: 30s, 60s, 120s … en çok 15 dk.
        $delay = min(900, 30 * (2 ** ($failures - 5)));

        $last = $this->db->builder('login_attempts')
            ->where('ip', $ip)
            ->where('successful', 0)
            ->orderBy('id', 'desc')
            ->value('attempted_at');

        $lastTime = $last !== null ? (int) strtotime((string) $last) : 0;
        $remaining = ($lastTime + $delay) - time();

        return max(0, $remaining);
    }

    private function recordAttempt(string $ip, string $identifier, bool $successful): void
    {
        if (!$this->db->tableExists('login_attempts')) {
            return;
        }

        $this->db->insert('login_attempts', [
            'ip'           => $ip,
            'identifier'   => mb_substr($identifier, 0, 180),
            'successful'   => $successful ? 1 : 0,
            'attempted_at' => Dates::stamp(),
        ]);

        if ($successful) {
            // Başarılı girişte o IP'nin geçmiş hatalarını temizle.
            $this->db->builder('login_attempts')
                ->where('ip', $ip)
                ->where('successful', 0)
                ->delete();
        }
    }

    /**
     * Eski kayıtları temizler (planlı görev çağırır).
     */
    public function pruneAttempts(): int
    {
        if (!$this->db->tableExists('login_attempts')) {
            return 0;
        }

        return $this->db->statement(
            sprintf('DELETE FROM `%s` WHERE attempted_at < :cutoff', $this->db->t('login_attempts')),
            ['cutoff' => Dates::stamp('-7 days')]
        );
    }
}
