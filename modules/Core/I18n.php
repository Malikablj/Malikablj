<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Dua bahasa (PRD §12): teks antarmuka disimpan di lang/id.php dan lang/en.php.
 * Bawaan Indonesia. Isian pengguna TIDAK diterjemahkan.
 */
final class I18n
{
    public const SUPPORTED = ['id', 'en'];

    private static string $locale = 'id';
    /** @var array<string,array<string,string>> */
    private static array $catalogs = [];

    private const MONTHS = [
        'id' => ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'],
        'en' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
    ];
    private const MONTHS_LONG = [
        'id' => ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'],
        'en' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
    ];
    private const DAYS = [
        'id' => ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'],
        'en' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
    ];

    public static function setLocale(string $locale): void
    {
        self::$locale = in_array($locale, self::SUPPORTED, true) ? $locale : 'id';
    }

    public static function locale(): string
    {
        return self::$locale;
    }

    /** @return array<string,string> */
    public static function catalog(string $locale): array
    {
        if (!isset(self::$catalogs[$locale])) {
            $file = dirname(__DIR__, 2) . '/lang/' . $locale . '.php';
            $data = is_file($file) ? require $file : [];
            self::$catalogs[$locale] = is_array($data) ? $data : [];
        }
        return self::$catalogs[$locale];
    }

    /** @param array<string,string|int|float> $params */
    public static function t(string $key, array $params = [], ?string $locale = null): string
    {
        $locale = $locale ?? self::$locale;
        $text = self::catalog($locale)[$key] ?? self::catalog('id')[$key] ?? $key;
        foreach ($params as $k => $v) {
            $text = str_replace(':' . $k, (string) $v, $text);
        }
        return $text;
    }

    public static function has(string $key, ?string $locale = null): bool
    {
        return isset(self::catalog($locale ?? self::$locale)[$key]);
    }

    /** 05 Okt 2026 / 05 Oct 2026 */
    public static function date(string|\DateTimeInterface|null $date, ?string $locale = null): string
    {
        $d = self::toDate($date);
        if ($d === null) {
            return '–';
        }
        $locale = $locale ?? self::$locale;
        return $d->format('d') . ' ' . self::MONTHS[$locale][(int) $d->format('n') - 1] . ' ' . $d->format('Y');
    }

    /** 05 Okt (tanpa tahun) */
    public static function dateShort(string|\DateTimeInterface|null $date, ?string $locale = null): string
    {
        $d = self::toDate($date);
        if ($d === null) {
            return '–';
        }
        $locale = $locale ?? self::$locale;
        return $d->format('d') . ' ' . self::MONTHS[$locale][(int) $d->format('n') - 1];
    }

    /** 05 Okt 2026 14:30 */
    public static function dateTime(string|\DateTimeInterface|null $date, ?string $locale = null): string
    {
        $d = self::toDate($date);
        return $d === null ? '–' : self::date($d, $locale) . ' ' . $d->format('H:i');
    }

    public static function monthName(int $month, ?string $locale = null, bool $long = true): string
    {
        $locale = $locale ?? self::$locale;
        return ($long ? self::MONTHS_LONG : self::MONTHS)[$locale][$month - 1] ?? '';
    }

    public static function dayName(int $isoWeekday, ?string $locale = null): string
    {
        return self::DAYS[$locale ?? self::$locale][$isoWeekday - 1] ?? '';
    }

    public static function number(float|int|string|null $n, int $decimals = 0, ?string $locale = null): string
    {
        if ($n === null || $n === '') {
            return '–';
        }
        $locale = $locale ?? self::$locale;
        return $locale === 'id'
            ? number_format((float) $n, $decimals, ',', '.')
            : number_format((float) $n, $decimals, '.', ',');
    }

    private static function toDate(string|\DateTimeInterface|null $date): ?\DateTimeImmutable
    {
        if ($date === null || $date === '' || $date === '0000-00-00') {
            return null;
        }
        if ($date instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($date);
        }
        try {
            return new \DateTimeImmutable($date, Clock::tz());
        } catch (\Exception) {
            return null;
        }
    }
}
