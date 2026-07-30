<?php

declare(strict_types=1);

namespace HiCMS\Support;

/**
 * Dış istekler (güncelleme kontrolü ve paket indirme).
 *
 * curl varsa onu, yoksa allow_url_fopen ile stream'i kullanır. Her iki yolda da
 * zaman aşımı ve yönlendirme sınırı uygulanır; yalnızca https adreslere izin
 * verilir.
 */
final class Remote
{
    private const USER_AGENT = 'HiCMS-Updater/1.0';

    /**
     * @param array<string, string> $headers
     * @return array{ok: bool, status: int, body: string, error: string}
     */
    public static function get(string $url, array $headers = [], int $timeout = 15): array
    {
        if (!str_starts_with($url, 'https://')) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'Yalnızca https adreslere istek yapılır.'];
        }

        $headers['User-Agent'] ??= self::USER_AGENT;
        $headers['Accept']     ??= 'application/vnd.github+json';

        if (function_exists('curl_init')) {
            return self::viaCurl($url, $headers, $timeout);
        }

        return self::viaStream($url, $headers, $timeout);
    }

    /**
     * JSON döndüren uç noktalar için.
     *
     * @param array<string, string> $headers
     * @return array{ok: bool, status: int, data: array<mixed>|null, error: string}
     */
    public static function getJson(string $url, array $headers = [], int $timeout = 15): array
    {
        $response = self::get($url, $headers, $timeout);

        if (!$response['ok']) {
            return ['ok' => false, 'status' => $response['status'], 'data' => null, 'error' => $response['error']];
        }

        $data = json_decode($response['body'], true);

        if (!is_array($data)) {
            return ['ok' => false, 'status' => $response['status'], 'data' => null, 'error' => 'Yanıt JSON olarak okunamadı.'];
        }

        return ['ok' => true, 'status' => $response['status'], 'data' => $data, 'error' => ''];
    }

    /**
     * Dosyayı indirir.
     *
     * @return array{ok: bool, error: string, bytes: int}
     */
    public static function download(string $url, string $destination, int $timeout = 120): array
    {
        if (!str_starts_with($url, 'https://')) {
            return ['ok' => false, 'error' => 'Yalnızca https adreslerden indirme yapılır.', 'bytes' => 0];
        }

        if (!Fs::ensureDir(dirname($destination))) {
            return ['ok' => false, 'error' => 'İndirme dizini oluşturulamadı.', 'bytes' => 0];
        }

        $handle = @fopen($destination, 'wb');

        if ($handle === false) {
            return ['ok' => false, 'error' => 'İndirme dosyası açılamadı.', 'bytes' => 0];
        }

        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_FILE           => $handle,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_USERAGENT      => self::USER_AGENT,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FAILONERROR    => true,
            ]);

            $ok    = curl_exec($curl) !== false;
            $error = curl_error($curl);
            curl_close($curl);
            fclose($handle);

            if (!$ok) {
                @unlink($destination);
                return ['ok' => false, 'error' => $error !== '' ? $error : 'İndirme başarısız.', 'bytes' => 0];
            }

            return ['ok' => true, 'error' => '', 'bytes' => (int) @filesize($destination)];
        }

        fclose($handle);

        $context = stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => $timeout, 'user_agent' => self::USER_AGENT, 'follow_location' => 1],
            'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            @unlink($destination);
            return ['ok' => false, 'error' => 'İndirme başarısız (stream).', 'bytes' => 0];
        }

        if (@file_put_contents($destination, $body) === false) {
            return ['ok' => false, 'error' => 'İndirilen dosya yazılamadı.', 'bytes' => 0];
        }

        return ['ok' => true, 'error' => '', 'bytes' => strlen($body)];
    }

    /**
     * @param array<string, string> $headers
     * @return array{ok: bool, status: int, body: string, error: string}
     */
    private static function viaCurl(string $url, array $headers, int $timeout): array
    {
        $curl = curl_init($url);

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => self::flatten($headers),
        ]);

        $body   = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error  = curl_error($curl);
        curl_close($curl);

        if ($body === false) {
            return ['ok' => false, 'status' => $status, 'body' => '', 'error' => $error !== '' ? $error : 'İstek başarısız.'];
        }

        return [
            'ok'     => $status >= 200 && $status < 300,
            'status' => $status,
            'body'   => (string) $body,
            'error'  => $status >= 400 ? "Sunucu {$status} döndürdü." : '',
        ];
    }

    /**
     * @param array<string, string> $headers
     * @return array{ok: bool, status: int, body: string, error: string}
     */
    private static function viaStream(string $url, array $headers, int $timeout): array
    {
        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'curl yok ve allow_url_fopen kapalı.'];
        }

        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => implode("\r\n", self::flatten($headers)),
                'timeout'       => $timeout,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $body   = @file_get_contents($url, false, $context);
        $status = 0;

        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        if ($body === false) {
            return ['ok' => false, 'status' => $status, 'body' => '', 'error' => 'İstek başarısız (stream).'];
        }

        return [
            'ok'     => $status >= 200 && $status < 300,
            'status' => $status,
            'body'   => $body,
            'error'  => $status >= 400 ? "Sunucu {$status} döndürdü." : '',
        ];
    }

    /**
     * @param array<string, string> $headers
     * @return list<string>
     */
    private static function flatten(array $headers): array
    {
        $lines = [];

        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        return $lines;
    }
}
