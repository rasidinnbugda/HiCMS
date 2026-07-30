<?php

declare(strict_types=1);

namespace HiCMS\Extension;

use HiCMS\Content\Field;
use HiCMS\Repository\OptionRepository;

/**
 * Eklenti ayarları için bildirimsel tanımlama.
 *
 * Eklenti ayarlarını tarif eder; formu basmak, doğrulamak, temizlemek ve
 * saklamak çekirdeğin işi olur:
 *
 *     $settings = $app->settings('hi-seo');
 *
 *     $settings->section('genel', 'Genel', [
 *         ['key' => 'title_pattern', 'type' => 'text', 'label' => 'Başlık kalıbı',
 *          'default' => '%s · %s'],
 *         ['key' => 'noindex_archives', 'type' => 'switch', 'label' => 'Arşivleri gizle',
 *          'default' => false],
 *     ]);
 *
 * Alan türleri `HiCMS\Content\Field` ile aynı kümeden gelir; temizleme ve
 * doğrulama zaten orada tanımlı olduğu için ikinci bir kural seti doğmuyor.
 *
 * DEPOLAMA: tek option satırı, `plugin.<slug>.settings` anahtarında bir dizi.
 * Eklenti başına tek satır okuma demek; ayar başına satır olsaydı her istekte
 * onlarca sorgu çıkardı.
 */
final class Settings
{
    /** @var array<string, array{label: string, description: string, fields: list<Field>}> */
    private array $sections = [];

    public function __construct(
        private readonly string $slug,
        private readonly OptionRepository $options,
    ) {
    }

    /**
     * Bölüm ve alanlarını tanımlar.
     *
     * @param list<array<string, mixed>> $fields
     */
    public function section(string $key, string $label, array $fields, string $description = ''): self
    {
        $built = [];

        foreach ($fields as $definition) {
            if (!is_array($definition) || ($definition['key'] ?? '') === '') {
                continue;
            }

            $built[] = Field::fromArray($definition);
        }

        $this->sections[$key] = [
            'label'       => $label,
            'description' => $description,
            'fields'      => $built,
        ];

        return $this;
    }

    /** @return array<string, array{label: string, description: string, fields: list<Field>}> */
    public function sections(): array
    {
        return $this->sections;
    }

    /** @return list<Field> */
    public function fields(?string $section = null): array
    {
        if ($section !== null) {
            return $this->sections[$section]['fields'] ?? [];
        }

        $all = [];

        foreach ($this->sections as $definition) {
            foreach ($definition['fields'] as $field) {
                $all[] = $field;
            }
        }

        return $all;
    }

    private function storeKey(): string
    {
        return 'plugin.' . $this->slug . '.settings';
    }

    /**
     * Kayıtlı değerler, tanımdaki varsayılanlarla birleştirilmiş hâlde.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $stored = (array) $this->options->get($this->storeKey(), []);
        $values = [];

        foreach ($this->fields() as $field) {
            $values[$field->key] = array_key_exists($field->key, $stored)
                ? $stored[$field->key]
                : $field->default;
        }

        return $values;
    }

    public function get(string $key, mixed $fallback = null): mixed
    {
        $values = $this->all();

        return array_key_exists($key, $values) ? $values[$key] : $fallback;
    }

    /**
     * Gelen girdiyi temizleyip saklar.
     *
     * EKSİK ANAHTARIN ANLAMI — bu sözleşmenin en kritik noktası:
     *
     *   • `$partial = false` (form gönderimi): gönderilen bölümün TÜM alanları
     *     yazılır. Eksik alan "bu alanın kapalı hâli" sayılır — çünkü işaretsiz
     *     bir onay kutusu POST'a HİÇ girmez. Bu yüzden bölüm sınırı önemli:
     *     yalnızca formda gerçekten bulunan alanlar ele alınır.
     *
     *   • `$partial = true` (API, göç, programatik yazma): eksik anahtar
     *     "değiştirme" demektir; mevcut değer korunur.
     *
     * 0.2.0 tasarımında bu ayrım yoktu ve `save()` kısmi girdiyle çağrıldığında
     * gönderilmeyen her ayarı sessizce varsayılana düşürüyordu: bir bölümü
     * kaydetmek diğer bölümlerin ayarlarını siliyordu.
     *
     * @param array<string, mixed> $input
     * @param string|null $section Yalnızca bu bölümün alanları ele alınır.
     * @return array<string, mixed> Saklanan tam değer kümesi
     */
    public function save(array $input, ?string $section = null, bool $partial = false): array
    {
        $stored = (array) $this->options->get($this->storeKey(), []);
        $scope  = $section !== null ? $this->fields($section) : $this->fields();

        foreach ($scope as $field) {
            $present = array_key_exists($field->key, $input);

            if (!$present && $partial) {
                continue; // kısmi yazma: dokunma
            }

            if (!$present && $field->type === 'switch') {
                // İşaretsiz onay kutusu POST'a hiç girmez: kapalı demektir.
                $stored[$field->key] = false;
                continue;
            }

            if (!$present) {
                /*
                 * Form gönderiminde bulunmayan metin alanı: boş gönderilmiş
                 * sayılır. Varsayılana DÖNDÜRÜLMEZ — kullanıcı bir alanı
                 * bilinçli olarak boşaltabilir ve varsayılanın geri gelmesi
                 * "sildim ama duruyor" şaşkınlığı yaratır.
                 */
                $stored[$field->key] = $field->sanitize(null);
                continue;
            }

            $stored[$field->key] = $field->sanitize($input[$field->key]);
        }

        $this->options->set($this->storeKey(), $stored);

        return $this->all();
    }

    /** Ayarları tamamen siler (eklenti kaldırılırken). */
    public function forget(): void
    {
        $this->options->delete($this->storeKey());
    }
}
