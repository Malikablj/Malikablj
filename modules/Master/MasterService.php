<?php
declare(strict_types=1);

namespace App\Master;

use App\Core\AuditLogger;
use App\Core\BusinessRuleException;
use App\Core\Db;
use App\Core\Gate;
use App\Core\I18n;
use App\Core\NotFoundException;
use App\Core\User;
use App\Core\ValidationException;

/**
 * Daftar master NPR & tipe dokumen (PRD §4.4). Hanya Admin yang mengubah.
 * "Menghapus" = menonaktifkan, sehingga data lama tetap terbaca.
 */
final class MasterService
{
    /** Kategori yang dikelola di Pengaturan > Master NPR (urutan tampilan). */
    public const CATEGORIES = [
        'part_name', 'request_type', 'development_type', 'product_application', 'product_content',
        'resin', 'color', 'surface', 'neck_preform', 'printing_method', 'varnish', 'labelling_side',
        'mould_method', 'packaging', 'test_method', 'document_type',
    ];

    /** @var array<string,list<array<string,mixed>>> */
    private static array $cache = [];

    /** @return list<array<string,mixed>> opsi (aktif saja kecuali $includeInactive) */
    public static function options(string $category, bool $includeInactive = false): array
    {
        $key = $category . ($includeInactive ? ':all' : '');
        if (!isset(self::$cache[$key])) {
            $rows = Db::fetchAll(
                'SELECT id, category, code, label_id, label_en, meta_json, sort_order, is_active, is_builtin
                 FROM master_options WHERE category = ?' . ($includeInactive ? '' : ' AND is_active = 1') . '
                 ORDER BY sort_order, id',
                [$category]
            );
            foreach ($rows as &$r) {
                $r['meta'] = $r['meta_json'] ? (json_decode((string) $r['meta_json'], true) ?: []) : [];
            }
            unset($r);
            self::$cache[$key] = $rows;
        }
        return self::$cache[$key];
    }

    /** Kode yang valid untuk kategori (termasuk nonaktif agar data lama tetap valid bila tidak berubah). */
    public static function isValid(string $category, string $code, bool $activeOnly = true): bool
    {
        foreach (self::options($category, !$activeOnly) as $o) {
            if ($o['code'] === $code) {
                return true;
            }
        }
        return false;
    }

    /** Label sesuai bahasa; opsi nonaktif tetap dapat ditampilkan pada data lama. */
    public static function label(string $category, ?string $code, ?string $locale = null): string
    {
        if ($code === null || $code === '') {
            return '';
        }
        $locale = $locale ?? I18n::locale();
        foreach (self::options($category, true) as $o) {
            if ($o['code'] === $code) {
                return (string) ($locale === 'en' ? $o['label_en'] : $o['label_id']);
            }
        }
        return $code;
    }

    /** @return array<string,mixed> */
    public static function meta(string $category, ?string $code): array
    {
        foreach (self::options($category, true) as $o) {
            if ($o['code'] === $code) {
                return $o['meta'];
            }
        }
        return [];
    }

    public static function flush(): void
    {
        self::$cache = [];
    }

    /** @param array<string,mixed> $data code, label_id, label_en, units */
    public function create(User $actor, string $category, array $data): int
    {
        Gate::authorize($actor, 'settings.manage');
        $this->assertCategory($category);
        $clean = $this->validate($category, $data, null);
        $sort = (int) Db::value('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM master_options WHERE category = ?', [$category]);
        $id = Db::transaction(function () use ($actor, $category, $clean, $sort): int {
            $id = Db::insert('master_options', [
                'category' => $category,
                'code' => $clean['code'],
                'label_id' => $clean['label_id'],
                'label_en' => $clean['label_en'],
                'meta_json' => $clean['meta'] ? json_encode($clean['meta'], JSON_UNESCAPED_UNICODE) : null,
                'sort_order' => $sort,
                'is_active' => 1,
                'is_builtin' => 0,
            ]);
            AuditLogger::log('master.create', 'master_option', $id, null, ['category' => $category] + $clean, null, null, $actor);
            return $id;
        });
        self::flush();
        return $id;
    }

    /** @param array<string,mixed> $data label_id, label_en, units */
    public function update(User $actor, int $id, array $data): void
    {
        Gate::authorize($actor, 'settings.manage');
        $row = $this->find($id);
        $clean = $this->validate($row['category'], $data + ['code' => $row['code']], $id);
        $meta = $row['meta_json'] ? (json_decode((string) $row['meta_json'], true) ?: []) : [];
        if (array_key_exists('units', $clean['meta'])) {
            $meta['units'] = $clean['meta']['units'];
        }
        Db::transaction(function () use ($actor, $id, $row, $clean, $meta): void {
            Db::update('master_options', [
                'label_id' => $clean['label_id'],
                'label_en' => $clean['label_en'],
                'meta_json' => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            ], ['id' => $id]);
            AuditLogger::log('master.update', 'master_option', $id,
                ['label_id' => $row['label_id'], 'label_en' => $row['label_en']],
                ['label_id' => $clean['label_id'], 'label_en' => $clean['label_en']], null, null, $actor);
        });
        self::flush();
    }

    public function setActive(User $actor, int $id, bool $active): void
    {
        Gate::authorize($actor, 'settings.manage');
        $row = $this->find($id);
        if ((bool) $row['is_active'] === $active) {
            return;
        }
        if (!$active) {
            $remaining = (int) Db::value('SELECT COUNT(*) FROM master_options WHERE category = ? AND is_active = 1 AND id <> ?', [$row['category'], $id]);
            if ($remaining === 0) {
                throw new BusinessRuleException(I18n::t('master.last_active'));
            }
        }
        Db::transaction(function () use ($actor, $id, $active, $row): void {
            Db::update('master_options', ['is_active' => $active ? 1 : 0], ['id' => $id]);
            AuditLogger::log($active ? 'master.activate' : 'master.deactivate', 'master_option', $id, null, ['category' => $row['category'], 'code' => $row['code']], null, null, $actor);
        });
        self::flush();
    }

