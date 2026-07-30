<?php

declare(strict_types=1);

namespace HiCMS\Auth;

/**
 * Roller ve izinler.
 *
 * İzinler kaba değil ince tanelidir: "content.publish" ile "content.edit_others"
 * ayrı yetkilerdir. Böylece editöre yayınlama verip eklenti kurmayı
 * kapatabilirsiniz.
 *
 * Çekirdek dört rol tanımlar. Özel rol oluşturma HiRoles eklentisinin işidir;
 * eklenti `define()` ile yeni rol ekler, çekirdek kodu değişmez.
 */
final class Roles
{
    /** Tüm izinlerin listesi — panelde rol düzenleme ekranı bunu kullanır. */
    public const CAPABILITIES = [
        'content.read'          => 'İçerikleri görüntüle',
        'content.create'        => 'İçerik oluştur',
        'content.edit_own'      => 'Kendi içeriğini düzenle',
        'content.edit_others'   => 'Başkalarının içeriğini düzenle',
        'content.publish'       => 'İçerik yayınla',
        'content.delete'        => 'İçerik sil',
        'terms.manage'          => 'Kategori ve etiketleri yönet',
        'media.upload'          => 'Dosya yükle',
        'media.delete_others'   => 'Başkalarının dosyalarını sil',
        'comments.moderate'     => 'Yorumları denetle',
        'users.manage'          => 'Kullanıcıları yönet',
        'settings.manage'       => 'Site ayarlarını değiştir',
        'appearance.manage'     => 'Tema, menü ve bileşenleri yönet',
        'plugins.manage'        => 'Eklentileri yönet',
        'system.update'         => 'Sistemi güncelle',
        'system.backup'         => 'Yedek al ve geri yükle',
        'system.logs'           => 'Denetim günlüğünü görüntüle',
    ];

    /** @var array<string, array{label: string, capabilities: list<string>}> */
    private array $roles = [];

    public function __construct()
    {
        $this->registerDefaults();
    }

    private function registerDefaults(): void
    {
        $this->define('admin', 'Yönetici', array_keys(self::CAPABILITIES));

        $this->define('editor', 'Editör', [
            'content.read', 'content.create', 'content.edit_own', 'content.edit_others',
            'content.publish', 'content.delete', 'terms.manage',
            'media.upload', 'media.delete_others', 'comments.moderate',
            'appearance.manage',
        ]);

        $this->define('author', 'Yazar', [
            'content.read', 'content.create', 'content.edit_own', 'content.publish',
            'media.upload',
        ]);

        $this->define('contributor', 'Katkıcı', [
            'content.read', 'content.create', 'content.edit_own',
        ]);

        $this->define('subscriber', 'Abone', [
            'content.read',
        ]);
    }

    /**
     * Rol tanımlar veya var olanı değiştirir.
     *
     * @param list<string> $capabilities
     */
    public function define(string $role, string $label, array $capabilities): void
    {
        $valid = array_values(array_intersect($capabilities, array_keys(self::CAPABILITIES)));

        $this->roles[$role] = ['label' => $label, 'capabilities' => $valid];
    }

    public function exists(string $role): bool
    {
        return isset($this->roles[$role]);
    }

    public function label(string $role): string
    {
        return $this->roles[$role]['label'] ?? $role;
    }

    /**
     * @return array<string, string> rol → etiket
     */
    public function options(): array
    {
        $options = [];

        foreach ($this->roles as $name => $role) {
            $options[$name] = $role['label'];
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    public function capabilitiesOf(string $role): array
    {
        return $this->roles[$role]['capabilities'] ?? [];
    }

    public function roleCan(string $role, string $capability): bool
    {
        return in_array($capability, $this->capabilitiesOf($role), true);
    }

    /**
     * Panelde rol karşılaştırma tablosu için.
     *
     * @return array<string, array{label: string, capabilities: list<string>}>
     */
    public function all(): array
    {
        return $this->roles;
    }

    /**
     * Rolleri yetki gücüne göre sıralar (en yetkili önce).
     *
     * @return list<string>
     */
    public function ranked(): array
    {
        $names = array_keys($this->roles);

        usort($names, fn(string $a, string $b): int
            => count($this->capabilitiesOf($b)) <=> count($this->capabilitiesOf($a)));

        return $names;
    }

    /**
     * Ayarlardan gelen özel rol tanımlarını uygular (HiRoles eklentisi yazar).
     *
     * @param array<string, array{label?: string, capabilities?: list<string>}> $custom
     */
    public function applyCustom(array $custom): void
    {
        foreach ($custom as $name => $definition) {
            if (!is_string($name) || $name === '') {
                continue;
            }

            $this->define(
                $name,
                (string) ($definition['label'] ?? ucfirst($name)),
                array_map('strval', (array) ($definition['capabilities'] ?? []))
            );
        }
    }
}
