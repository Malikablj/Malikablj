<?php

declare(strict_types=1);

namespace App\Helpers;

use DateTimeImmutable;

/**
 * Validasi input server-side.
 *
 * Contoh:
 *   $v = Validator::make($_POST, [
 *       'name'   => 'required|string|max:190',
 *       'email'  => 'nullable|email|max:190',
 *       'status' => ['required', ['in', ['Active', 'Inactive']]],
 *       'qty'    => 'required|integer|min:1',
 *   ], ['name' => 'Nama']);
 *   if ($v->fails()) { $errors = $v->errors(); }
 *   $data = $v->validated();   // sudah di-trim, string kosong => null, angka di-cast
 *
 * Aturan: required, nullable, string, max:n, min:n, integer, numeric, email,
 * in, date, datetime, time, url, boolean, phone, exists:table,column,
 * unique:table,column[,ignoreId], after_or_equal:field
 */
final class Validator
{
    /** @var array<string,string> */
    private array $errors = [];
    /** @var array<string,mixed> */
    private array $validated = [];

    /**
     * @param array<string,mixed> $data
     * @param array<string,string|list<string|array{0:string,1:mixed}>> $rules
     * @param array<string,string> $labels
     */
    private function __construct(private array $data, private array $rules, private array $labels)
    {
        $this->run();
    }

    public static function make(array $data, array $rules, array $labels = []): self
    {
        return new self($data, $rules, $labels);
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return array<string,mixed> */
    public function validated(): array
    {
        return $this->validated;
    }

    public function addError(string $field, string $message): void
    {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = $message;
        }
    }

    private function label(string $field): string
    {
        return $this->labels[$field] ?? ucfirst(str_replace('_', ' ', $field));
    }

    /** @return list<array{0:string,1:mixed}> */
    private function parseRules(string|array $rules): array
    {
        $parsed = [];
        $list = is_string($rules) ? explode('|', $rules) : $rules;
        foreach ($list as $rule) {
            if (is_array($rule)) {
                $parsed[] = [(string) $rule[0], $rule[1] ?? null];
                continue;
            }
            $rule = trim((string) $rule);
            if ($rule === '') {
                continue;
            }
            $pos = strpos($rule, ':');
            if ($pos === false) {
                $parsed[] = [$rule, null];
            } else {
                $name = substr($rule, 0, $pos);
                $arg = substr($rule, $pos + 1);
                $parsed[] = [$name, $name === 'in' ? explode(',', $arg) : $arg];
            }
        }
        return $parsed;
    }

    private function run(): void
    {
        foreach ($this->rules as $field => $ruleSet) {
            $rules = $this->parseRules($ruleSet);
            $names = array_column($rules, 0);
            $value = $this->data[$field] ?? null;

            if (is_array($value)) {
                $this->addError($field, $this->label($field) . ' tidak valid.');
                continue;
            }
            if (is_string($value)) {
                $value = trim(str_replace("\0", '', $value));
                if ($value === '') {
                    $value = null;
                }
            }

            if ($value === null) {
                if (in_array('boolean', $names, true) && !in_array('required', $names, true)) {
                    $this->validated[$field] = 0;
                    continue;
                }
                if (in_array('required', $names, true)) {
                    $this->addError($field, $this->label($field) . ' wajib diisi.');
                    continue;
                }
                $this->validated[$field] = null;
                continue;
            }

            $isNumeric = in_array('integer', $names, true) || in_array('numeric', $names, true);
            $ok = true;
            foreach ($rules as [$rule, $arg]) {
                $result = $this->applyRule($field, $rule, $arg, $value, $isNumeric);
                if ($result === false) {
                    $ok = false;
                    break;
                }
                $value = $result['value'];
            }
            if ($ok) {
                $this->validated[$field] = $value;
            }
        }
    }

