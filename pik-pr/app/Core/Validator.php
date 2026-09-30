<?php

declare(strict_types=1);

namespace App\Core;

use App\Support\Decimal;
use DateTimeImmutable;

/**
 * Validator sederhana. Setiap aturan menyimpan pesan error pertama per field.
 */
final class Validator
{
    /** @var array<string, string> */
    private array $errors = [];

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private readonly array $data)
    {
    }

    public function value(string $field): string
    {
        $value = $this->data[$field] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    public function required(string $field, string $label): self
    {
        if ($this->value($field) === '') {
            $this->add($field, "{$label} wajib diisi.");
        }

        return $this;
    }

    public function maxLength(string $field, int $max, string $label): self
    {
        if (mb_strlen($this->value($field)) > $max) {
            $this->add($field, "{$label} maksimal {$max} karakter.");
        }

        return $this;
    }

    public function minLength(string $field, int $min, string $label): self
    {
        $value = $this->value($field);
        if ($value !== '' && mb_strlen($value) < $min) {
            $this->add($field, "{$label} minimal {$min} karakter.");
        }

        return $this;
    }

    public function email(string $field, string $label): self
    {
        $value = $this->value($field);
        if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->add($field, "{$label} harus berupa alamat email yang valid.");
        }

        return $this;
    }

    /**
     * @param list<string> $allowed
     */
    public function in(string $field, array $allowed, string $label): self
    {
        $value = $this->value($field);
        if ($value !== '' && !in_array($value, $allowed, true)) {
            $this->add($field, "{$label} tidak valid.");
        }

        return $this;
    }

    public function pattern(string $field, string $regex, string $message): self
    {
        $value = $this->value($field);
        if ($value !== '' && !preg_match($regex, $value)) {
            $this->add($field, $message);
        }

        return $this;
    }

    public function date(string $field, string $label): self
    {
        $value = $this->value($field);
        if ($value === '') {
            return $this;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            $this->add($field, "{$label} harus berupa tanggal yang valid.");
        }

        return $this;
    }

    public function decimal(string $field, string $label, string $min = '0', string $max = '999999999999.99', int $scale = 2): self
    {
        $raw = $this->value($field);
        if ($raw === '') {
            return $this;
        }
        $value = Decimal::parse($raw, $scale);
        if ($value === null) {
            $this->add($field, "{$label} harus berupa angka dengan maksimal {$scale} angka desimal.");
        } elseif (Decimal::compare($value, $min) < 0 || Decimal::compare($value, $max) > 0) {
            $this->add($field, "{$label} harus di antara {$min} dan {$max}.");
        }

        return $this;
    }

    public function add(string $field, string $message): self
    {
        $this->errors[$field] ??= $message;

        return $this;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function throwIfFailed(): void
    {
        if ($this->fails()) {
            throw new ValidationException($this->errors);
        }
    }
}
