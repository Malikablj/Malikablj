<?php
declare(strict_types=1);

namespace App\Settings;

use App\Core\AuditLogger;
use App\Core\Db;
use App\Core\Gate;
use App\Core\I18n;
use App\Core\NotFoundException;
use App\Core\User;
use App\Core\ValidationException;
use App\Scheduling\ScheduleService;
use App\Scheduling\WorkingCalendar;

/**
 * Hari libur (PRD §6.2): Admin mengelola tanggal, nama, dan opsi berulang tahunan. Hari libur tidak
 * dihitung sebagai hari kerja; setiap perubahan menghitung ulang jadwal proses yang belum dimulai pada
 * semua project berjalan (tercatat di log penggeseran jadwal dengan jenis "calendar").
 */
final class HolidayService
{
    /** @return list<array<string,mixed>> libur pada tahun tsb (termasuk yang berulang) */
    public function forYear(int $year): array
    {
        $rows = Db::fetchAll(
            'SELECT h.*, u.name AS created_by_name FROM holidays h LEFT JOIN users u ON u.id = h.created_by
             WHERE YEAR(h.holiday_date) = ? OR h.is_recurring = 1 ORDER BY DATE_FORMAT(h.holiday_date, \'%m-%d\'), h.holiday_date',
            [$year]
        );
        foreach ($rows as &$r) {
            $r['date_in_year'] = (int) $r['is_recurring'] === 1 ? sprintf('%04d-%s', $year, substr((string) $r['holiday_date'], 5)) : (string) $r['holiday_date'];
        }
        unset($r);
        usort($rows, static fn ($a, $b) => $a['date_in_year'] <=> $b['date_in_year']);
        return $rows;
    }

    /** @param array<string,mixed> $input holiday_date, name, is_recurring @return array{id:int,projects:int,shifted:int} */
    public function save(User $actor, ?int $id, array $input): array
    {
        Gate::authorize($actor, 'settings.manage');
        $date = trim((string) ($input['holiday_date'] ?? ''));
        $name = trim((string) ($input['name'] ?? ''));
        $recurring = !empty($input['is_recurring']) ? 1 : 0;
        $errors = [];
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            $errors['holiday_date'] = I18n::t('validation.date');
        }
        if ($name === '' || mb_strlen($name) > 160) {
            $errors['name'] = I18n::t('validation.required_max', ['max' => 160]);
        }
        if (!$errors) {
            $dup = Db::value('SELECT id FROM holidays WHERE holiday_date = ?' . ($id !== null ? ' AND id <> ?' : ''), $id !== null ? [$date, $id] : [$date]);
            if ($dup) {
                $errors['holiday_date'] = I18n::t('holiday.duplicate');
            }
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        return Db::transaction(function () use ($actor, $id, $date, $name, $recurring): array {
            $data = ['holiday_date' => $date, 'name' => $name, 'is_recurring' => $recurring];
            if ($id === null) {
                $id = Db::insert('holidays', $data + ['created_by' => $actor->id]);
                AuditLogger::log('holiday.create', 'holiday', $id, null, $data, null, null, $actor);
            } else {
                $old = Db::fetch('SELECT holiday_date, name, is_recurring FROM holidays WHERE id = ? FOR UPDATE', [$id]);
                if (!$old) {
                    throw new NotFoundException(I18n::t('error.not_found'));
                }
                Db::update('holidays', $data, ['id' => $id]);
                [$o, $n] = AuditLogger::diff($old, $data);
                AuditLogger::log('holiday.update', 'holiday', $id, $o, $n, null, null, $actor);
            }
            return ['id' => $id] + $this->recalculateAll($actor, I18n::t('holiday.recalc_reason', ['date' => $date, 'name' => $name], 'id'));
        });
    }

    /** @return array{projects:int,shifted:int} */
    public function delete(User $actor, int $id): array
    {
        Gate::authorize($actor, 'settings.manage');
        return Db::transaction(function () use ($actor, $id): array {
            $old = Db::fetch('SELECT holiday_date, name, is_recurring FROM holidays WHERE id = ? FOR UPDATE', [$id]);
            if (!$old) {
                throw new NotFoundException(I18n::t('error.not_found'));
            }
            Db::execute('DELETE FROM holidays WHERE id = ?', [$id]);
            AuditLogger::log('holiday.delete', 'holiday', $id, $old, null, null, null, $actor);
            return $this->recalculateAll($actor, I18n::t('holiday.recalc_reason_deleted', ['date' => (string) $old['holiday_date'], 'name' => (string) $old['name']], 'id'));
        });
    }

    /** Impor beberapa tanggal sekaligus (satu baris "YYYY-MM-DD;Nama"; tanggal yang sudah ada dilewati). @return array{added:int,skipped:int,projects:int,shifted:int} */
    public function import(User $actor, string $text): array
    {
        Gate::authorize($actor, 'settings.manage');
        $lines = array_filter(array_map('trim', preg_split('/\r?\n/', $text) ?: []));
        if (count($lines) > 200) {
            throw new ValidationException(['import' => I18n::t('holiday.import_too_many')]);
        }
        $rows = [];
        $errors = [];
        foreach (array_values($lines) as $i => $line) {
            $parts = array_map('trim', preg_split('/[;,\t]/', $line, 2) ?: []);
            $date = $parts[0] ?? '';
            $name = $parts[1] ?? '';
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$d || $d->format('Y-m-d') !== $date || $name === '' || mb_strlen($name) > 160) {
                $errors[] = I18n::t('holiday.import_line', ['line' => $i + 1]);
                continue;
            }
            $rows[$date] = $name;
        }
        if ($errors) {
            throw new ValidationException(['import' => implode(' ', array_slice($errors, 0, 5))]);
        }
        return Db::transaction(function () use ($actor, $rows): array {
            $added = 0;
            foreach ($rows as $date => $name) {
                if (Db::value('SELECT id FROM holidays WHERE holiday_date = ?', [$date])) {
                    continue;
                }
                $id = Db::insert('holidays', ['holiday_date' => $date, 'name' => $name, 'is_recurring' => 0, 'created_by' => $actor->id]);
                AuditLogger::log('holiday.create', 'holiday', $id, null, ['holiday_date' => $date, 'name' => $name, 'is_recurring' => 0], 'import', null, $actor);
                $added++;
            }
            $r = $added > 0 ? $this->recalculateAll($actor, I18n::t('holiday.recalc_reason_import', ['count' => $added], 'id')) : ['projects' => 0, 'shifted' => 0];
            return ['added' => $added, 'skipped' => count($rows) - $added] + $r;
        });
    }

    /** Hitung ulang jadwal semua project berjalan dengan kalender baru. @return array{projects:int,shifted:int} */
    private function recalculateAll(User $actor, string $reason): array
    {
        WorkingCalendar::flush();
        $schedule = new ScheduleService();
        $projects = 0;
        $shifted = 0;
        foreach (Db::column('SELECT id FROM projects WHERE finished_at IS NULL AND cancelled_at IS NULL AND is_archived = 0 ORDER BY id') as $pid) {
            $res = $schedule->recalculate((int) $pid, 'calendar', null, $reason, $actor, 'plan');
            $projects++;
            $shifted += count(array_filter($res['changes'], static fn ($c) => $c['shift'] !== null && $c['shift'] !== 0));
        }
        return ['projects' => $projects, 'shifted' => $shifted];
    }
}