    /** Geser urutan naik/turun dalam kategori. */
    public function move(User $actor, int $id, string $direction): void
    {
        Gate::authorize($actor, 'settings.manage');
        $category = (string) $this->find($id)['category'];
        Db::transaction(function () use ($id, $direction, $category): void {
            $this->normalizeOrder($category); // urutan unik 10, 20, 30, …
            $ids = array_map('intval', Db::column('SELECT id FROM master_options WHERE category = ? ORDER BY sort_order, id', [$category]));
            $pos = array_search($id, $ids, true);
            $swap = $direction === 'up' ? $pos - 1 : $pos + 1;
            if ($pos === false || $swap < 0 || $swap >= count($ids)) {
                return;
            }
            [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
            foreach ($ids as $i => $optionId) {
                Db::update('master_options', ['sort_order' => ($i + 1) * 10], ['id' => $optionId]);
            }
        });
        self::flush();
    }

    /**
     * Nama part bebas ("Lainnya") yang pernah dipakai, dengan jumlah pemakaian — kandidat untuk
     * dipromosikan menjadi master (PRD §4.3).
     * @return list<array{name:string,uses:int}>
     */
    public function customPartNames(): array
    {
        return Db::fetchAll(
            "SELECT p.part_name_custom AS name, COUNT(*) AS uses FROM npr_parts p
             WHERE p.part_name_code = '__other' AND p.part_name_custom IS NOT NULL AND p.part_name_custom <> ''
               AND NOT EXISTS (SELECT 1 FROM master_options m WHERE m.category = 'part_name' AND (m.label_id = p.part_name_custom OR m.label_en = p.part_name_custom))
             GROUP BY p.part_name_custom ORDER BY uses DESC, name"
        );
    }

    /** Promosikan nama part bebas menjadi master; NPR lama yang memakai nama itu ikut ditautkan. */
    public function promotePartName(User $actor, string $name): int
    {
        Gate::authorize($actor, 'settings.manage');
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new ValidationException(['name' => I18n::t('validation.required_max', ['max' => 120])]);
        }
        $code = $this->slug($name);
        $base = $code;
        $i = 2;
        while (Db::value("SELECT id FROM master_options WHERE category = 'part_name' AND code = ?", [$code])) {
            $code = $base . '_' . $i++;
        }
        return Db::transaction(function () use ($actor, $name, $code): int {
            $id = $this->create($actor, 'part_name', ['code' => $code, 'label_id' => $name, 'label_en' => $name]);
            $n = Db::execute("UPDATE npr_parts SET part_name_code = ?, part_name_custom = NULL WHERE part_name_code = '__other' AND part_name_custom = ?", [$code, $name]);
            AuditLogger::log('master.promote_part_name', 'master_option', $id, null, ['name' => $name, 'linked_parts' => $n], null, null, $actor);
            return $id;
        });
    }

    /** @return array<string,mixed> */
    public function find(int $id): array
    {
        $row = Db::fetch('SELECT * FROM master_options WHERE id = ?', [$id]);
        if (!$row) {
            throw new NotFoundException(I18n::t('error.not_found'));
        }
        return $row;
    }

    private function assertCategory(string $category): void
    {
        if (!in_array($category, self::CATEGORIES, true)) {
            throw new ValidationException(['category' => I18n::t('validation.invalid')]);
        }
    }

    /**
     * @param array<string,mixed> $data
     * @return array{code:string,label_id:string,label_en:string,meta:array<string,mixed>}
     */
    private function validate(string $category, array $data, ?int $id): array
    {
        $errors = [];
        $labelId = trim((string) ($data['label_id'] ?? ''));
        $labelEn = trim((string) ($data['label_en'] ?? ''));
        $code = trim((string) ($data['code'] ?? ''));
        if ($code === '') {
            $code = $this->slug($labelId !== '' ? $labelId : $labelEn);
        }
        if ($labelId === '' || mb_strlen($labelId) > 160) {
            $errors['label_id'] = I18n::t('validation.required_max', ['max' => 160]);
        }
        if ($labelEn === '') {
            $labelEn = $labelId;
        }
        if (mb_strlen($labelEn) > 160) {
            $errors['label_en'] = I18n::t('validation.max', ['max' => 160]);
        }
        if (!preg_match('/^[a-z0-9_]{1,60}$/', $code) || $code === '__other') {
            $errors['code'] = I18n::t('master.code_invalid');
        } elseif ($id === null && Db::value('SELECT id FROM master_options WHERE category = ? AND code = ?', [$category, $code])) {
            $errors['code'] = I18n::t('master.code_taken');
        }
        $meta = [];
        if ($category === 'test_method' && isset($data['units'])) {
            $units = array_values(array_filter(array_map('trim', explode(',', (string) $data['units'])), static fn ($u) => $u !== ''));
            $meta['units'] = array_slice($units, 0, 5);
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return ['code' => $code, 'label_id' => $labelId, 'label_en' => $labelEn, 'meta' => $meta];
    }

    private function normalizeOrder(string $category): void
    {
        $ids = Db::column('SELECT id FROM master_options WHERE category = ? ORDER BY sort_order, id', [$category]);
        foreach ($ids as $i => $id) {
            Db::update('master_options', ['sort_order' => ($i + 1) * 10], ['id' => (int) $id]);
        }
    }

    private function slug(string $text): string
    {
        $s = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text), '_'));
        return substr($s !== '' ? $s : 'opsi', 0, 50);
    }
}
