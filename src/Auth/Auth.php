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
     * Oturum dosyasının kilidini bırakır.
     *
     * PHP'nin dosya tabanlı oturum deposu `session_start()` ile ÖZEL bir kilit
     * alır ve isteğin sonuna kadar tutar. Aynı kullanıcıdan gelen ikinci istek
     * bu kilidi bekler — yani eşzamanlı istekler sıraya girer.
     *
     * Bu, panelin "anında" hissetmesinin önündeki en büyük engeldi: otomatik
     * kaydetme, kısmi güncelleme ve anlık arama hep aynı oturumdan paralel
     * istek atar; kilit bunları tek tek çalıştırır. Üstüne yanıt gönderildikten
     * sonra çalışan planlayıcı da kilidi elinde tutarak veritabanı işi yapıyor
     * ve kullanıcının sıradaki isteğini bekletiyordu.
     *
     * Çağrıldıktan sonra `$_SESSION`'a yazmak SESSİZCE kaybolur. Bu yüzden
     * yalnızca isteğin oturuma artık yazmayacağı kesin olduğu noktalarda
     * çağrılır: yanıt gövdesi tamamlandıktan sonra.
     */
    public function closeSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
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
    /**
     * Giriş denemesi için beklenmesi gereken saniye.
     *
     * BİRİNCİL ÖLÇÜT KULLANICI ADI, IP DEĞİL.
     *
     * 0.2.0 yalnızca IP'ye bakıyordu ve `$identifier` parametresini alıp HİÇ
     * kullanmıyordu. İki ayrı sorun:
     *
     *   1. Ters vekil arkasında (Cloudflare, nginx, paylaşımlı barındırma yük
     *      dengeleyicisi) tüm ziyaretçiler aynı adresten görünür. Bir kişinin
     *      beş hatalı denemesi SİTE ÇAPINDA girişi kilitliyordu — kimse
     *      giremiyor ve yöneticinin bunu anlaması çok zor.
     *   2. Vekil başlığı taklit edilebildiği için (bkz. Request::ip()) saldırgan
     *      her istekte farklı bir "IP" göstererek sınırlamayı tamamen
     *      atlıyordu.
     *
     * Kullanıcı adına dayanmak ikisini birden çözüyor: vekil arkasında da
     * doğru çalışıyor ve taklit edilebilir bir girdiye bağlı değil.
     *
     * IP ölçütü KALDIRILMADI ama eşiği çok daha yüksek: farklı hesaplara
     * yayılan saldırıyı (spray) yakalamak için var. Paylaşımlı bir adreste
     * yirmi hatalı deneme olağan değil, ama bir kişinin beş hatası da tüm
     * siteyi kilitlemiyor.
     */
    public function throttleSeconds(string $ip, string $identifier = ''): int
    {
        if (!$this->db->tableExists('login_attempts')) {
            return 0;
        }

        $window = Dates::stamp('-15 minutes');

        // 1. Kullanıcı adı ölçütü: sıkı, beşinci denemeden sonra gecikme.
        $byIdentifier = 0;

        if (trim($identifier) !== '') {
            $byIdentifier = $this->delayFor(
                ['identifier' => mb_strtolower(trim($identifier))],
                $window,
                5
            );
        }

        // 2. IP ölçütü: gevşek, yayılan saldırı için. Paylaşımlı adresi kilitlemez.
        $byIp = $ip !== '' && $ip !== '0.0.0.0'
            ? $this->delayFor(['ip' => $ip], $window, 20)
            : 0;

        return max($byIdentifier, $byIp);
    }

    /**
     * Verilen ölçüt için kademeli gecikme.
     *
     * @param array<string, string> $match sütun → değer
     */
    private function delayFor(array $match, string $window, int $threshold): int
    {
        $query = $this->db->builder('login_attempts')
            ->where('successful', 0)
            ->whereRaw('attempted_at > :window', ['window' => $window]);

        foreach ($match as $column => $value) {
            $query->where($column, $value);
        }

        $failures = $query->count();

        if ($failures < $threshold) {
            return 0;
        }

        // Eşikten sonra kademeli gecikme: 30s, 60s, 120s … en çok 15 dk.
        $delay = min(900, 30 * (2 ** ($failures - $threshold)));

        $lastQuery = $this->db->builder('login_attempts')
            ->where('successful', 0)
            ->orderBy('id', 'desc');

        foreach ($match as $column => $value) {
            $lastQuery->where($column, $value);
        }

        $last     = $lastQuery->value('attempted_at');
        $lastTime = $last !== null ? (int) strtotime((string) $last) : 0;

        return max(0, ($lastTime + $delay) - time());
    }

    private function recordAttempt(string $ip, string $identifier, bool $successful): void
    {
        if (!$this->db->tableExists('login_attempts')) {
            return;
        }

        /*
         * Kullanıcı adı KÜÇÜK HARFE çevrilerek saklanır.
         *
         * Sınırlama kullanıcı adına dayandığı için normalleştirme şart: aksi
         * hâlde `admin`, `Admin`, `ADMIN` ayrı anahtarlar olur ve saldırgan
         * yalnızca harf büyüklüğünü değiştirerek sınırı sonsuza kadar atlar.
         */
        $key = mb_strtolower(trim($identifier), 'UTF-8');

        $this->db->insert('login_attempts', [
            'ip'           => $ip,
            'identifier'   => mb_substr($key, 0, 180),
            'successful'   => $successful ? 1 : 0,
            'attempted_at' => Dates::stamp(),
        ]);

        if ($successful) {
            /*
             * Başarılı girişte HEM IP HEM kullanıcı adı geçmişi temizlenir.
             * 0.2.0 yalnızca IP'yi temizliyordu; sınırlama artık kullanıcı adına
             * dayandığı için o kayıtlar kalsaydı doğru şifreyi girmiş kullanıcı
             * bir sonraki girişinde hâlâ bekletilirdi.
             */
            $this->db->builder('login_attempts')
                ->where('ip', $ip)
                ->where('successful', 0)
                ->delete();

            if ($key !== '') {
                $this->db->builder('login_attempts')
                    ->where('identifier', mb_substr($key, 0, 180))
                    ->where('successful', 0)
                    ->delete();
            }
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
