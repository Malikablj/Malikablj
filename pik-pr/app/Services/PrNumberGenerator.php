<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Repositories\SettingRepository;
use DateTimeImmutable;
use LogicException;
use PDO;

/**
 * Nomor PR: {prefix}/{BULAN}/{TAHUN}-{KODE DEPT}PR{urut 3 digit}
 * contoh:   PR/PIK/SEPT/2026-PDPR077
 *
 * Nomor urut per department per tahun. Counter di-increment dengan
 * INSERT ... ON DUPLICATE KEY UPDATE yang mengunci baris counter sampai
 * transaksi selesai, sehingga dua submit bersamaan tidak mendapat nomor sama.
 * UNIQUE KEY pada purchase_requisitions.pr_number menjadi pengaman terakhir.
 */
final class PrNumberGenerator
{
    private PDO $db;
    private SettingRepository $settings;

    public function __construct(?PDO $db = null, ?SettingRepository $settings = null)
    {
        $this->db = $db ?? Database::connection();
        $this->settings = $settings ?? new SettingRepository($this->db);
    }

    public function next(string $departmentCode, string $prDate): string
    {
        if (!$this->db->inTransaction()) {
            throw new LogicException('PrNumberGenerator::next() wajib dipanggil di dalam transaksi.');
        }

        $date = new DateTimeImmutable($prDate);
        $scope = $departmentCode . '-' . $date->format('Y');

        $this->db->prepare(
            'INSERT INTO pr_number_sequences (scope, last_number) VALUES (?, 1)
            ON DUPLICATE KEY UPDATE last_number = last_number + 1',
        )->execute([$scope]);

        $stmt = $this->db->prepare('SELECT last_number FROM pr_number_sequences WHERE scope = ?');
        $stmt->execute([$scope]);
        $sequence = (int) $stmt->fetchColumn();

        return self::format($this->settings->get('pr_prefix'), $date, $departmentCode, $sequence);
    }

    public static function format(string $prefix, DateTimeImmutable $date, string $departmentCode, int $sequence): string
    {
        return sprintf(
            '%s/%s/%s-%sPR%03d',
            $prefix,
            self::monthLabel($date),
            $date->format('Y'),
            $departmentCode,
            $sequence,
        );
    }

    /**
     * Contoh format untuk ditampilkan di form sebelum nomor terbit.
     */
    public function preview(string $departmentCode, string $prDate): string
    {
        $date = new DateTimeImmutable($prDate !== '' ? $prDate : 'today');

        return sprintf(
            '%s/%s/%s-%sPR###',
            $this->settings->get('pr_prefix'),
            self::monthLabel($date),
            $date->format('Y'),
            $departmentCode,
        );
    }

    private static function monthLabel(DateTimeImmutable $date): string
    {
        $months = (array) Config::get('app.pr.months', []);

        return (string) ($months[(int) $date->format('n') - 1] ?? strtoupper($date->format('M')));
    }
}
