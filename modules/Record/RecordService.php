<?php
declare(strict_types=1);

namespace App\Record;

use App\Core\AuditLogger;
use App\Core\AuthorizationException;
use App\Core\BusinessRuleException;
use App\Core\Clock;
use App\Core\Db;
use App\Core\Gate;
use App\Core\I18n;
use App\Core\NotFoundException;
use App\Core\User;
use App\Core\ValidationException;

/**
 * Catatan proses per part (PRD §9.3): Trial & Evaluation / T0 / Commissioning (trial_records),
 * Permintaan & Persiapan Material (material_requests — Purchasing dapat memperbarui), Validation
 * Mass Production (validation_records). Trial & validasi: satu catatan per iterasi proses.
 */
final class RecordService
{
    /** record_type proses → [tabel, jenis, izin] */
    public const KINDS = [
        'trial' => ['trial_records', 'trial', 'record.trial'],
        't0' => ['trial_records', 't0', 'record.trial'],
        'commissioning' => ['trial_records', 'commissioning', 'record.trial'],
        'material_trial' => ['material_requests', 'trial', 'record.material'],
        'material_bulk' => ['material_requests', 'bulk', 'record.material'],
        'material_preparation' => ['material_requests', 'preparation', 'record.material'],
        'validation' => ['validation_records', null, 'record.validation'],
    ];

    public const TRIAL_FIELDS = ['trial_date' => 'date', 'machine' => 120, 'mold' => 120, 'cavity' => 40, 'material' => 190, 'parameters' => 4000,
        'quantity' => 60, 'tests' => 4000, 'problems' => 4000, 'evaluation' => 4000, 'recommendation' => 4000];
    public const VALIDATION_FIELDS = ['validation_date' => 'date', 'machine' => 120, 'mold' => 120, 'cavity' => 40, 'material' => 190, 'production_qty' => 60,
        'parameters' => 4000, 'problems' => 4000, 'corrective_action' => 4000, 'comment' => 4000];
    public const MATERIAL_FIELDS = ['material' => 190, 'material_received' => 190, 'batch_no' => 80, 'quantity_kg' => 'decimal', 'requested_date' => 'date',
        'received_date' => 'date', 'supplier' => 190, 'preparation_date' => 'date', 'pic_user_id' => 'user', 'notes' => 4000];

    /** @return array{kind:string,table:string,type:?string,permission:string}|null */
    public static function kindFor(array $process): ?array
    {
        $k = (string) ($process['record_type'] ?? '');
        if (!isset(self::KINDS[$k])) {
            return null;
        }
        [$table, $type, $perm] = self::KINDS[$k];
        return ['kind' => $k, 'table' => $table, 'type' => $type, 'permission' => $perm];
    }

    public function canEdit(User $user, array $process): bool
    {
        $kind = self::kindFor($process);
        return $kind !== null && Gate::can($user, $kind['permission']) && $process['part_id'] !== null;
    }

    /** Catatan untuk proses: trial/validasi per iterasi (terbaru dulu), material semua baris. @return list<array<string,mixed>> */
    public function forProcess(array $process): array
    {
        $kind = self::kindFor($process);
        if ($kind === null) {
            return [];
        }
        return match ($kind['table']) {
            'trial_records' => Db::fetchAll('SELECT r.*, u.name AS updated_by_name FROM trial_records r LEFT JOIN users u ON u.id = COALESCE(r.updated_by, r.created_by) WHERE r.process_id = ? ORDER BY r.iteration DESC', [(int) $process['id']]),
            'validation_records' => Db::fetchAll('SELECT r.*, u.name AS updated_by_name FROM validation_records r LEFT JOIN users u ON u.id = COALESCE(r.updated_by, r.created_by) WHERE r.process_id = ? ORDER BY r.iteration DESC', [(int) $process['id']]),
            default => Db::fetchAll('SELECT r.*, u.name AS updated_by_name, pu.name AS pic_name FROM material_requests r LEFT JOIN users u ON u.id = COALESCE(r.updated_by, r.created_by) LEFT JOIN users pu ON pu.id = r.pic_user_id WHERE r.process_id = ? ORDER BY r.id', [(int) $process['id']]),
        };
    }

