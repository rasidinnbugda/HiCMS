<?php

declare(strict_types=1);

namespace HiCMS\Repository;

use HiCMS\Database\Connection;
use HiCMS\Model\User;
use HiCMS\Support\Dates;
use HiCMS\Support\Str;

/**
 * Kullanıcı deposu.
 *
 * Şifreler yalnızca burada hash'lenir; çağıran kod açık şifreyi asla saklamaz.
 */
final class UserRepository
{
    /** @var array<int, User|null> */
    private array $cache = [];

    public function __construct(private readonly Connection $db)
    {
    }

    public function find(int $id): ?User
    {
        if ($id <= 0) {
            return null;
        }

        if (array_key_exists($id, $this->cache)) {
            return $this->cache[$id];
        }

        $row = $this->db->builder('users')->where('id', $id)->first();

        return $this->cache[$id] = $row !== null ? User::fromRow($row) : null;
    }

    /**
     * @param list<int> $ids
     * @return array<int, User>
     */
    public function findMany(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if ($ids === []) {
            return [];
        }

        $users = [];

        foreach ($this->db->builder('users')->whereIn('id', $ids)->get() as $row) {
            $user                   = User::fromRow($row);
            $users[$user->id]       = $user;
            $this->cache[$user->id] = $user;
        }

        return $users;
    }

    /** Kullanıcı adı veya e-posta ile arar (giriş için). */
    public function findByIdentifier(string $identifier): ?User
    {
        $identifier = trim($identifier);

        if ($identifier === '') {
            return null;
        }

        $column = str_contains($identifier, '@') ? 'email' : 'username';

        $row = $this->db->builder('users')->where($column, $identifier)->first();

        return $row !== null ? User::fromRow($row) : null;
    }

    public function findBySlug(string $slug): ?User
    {
        $row = $this->db->builder('users')->where('slug', $slug)->first();

        return $row !== null ? User::fromRow($row) : null;
    }