    /**
     * @return array{value:mixed}|false
     */
    private function applyRule(string $field, string $rule, mixed $arg, mixed $value, bool $isNumeric): array|false
    {
        $label = $this->label($field);
        switch ($rule) {
            case 'required':
            case 'nullable':
                return ['value' => $value];

            case 'string':
                if (!is_scalar($value)) {
                    return $this->fail($field, "{$label} tidak valid.");
                }
                return ['value' => (string) $value];

            case 'max':
                if ($isNumeric) {
                    return (float) $value > (float) $arg ? $this->fail($field, "{$label} maksimal {$arg}.") : ['value' => $value];
                }
                return mb_strlen((string) $value) > (int) $arg ? $this->fail($field, "{$label} maksimal {$arg} karakter.") : ['value' => $value];

            case 'min':
                if ($isNumeric) {
                    return (float) $value < (float) $arg ? $this->fail($field, "{$label} minimal {$arg}.") : ['value' => $value];
                }
                return mb_strlen((string) $value) < (int) $arg ? $this->fail($field, "{$label} minimal {$arg} karakter.") : ['value' => $value];

            case 'integer':
                $int = Number::parseInt((string) $value);
                if ($int === null) {
                    return $this->fail($field, "{$label} harus berupa bilangan bulat.");
                }
                if ($int > 2147483647 || $int < -2147483648) {
                    return $this->fail($field, "{$label} terlalu besar.");
                }
                return ['value' => $int];

            case 'numeric':
                $num = Number::parseDecimal((string) $value);
                if ($num === null) {
                    return $this->fail($field, "{$label} harus berupa angka.");
                }
                if (abs((float) $num) >= 1.0e16) {
                    return $this->fail($field, "{$label} terlalu besar.");
                }
                return ['value' => $num];

            case 'email':
                return filter_var($value, FILTER_VALIDATE_EMAIL) === false
                    ? $this->fail($field, "{$label} harus berupa alamat email yang valid.")
                    : ['value' => mb_strtolower((string) $value)];

            case 'in':
                $allowed = array_map('strval', (array) $arg);
                return in_array((string) $value, $allowed, true)
                    ? ['value' => (string) $value]
                    : $this->fail($field, "{$label} tidak valid.");

            case 'date':
                $date = self::parseDate((string) $value);
                return $date === null ? $this->fail($field, "{$label} harus berupa tanggal yang valid.") : ['value' => $date];

            case 'datetime':
                $dt = self::parseDateTime((string) $value);
                return $dt === null ? $this->fail($field, "{$label} harus berupa tanggal & jam yang valid.") : ['value' => $dt];

            case 'time':
                if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:([0-5]\d))?$/', (string) $value, $m)) {
                    return $this->fail($field, "{$label} harus berupa jam yang valid (HH:MM).");
                }
                return ['value' => sprintf('%s:%s:%s', $m[1], $m[2], $m[4] ?? '00')];

            case 'url':
                $url = (string) $value;
                $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
                if (filter_var($url, FILTER_VALIDATE_URL) === false || !in_array($scheme, ['http', 'https'], true)) {
                    return $this->fail($field, "{$label} harus berupa link http/https yang valid.");
                }
                return ['value' => $url];

            case 'boolean':
                $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                return $bool === null ? $this->fail($field, "{$label} tidak valid.") : ['value' => $bool ? 1 : 0];

            case 'phone':
                return preg_match('/^[0-9+()\-\s.]{5,40}$/', (string) $value)
                    ? ['value' => (string) $value]
                    : $this->fail($field, "{$label} hanya boleh berisi angka, spasi, +, -, ( ).");

            case 'exists':
                [$table, $column] = array_pad(explode(',', (string) $arg), 2, 'id');
                Database::assertIdentifier($table);
                Database::assertIdentifier($column);
                $found = Database::fetchValue("SELECT 1 FROM `{$table}` WHERE `{$column}` = :v LIMIT 1", ['v' => $value]);
                return $found ? ['value' => $value] : $this->fail($field, "{$label} tidak ditemukan.");

            case 'unique':
                $parts = explode(',', (string) $arg);
                $table = $parts[0];
                $column = $parts[1] ?? $field;
                $ignore = isset($parts[2]) && $parts[2] !== '' ? (int) $parts[2] : 0;
                Database::assertIdentifier($table);
                Database::assertIdentifier($column);
                $found = Database::fetchValue(
                    "SELECT 1 FROM `{$table}` WHERE `{$column}` = :v AND id <> :ignore LIMIT 1",
                    ['v' => $value, 'ignore' => $ignore]
                );
                return $found ? $this->fail($field, "{$label} sudah digunakan.") : ['value' => $value];

            case 'after_or_equal':
                $other = $this->validated[(string) $arg] ?? null;
                if ($other !== null && (string) $value < (string) $other) {
                    return $this->fail($field, "{$label} tidak boleh sebelum " . $this->label((string) $arg) . '.');
                }
                return ['value' => $value];
        }
        return ['value' => $value];
    }

    /** Selalu mengembalikan false (tipe `bool` agar tetap kompatibel dengan PHP 8.1). */
    private function fail(string $field, string $message): bool
    {
        $this->addError($field, $message);
        return false;
    }

    /** Terima format Y-m-d (input type=date). Kembalikan Y-m-d atau null. */
    public static function parseDate(string $value): ?string
    {
        $value = trim($value);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return null;
        }
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[1] < 1900 || (int) $m[1] > 2100) {
            return null;
        }
        return $value;
    }

    /** Terima "Y-m-d\TH:i", "Y-m-d H:i" atau dengan detik. */
    public static function parseDateTime(string $value): ?string
    {
        $value = str_replace('T', ' ', trim($value));
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
            $dt = DateTimeImmutable::createFromFormat('!' . $format, $value);
            if ($dt !== false && $dt->format($format) === $value) {
                $year = (int) $dt->format('Y');
                if ($year < 1900 || $year > 2100) {
                    return null;
                }
                return $dt->format('Y-m-d H:i:s');
            }
        }
        return null;
    }
}