    /** Semua catatan project (tab Trial & Material). @return array{trial:list<array<string,mixed>>,material:list<array<string,mixed>>,validation:list<array<string,mixed>>} */
    public function forProject(int $projectId): array
    {
        $join = 'JOIN processes pr ON pr.id = r.process_id LEFT JOIN project_parts pp ON pp.id = r.part_id LEFT JOIN users u ON u.id = COALESCE(r.updated_by, r.created_by)';
        return [
            'trial' => Db::fetchAll("SELECT r.*, pr.code, pr.name AS process_name, pp.name AS part_name, u.name AS updated_by_name FROM trial_records r $join WHERE r.project_id = ? ORDER BY pp.sort_order, r.trial_date, r.id", [$projectId]),
            'material' => Db::fetchAll("SELECT r.*, pr.code, pr.name AS process_name, pp.name AS part_name, u.name AS updated_by_name, pu.name AS pic_name FROM material_requests r $join LEFT JOIN users pu ON pu.id = r.pic_user_id WHERE r.project_id = ? ORDER BY pp.sort_order, r.id", [$projectId]),
            'validation' => Db::fetchAll("SELECT r.*, pr.code, pr.name AS process_name, pp.name AS part_name, u.name AS updated_by_name FROM validation_records r $join WHERE r.project_id = ? ORDER BY pp.sort_order, r.validation_date, r.id", [$projectId]),
        ];
    }

    /**
     * Simpan catatan trial/validasi untuk iterasi berjalan proses (buat atau perbarui).
     * @param array<string,mixed> $input
     */
    public function saveIteration(User $actor, int $processId, array $input): int
    {
        $p = $this->process($processId);
        $kind = $this->authorize($actor, $p);
        if ($kind['table'] === 'material_requests') {
            throw new BusinessRuleException(I18n::t('validation.invalid'));
        }
        $fields = $kind['table'] === 'trial_records' ? self::TRIAL_FIELDS : self::VALIDATION_FIELDS;
        $data = $this->clean($input, $fields);
        if ($kind['table'] === 'validation_records' && array_key_exists('result', $input)) {
            $r = trim((string) $input['result']);
            if ($r !== '' && !in_array($r, ['pass', 'pass_with_condition', 'fail'], true)) {
                throw new ValidationException(['result' => I18n::t('validation.invalid')]);
            }
            $data['result'] = $r !== '' ? $r : null;
        }
        if ($kind['table'] === 'trial_records' && array_key_exists('result', $input)) {
            $data['result'] = mb_substr(trim((string) $input['result']), 0, 30) ?: null;
        }
        return Db::transaction(function () use ($actor, $p, $kind, $data): int {
            $iteration = (int) $p['iteration'];
            $existing = Db::fetch('SELECT * FROM ' . $kind['table'] . ' WHERE process_id = ? AND iteration = ? FOR UPDATE', [(int) $p['id'], $iteration]);
            if ($existing) {
                [$old, $new] = AuditLogger::diff(array_intersect_key($existing, $data), $data);
                if ($new) {
                    Db::update($kind['table'], $data + ['updated_by' => $actor->id], ['id' => (int) $existing['id']]);
                    AuditLogger::log('record.update', $kind['table'], (int) $existing['id'], $old, $new, null, (int) $p['project_id'], $actor);
                }
                return (int) $existing['id'];
            }
            $base = ['project_id' => (int) $p['project_id'], 'part_id' => (int) $p['part_id'], 'process_id' => (int) $p['id'], 'iteration' => $iteration,
                     'created_by' => $actor->id, 'created_at' => Clock::nowString()];
            if ($kind['table'] === 'trial_records') {
                $base['trial_type'] = $kind['type'];
            }
            $id = Db::insert($kind['table'], $base + $data);
            AuditLogger::log('record.create', $kind['table'], $id, null, $data + ['iteration' => $iteration], null, (int) $p['project_id'], $actor);
            return $id;
        });
    }