    /**
     * @param array{role?: string, search?: string, status?: string} $args
     * @return list<User>
     */
    public function all(array $args = []): array
    {
        $prefix = $this->db->prefix();

        $sql = sprintf(
            'SELECT u.*,
                    (SELECT COUNT(*) FROM `%scontent` c WHERE c.author_id = u.id) AS entry_count
             FROM `%susers` u',
            $prefix,
            $prefix
        );

        $conditions = [];
        $bindings   = [];

        if (($args['role'] ?? '') !== '') {
            $conditions[]     = 'u.role = :role';
            $bindings['role'] = $args['role'];
        }

        if (($args['status'] ?? '') !== '') {
            $conditions[]       = 'u.status = :status';
            $bindings['status'] = $args['status'];
        }

        if (($args['search'] ?? '') !== '') {
            $conditions[]       = '(u.display_name LIKE :search OR u.username LIKE :search OR u.email LIKE :search)';
            $bindings['search'] = '%' . $args['search'] . '%';
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY u.display_name ASC';

        $users = [];

        foreach ($this->db->select($sql, $bindings) as $row) {
            $user             = User::fromRow($row);
            $user->entryCount = (int) ($row['entry_count'] ?? 0);
            $users[]          = $user;
        }

        return $users;
    }

    /**
     * Kullanıcı oluşturur.
     *
     * @return array{ok: bool, id: int, error: string}
     */
    public function create(User $user, string $plainPassword): array
    {
        $user->username = strtolower(trim($user->username));
        $user->email    = strtolower(trim($user->email));

        if ($user->username === '' || $user->email === '') {
            return ['ok' => false, 'id' => 0, 'error' => 'Kullanıcı adı ve e-posta zorunludur.'];
        }

        if (filter_var($user->email, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'id' => 0, 'error' => 'E-posta adresi geçersiz.'];
        }

        if ($this->db->builder('users')->where('username', $user->username)->exists()) {
            return ['ok' => false, 'id' => 0, 'error' => 'Bu kullanıcı adı zaten kullanılıyor.'];
        }

        if ($this->db->builder('users')->where('email', $user->email)->exists()) {
            return ['ok' => false, 'id' => 0, 'error' => 'Bu e-posta adresi zaten kayıtlı.'];
        }

        if (strlen($plainPassword) < 10) {
            return ['ok' => false, 'id' => 0, 'error' => 'Şifre en az 10 karakter olmalıdır.'];
        }

        $user->passwordHash = self::hash($plainPassword);
        $user->displayName  = $user->displayName !== '' ? $user->displayName : $user->username;
        $user->slug         = $this->uniqueSlug($user->slug !== '' ? $user->slug : Str::slug($user->displayName));

        $row               = $user->toRow();
        $row['created_at'] = Dates::stamp();
        $row['updated_at'] = Dates::stamp();

        $id       = $this->db->insert('users', $row);
        $user->id = $id;

        $this->cache[$id] = $user;

        return ['ok' => true, 'id' => $id, 'error' => ''];
    }

    /**
     * @return array{ok: bool, error: string}
     */
    public function update(User $user, string $newPassword = ''): array
    {
        if ($user->id <= 0) {
            return ['ok' => false, 'error' => 'Geçersiz kullanıcı.'];
        }

        $user->email = strtolower(trim($user->email));

        if (filter_var($user->email, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'error' => 'E-posta adresi geçersiz.'];
        }

        $emailTaken = $this->db->builder('users')
            ->where('email', $user->email)
            ->where('id', '!=', $user->id)
            ->exists();

        if ($emailTaken) {
            return ['ok' => false, 'error' => 'Bu e-posta adresi başka bir kullanıcıya ait.'];
        }

        if ($newPassword !== '') {
            if (strlen($newPassword) < 10) {
                return ['ok' => false, 'error' => 'Şifre en az 10 karakter olmalıdır.'];
            }

            $user->passwordHash = self::hash($newPassword);
        }

        $user->slug = $this->uniqueSlug(
            $user->slug !== '' ? $user->slug : Str::slug($user->displayName),
            $user->id
        );

        $row               = $user->toRow();
        $row['updated_at'] = Dates::stamp();

        // Kullanıcı adı değiştirilemez.
        unset($row['username']);

        $this->db->update('users', $row, ['id' => $user->id]);
        $this->cache[$user->id] = $user;

        return ['ok' => true, 'error' => ''];
    }

    /**
     * Kullanıcıyı siler, içeriklerini devreder.
     */
    public function delete(int $id, int $reassignTo = 0): bool
    {
        if ($id <= 0) {
            return false;
        }

        if ($reassignTo > 0) {
            $this->db->builder('content')->where('author_id', $id)->update(['author_id' => $reassignTo]);
        }

        $this->db->delete('user_tokens', ['user_id' => $id]);
        $this->db->delete('users', ['id' => $id]);

        unset($this->cache[$id]);

        return true;
    }

    public function touchLogin(int $id): void
    {
        $this->db->update('users', ['last_login_at' => Dates::stamp()], ['id' => $id]);
        unset($this->cache[$id]);
    }

    public function count(?string $role = null): int
    {
        $query = $this->db->builder('users');

        if ($role !== null) {
            $query->where('role', $role);
        }

        return $query->count();
    }

    /** @return array<string, int> rol → sayı */
    public function countByRole(): array
    {
        $rows   = $this->db->builder('users')->select(['role'])->selectRaw('COUNT(*) AS total')->groupBy('role')->get();
        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row['role']] = (int) $row['total'];
        }

        return $counts;
    }

    /** Sistemde en az bir yönetici kaldığından emin olmak için. */
    public function isLastAdmin(int $userId): bool
    {
        $user = $this->find($userId);

        if ($user === null || $user->role !== 'admin') {
            return false;
        }

        return $this->db->builder('users')->where('role', 'admin')->count() <= 1;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public function verifyPassword(User $user, string $password): bool
    {
        if (!password_verify($password, $user->passwordHash)) {
            return false;
        }

        // Hash algoritması güncellendiyse sessizce yenile.
        if (password_needs_rehash($user->passwordHash, PASSWORD_DEFAULT)) {
            $user->passwordHash = self::hash($password);
            $this->db->update('users', ['password_hash' => $user->passwordHash], ['id' => $user->id]);
        }

        return true;
    }

    private function uniqueSlug(string $slug, int $ignoreId = 0): string
    {
        $slug = $slug !== '' ? $slug : 'kullanici';
        $base = $slug;
        $n    = 1;

        while (true) {
            $query = $this->db->builder('users')->where('slug', $slug);

            if ($ignoreId > 0) {
                $query->where('id', '!=', $ignoreId);
            }

            if (!$query->exists()) {
                return $slug;
            }

            $slug = $base . '-' . (++$n);
        }
    }
}
