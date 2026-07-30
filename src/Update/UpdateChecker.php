<?php

declare(strict_types=1);

namespace HiCMS\Update;

use HiCMS\Repository\OptionRepository;
use HiCMS\Support\Remote;

/**
 * GitHub Releases üzerinden sürüm kontrolü.
 *
 * Çekirdek, tema ve eklentiler aynı mekanizmayı kullanır: künyedeki
 * `repository` alanı (`sahip/depo`) sorgulanır ve en son yayın okunur. Sonuçlar
 * 6 saat önbelleklenir — panel her açılışta GitHub'a istek atmaz.
 *
 * Yayın varlıkları arasında `.zip` uzantılı bir dosya varsa o kullanılır
 * (dağıtım paketi); yoksa GitHub'ın ürettiği kaynak arşivine düşülür.
 */
final class UpdateChecker
{
    private const CACHE_KEY = 'update.cache';
    private const TTL       = 21600; // 6 saat

    /**
     * İstek içi bellek: depo → sonuç. Aynı istekte tekrarlanan denetimler
     * veritabanına ve GitHub'a ikinci kez gitmez.
     *
     * @var array<string, array{ok: bool, version: string, zip: string, notes: string,
     *                          published: string, url: string, error: string}>
     */
    private array $memo = [];

    public function __construct(private readonly OptionRepository $options)
    {
    }

    /**
     * Deponun en son yayınını döndürür.
     *
     * @return array{ok: bool, version: string, zip: string, notes: string,
     *               published: string, url: string, error: string}
     */
    public function latest(string $repository, bool $force = false): array
    {
        $empty = [
            'ok' => false, 'version' => '', 'zip' => '', 'notes' => '',
            'published' => '', 'url' => '', 'error' => '',
        ];

        if ($repository === '') {
            return ['error' => 'Depo bildirilmemiş.'] + $empty;
        }

        /*
         * İstek başına bellekleme. Panel gösterge sayfasında sürüm denetimi İKİ
         * kez çalışıyordu: menü kurulurken (bootstrap.php) ve gösterge
         * kutusunda (index.php). Altı saatlik seçenek önbelleği vardı ama o
         * önbellek her çağrıda veritabanından okunuyordu; önbellek soğukken de
         * aynı istek içinde iki HTTP çağrısı yapılıyordu.
         */
        if (!$force && array_key_exists($repository, $this->memo)) {
            return $this->memo[$repository];
        }

        $cache = $this->readCache();

        if (!$force && isset($cache[$repository]) && ($cache[$repository]['checked'] ?? 0) > time() - self::TTL) {
            return $this->memo[$repository] = $cache[$repository]['result'];
        }

        $response = Remote::getJson(
            'https://api.github.com/repos/' . $repository . '/releases/latest',
            ['X-GitHub-Api-Version' => '2022-11-28']
        );

        if (!$response['ok'] || $response['data'] === null) {
            $result = ['error' => $response['error'] !== '' ? $response['error'] : 'Sürüm bilgisi alınamadı.'] + $empty;

            // Hatalı sonucu da kısa süre önbellekle: her sayfa açılışında denemeyelim.
            $this->writeCache($repository, $result, time() - self::TTL + 900);

            return $this->memo[$repository] = $result;
        }

        $data    = $response['data'];
        $version = ltrim((string) ($data['tag_name'] ?? ''), 'vV');
        $zip     = '';

        foreach ((array) ($data['assets'] ?? []) as $asset) {
            $name = (string) ($asset['name'] ?? '');
            $url  = (string) ($asset['browser_download_url'] ?? '');

            if ($url !== '' && str_ends_with(strtolower($name), '.zip')) {
                $zip = $url;
                break;
            }
        }

        if ($zip === '') {
            $zip = (string) ($data['zipball_url'] ?? '');
        }

        $result = [
            'ok'        => $version !== '',
            'version'   => $version,
            'zip'       => $zip,
            'notes'     => (string) ($data['body'] ?? ''),
            'published' => (string) ($data['published_at'] ?? ''),
            'url'       => (string) ($data['html_url'] ?? ''),
            'error'     => $version === '' ? 'Yayın etiketi okunamadı.' : '',
        ];

        $this->writeCache($repository, $result);

        return $this->memo[$repository] = $result;
    }

    /**
     * Güncelleme var mı?
     *
     * @return array{available: bool, version: string, zip: string, notes: string,
     *               url: string, error: string}
     */
    public function check(string $repository, string $currentVersion, bool $force = false): array
    {
        $latest = $this->latest($repository, $force);

        if (!$latest['ok']) {
            return [
                'available' => false, 'version' => '', 'zip' => '',
                'notes' => '', 'url' => '', 'error' => $latest['error'],
            ];
        }

        return [
            'available' => version_compare($latest['version'], $currentVersion, '>'),
            'version'   => $latest['version'],
            'zip'       => $latest['zip'],
            'notes'     => $latest['notes'],
            'url'       => $latest['url'],
            'error'     => '',
        ];
    }

    /**
     * Birden çok paket için tek turda kontrol.
     *
     * @param array<string, string> $packages slug → "sahip/depo@sürüm"
     * @return array<string, array<string, mixed>>
     */
    public function checkMany(array $packages, bool $force = false): array
    {
        $results = [];

        foreach ($packages as $slug => $spec) {
            [$repository, $version] = array_pad(explode('@', $spec, 2), 2, '0.0.0');

            $results[$slug] = $this->check($repository, $version, $force);
        }

        return $results;
    }

    public function clearCache(): void
    {
        $this->options->delete(self::CACHE_KEY);

        // İstek içi bellek de temizlenmeli, yoksa aynı istekte yapılan
        // zorlamasız bir denetim silinen sonucu geri verir.
        $this->memo = [];
    }

    public function lastCheckedAt(string $repository): int
    {
        return (int) ($this->readCache()[$repository]['checked'] ?? 0);
    }

    /**
     * @return array<string, array{checked: int, result: array<string, mixed>}>
     */
    private function readCache(): array
    {
        $cache = $this->options->get(self::CACHE_KEY, []);

        return is_array($cache) ? $cache : [];
    }

    /**
     * @param array<string, mixed> $result
     */
    private function writeCache(string $repository, array $result, ?int $checkedAt = null): void
    {
        $cache = $this->readCache();

        $cache[$repository] = ['checked' => $checkedAt ?? time(), 'result' => $result];

        $this->options->set(self::CACHE_KEY, $cache, false);
    }
}