    /**
     * Tambah / perbarui baris material (Purchasing dapat memperbarui data material).
     * @param array<string,mixed> $input
     */
    public function saveMaterial(User $actor, int $processId, array $input, ?int $recordId = null): int
    {
        $p = $this->process($processId);
        $kind = $this->authorize($actor, $p);
        if ($kind['table'] !== 'material_requests') {
            throw new BusinessRuleException(I18n::t('validation.invalid'));
        }
        $data = $this->clean($input, self::MATERIAL_FIELDS);
        return Db::transaction(function () use ($actor, $p, $kind, $data, $recordId): int {
            if ($recordId !== null) {
                $row = Db::fetch('SELECT * FROM material_requests WHERE id = ? AND process_id = ? FOR UPDATE', [$recordId, (int) $p['id']]);
                if (!$row) {
                    throw new NotFoundException(I18n::t('error.not_found'));
                }
                [$old, $new] = AuditLogger::diff(array_intersect_key($row, $data), $data);
                if ($new) {
                    Db::update('material_requests', $data + ['updated_by' => $actor->id], ['id' => $recordId]);
                    AuditLogger::log('record.update', 'material_requests', $recordId, $old, $new, null, (int) $p['project_id'], $actor);
                }
                return $recordId;
            }
            $id = Db::insert('material_requests', [
                'project_id' => (int) $p['project_id'], 'part_id' => (int) $p['part_id'], 'process_id' => (int) $p['id'], 'request_type' => $kind['type'],
                'created_by' => $actor->id, 'created_at' => Clock::nowString(),
            ] + $data);
            AuditLogger::log('record.create', 'material_requests', $id, null, $data, null, (int) $p['project_id'], $actor);
            return $id;
        });
    }

    /** Hasil validasi mengikuti keputusan proses (dipanggil saat Validation diselesaikan). */
    public static function syncValidationResult(int $processId, int $iteration, string $outcome, ?int $userId): void
    {
        if (!in_array($outcome, ['pass', 'pass_with_condition', 'fail'], true)) {
            return;
        }
        Db::execute('UPDATE validation_records SET result = ?, updated_by = ? WHERE process_id = ? AND iteration = ?', [$outcome, $userId, $processId, $iteration]);
    }

    /** @return array{kind:string,table:string,type:?string,permission:string} */
    private function authorize(User $actor, array $p): array
    {
        $kind = self::kindFor($p);
        if ($kind === null || $p['part_id'] === null) {
            throw new BusinessRuleException(I18n::t('record.not_applicable'));
        }
        if (!Gate::can($actor, $kind['permission'])) {
            throw new AuthorizationException(I18n::t('error.forbidden'));
        }
        if ($p['part_cancelled_at'] !== null || $p['project_finished_at'] !== null || (int) $p['project_archived'] === 1) {
            throw new BusinessRuleException(I18n::t('doc.closed'));
        }
        return $kind;
    }

    /** @return array<string,mixed> */
    private function process(int $id): array
    {
        $p = Db::fetch(
            'SELECT pr.*, pp.cancelled_at AS part_cancelled_at, pj.finished_at AS project_finished_at, pj.is_archived AS project_archived
             FROM processes pr JOIN projects pj ON pj.id = pr.project_id LEFT JOIN project_parts pp ON pp.id = pr.part_id WHERE pr.id = ?',
            [$id]
        );
        if (!$p) {
            throw new NotFoundException(I18n::t('error.not_found'));
        }
        return $p;
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,int|string> $fields field => panjang maks | 'date' | 'decimal' | 'user'
     * @return array<string,mixed>
     */
    private function clean(array $input, array $fields): array
    {
        $out = [];
        $errors = [];
        foreach ($fields as $k => $rule) {
            if (!array_key_exists($k, $input)) {
                continue;
            }
            $v = is_string($input[$k]) ? trim($input[$k]) : $input[$k];
            if ($v === '' || $v === null) {
                $out[$k] = null;
                continue;
            }
            if ($rule === 'date') {
                $d = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $v);
                if (!$d || $d->format('Y-m-d') !== $v) {
                    $errors[$k] = I18n::t('validation.date');
                    continue;
                }
                $out[$k] = $v;
            } elseif ($rule === 'decimal') {
                if (!is_string($v) || !preg_match('/^\d{1,9}([.,]\d{1,3})?$/', $v)) {
                    $errors[$k] = I18n::t('validation.number');
                    continue;
                }
                $out[$k] = str_replace(',', '.', $v);
            } elseif ($rule === 'user') {
                if (!Db::value('SELECT id FROM users WHERE id = ? AND is_active = 1', [(int) $v])) {
                    $errors[$k] = I18n::t('validation.invalid');
                    continue;
                }
                $out[$k] = (int) $v;
            } else {
                $s = (string) $v;
                if (mb_strlen($s) > (int) $rule) {
                    $errors[$k] = I18n::t('validation.max', ['max' => (int) $rule]);
                    continue;
                }
                $out[$k] = $s;
            }
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        return $out;
    }
}
