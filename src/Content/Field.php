<?php

declare(strict_types=1);

namespace HiCMS\Content;

use HiCMS\Support\Html;
use HiCMS\Support\Str;

/**
 * Özel alan tanımı.
 *
 * Alanlar hem panel formunu hem doğrulamayı besler: panel `type` değerine göre
 * girdiyi kendisi üretir, `sanitize()` kaydetmeden önce değeri temizler.
 * Böylece yeni bir alan eklemek için panelde arayüz kodu yazmak gerekmez.
 */
final class Field
{
    public const TYPES = [
        'text', 'textarea', 'richtext', 'lines', 'code', 'number', 'date', 'datetime',
        'select', 'switch', 'color', 'url', 'email', 'media', 'media-list', 'entry', 'repeater',
    ];

    /**
     * @param array<string, string> $options select için
     * @param list<Field> $subFields repeater için
     */
    public function __construct(
        public readonly string $key,
        public readonly string $type = 'text',
        public readonly string $label = '',
        public readonly string $help = '',
        public readonly bool $required = false,
        public readonly mixed $default = '',
        public readonly array $options = [],
        public readonly array $subFields = [],
        public readonly int $rows = 3,
        public readonly string $placeholder = '',
        public readonly string $group = '',
    ) {
    }

    /**
     * @param array<string, mixed> $definition
     */
    public static function fromArray(array $definition): self
    {
        $type = (string) ($definition['type'] ?? 'text');

        if (!in_array($type, self::TYPES, true)) {
            $type = 'text';
        }

        $subFields = [];

        foreach ((array) ($definition['fields'] ?? []) as $sub) {
            if (is_array($sub)) {
                $subFields[] = self::fromArray($sub);
            }
        }

        $key = Str::slug((string) ($definition['key'] ?? ''), '_');

        return new self(
            key: $key !== '' ? $key : 'alan',
            type: $type,
            label: (string) ($definition['label'] ?? ucfirst($key)),
            help: (string) ($definition['help'] ?? ''),
            required: (bool) ($definition['required'] ?? false),
            default: $definition['default'] ?? ($type === 'switch' ? false : ''),
            options: array_map('strval', (array) ($definition['options'] ?? [])),
            subFields: $subFields,
            rows: (int) ($definition['rows'] ?? 3),
            placeholder: (string) ($definition['placeholder'] ?? ''),
            group: (string) ($definition['group'] ?? ''),
        );
    }

    /**
     * Gelen değeri alan türüne göre temizler.
     */
    public function sanitize(mixed $value): mixed
    {
        return match ($this->type) {
            'number'     => is_numeric($value) ? (float) $value + 0 : 0,
            'switch'     => in_array($value, ['1', 'on', 'true', true, 1], true),
            'richtext'   => Html::clean(is_string($value) ? $value : ''),
            'code'       => is_string($value) ? $value : '',
            'textarea'   => is_string($value) ? trim($value) : '',
            'lines'      => $this->sanitizeLines($value),
            'url'        => is_string($value) && filter_var(trim($value), FILTER_VALIDATE_URL) !== false
                                ? trim($value) : '',
            'email'      => is_string($value) && filter_var(trim($value), FILTER_VALIDATE_EMAIL) !== false
                                ? trim($value) : '',
            'color'      => is_string($value) && preg_match('/^#[0-9a-f]{3,8}$/i', trim($value)) === 1
                                ? strtolower(trim($value)) : '',
            'date'       => $this->sanitizeDate($value, 'Y-m-d'),
            'datetime'   => $this->sanitizeDate($value, 'Y-m-d H:i:s'),
            'select'     => is_scalar($value) && isset($this->options[(string) $value])
                                ? (string) $value : (array_key_first($this->options) ?? ''),
            'media',
            'entry'      => max(0, (int) (is_scalar($value) ? $value : 0)),
            'media-list' => array_values(array_filter(array_map(
                static fn(mixed $id): int => max(0, (int) (is_scalar($id) ? $id : 0)),
                is_array($value) ? $value : []
            ))),
            'repeater'   => $this->sanitizeRepeater($value),
            default      => is_scalar($value) ? trim((string) $value) : '',
        };
    }

    public function isEmpty(mixed $value): bool
    {
        if (is_array($value)) {
            return $value === [];
        }

        return $value === null || $value === '' || $value === 0 || $value === false;
    }

    /** @return list<string> */
    private function sanitizeLines(mixed $value): array
    {
        $lines = is_array($value)
            ? array_map('strval', array_filter($value, 'is_scalar'))
            : (preg_split('/\r\n|\r|\n/', (string) (is_scalar($value) ? $value : '')) ?: []);

        return array_values(array_filter(array_map('trim', $lines), static fn(string $l): bool => $l !== ''));
    }

    private function sanitizeDate(mixed $value, string $format): string
    {
        if (!is_string($value) || trim($value) === '') {
            return '';
        }

        $time = strtotime(str_replace('T', ' ', trim($value)));

        return $time !== false ? date($format, $time) : '';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sanitizeRepeater(mixed $value): array
    {
        if (!is_array($value) || $this->subFields === []) {
            return [];
        }

        $rows = [];

        foreach ($value as $row) {
            if (!is_array($row)) {
                continue;
            }

            $clean = [];
            $empty = true;

            foreach ($this->subFields as $field) {
                $fieldValue        = $field->sanitize($row[$field->key] ?? null);
                $clean[$field->key] = $fieldValue;

                if (!$field->isEmpty($fieldValue)) {
                    $empty = false;
                }
            }

            if (!$empty) {
                $rows[] = $clean;
            }
        }

        return $rows;
    }
}
