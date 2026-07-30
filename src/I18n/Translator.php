<?php

declare(strict_types=1);

namespace HiCMS\I18n;

use HiCMS\Support\Str;

/**
 * Çeviri katmanı.
 *
 * Kaynak metinler Türkçedir; dil dosyaları Türkçeden hedef dile eşleme yapar.
 * Bu sayede tr_TR kurulumlarında hiçbir ek maliyet oluşmaz (eşleme boştur) ve
 * yeni bir dil eklemek tek dosya bırakmaktan ibarettir.
 */
final class Translator
{
    /** @var array<string, string> */
    private array $strings = [];

    /** @var list<string> Yüklenmiş dizinler — tekrar yüklemeyi önler */
    private array $loaded = [];

    public function __construct(
        private string $locale = 'tr_TR',
        private readonly string $coreDir = '',
    ) {
        if ($this->coreDir !== '') {
            $this->loadDirectory($this->coreDir);
        }
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        if ($locale === $this->locale) {
            return;
        }

        $this->locale  = $locale;
        $this->strings = [];
        $directories   = $this->loaded;
        $this->loaded  = [];

        foreach ($directories as $directory) {
            $this->loadDirectory($directory);
        }
    }

    /**
     * Bir dizinden `{locale}.php` dosyasını yükler.
     * Tema ve eklentiler kendi çevirilerini böyle ekler.
     */
    public function loadDirectory(string $directory): bool
    {
        $directory = rtrim(str_replace('\\', '/', $directory), '/');

        if (in_array($directory, $this->loaded, true)) {
            return true;
        }

        $this->loaded[] = $directory;

        $file = $directory . '/' . basename($this->locale) . '.php';

        if (!is_readable($file)) {
            return false;
        }

        $strings = require $file;

        if (!is_array($strings)) {
            return false;
        }

        $this->strings = array_merge($this->strings, $strings);

        return true;
    }

    public function get(string $text): string
    {
        return $this->strings[$text] ?? $text;
    }

    /**
     * Yer tutuculu çeviri. Çeviri metnindeki tek `%` işaretleri kaçırıldığı için
     * "%100 hazır" gibi metinler bozulmaz.
     */
    public function format(string $text, mixed ...$args): string
    {
        return Str::format($this->get($text), ...$args);
    }

    public function plural(string $single, string $plural, int $count): string
    {
        return $this->get($count === 1 ? $single : $plural);
    }

    /**
     * Kullanılabilir dilleri listeler.
     *
     * @return array<string, string> kod → görünen ad
     */
    public function available(): array
    {
        $names = [
            'tr_TR' => 'Türkçe',
            'en_US' => 'English (US)',
            'en_GB' => 'English (UK)',
            'de_DE' => 'Deutsch',
            'fr_FR' => 'Français',
            'ar_SA' => 'العربية',
        ];

        $found = [];

        foreach (glob($this->coreDir . '/*.php') ?: [] as $file) {
            $code = basename($file, '.php');
            $found[$code] = $names[$code] ?? $code;
        }

        return $found !== [] ? $found : ['tr_TR' => 'Türkçe'];
    }
}
