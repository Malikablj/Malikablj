<?php
declare(strict_types=1);

namespace App\Document;

use App\Core\AuditLogger;
use App\Core\AuthorizationException;
use App\Core\Clock;
use App\Core\Db;
use App\Core\Gate;
use App\Core\I18n;
use App\Core\NotFoundException;
use App\Core\User;
use App\Core\ValidationException;
use App\Master\MasterService;
use App\Npr\NprService;

/**
 * Dokumen berversi (PRD §9.1): lampiran NPR (Contoh Bentuk Produk, Referensi Spek), dokumen proses
 * (project › part › proses; jenis sama = versi baru, versi lama tetap tersimpan) dan unduhan aman.
 */
final class DocumentService
{
    public const NPR_CATEGORIES = ['product_shape', 'spec_reference'];

    /**
     * Unggah lampiran NPR (kolom biru — hanya yang boleh mengisi kolom biru NPR tsb).
     * @param array<string,mixed> $file entri $_FILES
     */
    public function addNprAttachment(User $actor, int $nprId, string $category, array $file, bool $isUploaded = true): int
    {
        if (!in_array($category, self::NPR_CATEGORIES, true)) {
            throw new ValidationException(['category' => I18n::t('validation.invalid')]);
        }
        $nprService = new NprService();
        $npr = $nprService->find($nprId);
        if (!$nprService->abilities($actor, $npr)['upload']) {
            throw new AuthorizationException(I18n::t('error.forbidden'));
        }
        $valid = UploadValidator::validate($file, 'attachment', null, $isUploaded);
        $path = DocumentStorage::store($valid, $isUploaded);
        try {
            return Db::transaction(function () use ($actor, $nprId, $category, $valid, $path): int {
                $docId = Db::insert('documents', [
                    'npr_id' => $nprId,
                    'npr_category' => $category,
                    'project_id' => (new NprService())->projectIdOf($nprId),
                    'doc_type_code' => 'customer_document',
                    'title' => mb_substr($valid['original'], 0, 190),
                    'version_count' => 1,
                    'created_by' => $actor->id,
                ]);
                $verId = Db::insert('document_versions', [
                    'document_id' => $docId,
                    'version_no' => 1,
                    'original_name' => $valid['original'],
                    'stored_path' => $path,
                    'mime_type' => $valid['mime'],
                    'extension' => $valid['ext'],
                    'size_bytes' => $valid['size'],
                    'sha256' => $valid['sha256'],
                    'status' => 'current',
                    'uploaded_by' => $actor->id,
                    'uploaded_at' => Clock::nowString(),
                ]);
                Db::update('documents', ['current_version_id' => $verId], ['id' => $docId]);
                AuditLogger::log('document.upload', 'document', $docId, null, ['npr_id' => $nprId, 'category' => $category, 'name' => $valid['original'], 'size' => $valid['size']], null, (new NprService())->projectIdOf($nprId), $actor);
                return $docId;
            });
        } catch (\Throwable $e) {
            @unlink(DocumentStorage::absolute($path));
            throw $e;
        }
    }

    /** Lepas lampiran NPR (saat draft/dikembalikan). File & riwayat tetap disimpan. */
    public function removeNprAttachment(User $actor, int $nprId, int $documentId): void
    {
        $nprService = new NprService();
        $npr = $nprService->find($nprId);
        if (!$nprService->abilities($actor, $npr)['upload']) {
            throw new AuthorizationException(I18n::t('error.forbidden'));
        }
        Db::transaction(function () use ($actor, $nprId, $documentId): void {
            $doc = Db::fetch('SELECT * FROM documents WHERE id = ? AND npr_id = ? AND is_removed = 0', [$documentId, $nprId]);
            if (!$doc) {
                throw new NotFoundException(I18n::t('error.not_found'));
            }
            Db::update('documents', ['is_removed' => 1, 'removed_by' => $actor->id, 'removed_at' => Clock::nowString()], ['id' => $documentId]);
            AuditLogger::log('document.remove', 'document', $documentId, ['title' => $doc['title']], null, null, $doc['project_id'] !== null ? (int) $doc['project_id'] : null, $actor);
        });
    }

    /** Boleh mengunggah ke proses: Admin/NPD, atau PIC proses (Sales PIC untuk proses Sales). */
    public function canUploadToProcess(User $user, array $process): bool
    {
        $owners = [$process['pic_user_id']];
        if (($process['pic_role_code'] ?? null) === 'admin_sales') {
            $owners[] = $process['sales_pic_id'] ?? null;
        }
        return Gate::can($user, 'document.upload', ['owner_ids' => $owners]);
    }

    /**
     * Unggah dokumen proses. Jenis yang sama pada proses yang sama menjadi versi baru (versi lama "superseded").
     * @param array<string,mixed> $file entri $_FILES
     */
    public function addProcessDocument(User $actor, int $processId, string $docType, array $file, ?string $notes = null, bool $isUploaded = true): int
    {
        $process = Db::fetch(
            'SELECT pr.*, r.code AS pic_role_code, pj.sales_pic_id, pj.is_archived, pj.cancelled_at AS project_cancelled_at, pp.cancelled_at AS part_cancelled_at
             FROM processes pr JOIN roles r ON r.id = pr.pic_role_id JOIN projects pj ON pj.id = pr.project_id LEFT JOIN project_parts pp ON pp.id = pr.part_id
             WHERE pr.id = ?',
            [$processId]
        );
        if (!$process) {
            throw new NotFoundException(I18n::t('error.not_found'));
        }
        if (!$this->canUploadToProcess($actor, $process)) {
            throw new AuthorizationException(I18n::t('error.forbidden'));
        }
        if ((int) $process['is_archived'] === 1 || $process['project_cancelled_at'] !== null || $process['part_cancelled_at'] !== null) {
            throw new \App\Core\BusinessRuleException(I18n::t('doc.closed'));
        }
        if (!MasterService::isValid('document_type', $docType)) {
            throw new ValidationException(['doc_type' => I18n::t('validation.invalid')]);
        }
        $notes = $notes !== null ? mb_substr(trim($notes), 0, 500) : null;
        $valid = UploadValidator::validate($file, 'file', null, $isUploaded);
        $path = DocumentStorage::store($valid, $isUploaded);
        try {
            return Db::transaction(function () use ($actor, $process, $docType, $valid, $path, $notes): int {
                $now = Clock::nowString();
                $doc = Db::fetch('SELECT * FROM documents WHERE process_id = ? AND doc_type_code = ? AND is_removed = 0 ORDER BY id LIMIT 1 FOR UPDATE', [(int) $process['id'], $docType]);
                if ($doc) {
                    $docId = (int) $doc['id'];
                    $versionNo = (int) Db::value('SELECT COALESCE(MAX(version_no), 0) FROM document_versions WHERE document_id = ?', [$docId]) + 1;
                    Db::execute("UPDATE document_versions SET status = 'superseded' WHERE document_id = ? AND status = 'current'", [$docId]);
                } else {
                    $versionNo = 1;
                    $docId = Db::insert('documents', [
                        'project_id' => (int) $process['project_id'], 'part_id' => $process['part_id'], 'process_id' => (int) $process['id'],
                        'doc_type_code' => $docType, 'title' => mb_substr(MasterService::label('document_type', $docType, 'id'), 0, 190),
                        'version_count' => 0, 'created_by' => $actor->id, 'created_at' => $now,
                    ]);
                }
                $verId = Db::insert('document_versions', [
                    'document_id' => $docId, 'version_no' => $versionNo, 'original_name' => $valid['original'], 'stored_path' => $path,
                    'mime_type' => $valid['mime'], 'extension' => $valid['ext'], 'size_bytes' => $valid['size'], 'sha256' => $valid['sha256'],
                    'status' => 'current', 'notes' => $notes, 'uploaded_by' => $actor->id, 'uploaded_at' => $now,
                ]);
                Db::update('documents', ['current_version_id' => $verId, 'version_count' => $versionNo], ['id' => $docId]);
                Db::update('projects', ['last_activity_at' => $now], ['id' => (int) $process['project_id']]);
                AuditLogger::log($versionNo > 1 ? 'document.new_version' : 'document.upload', 'document', $docId, null,
                    ['process_id' => (int) $process['id'], 'type' => $docType, 'version' => $versionNo, 'name' => $valid['original'], 'size' => $valid['size']],
                    $notes, (int) $process['project_id'], $actor);
                return $docId;
            });
        } catch (\Throwable $e) {
            @unlink(DocumentStorage::absolute($path));
            throw $e;
        }
    }

    /** Dokumen proses beserta versi terkini. @return list<array<string,mixed>> */
    public function forProcess(int $processId): array
    {
        return Db::fetchAll(
            'SELECT d.*, v.id AS version_id, v.version_no, v.original_name, v.extension, v.size_bytes, v.uploaded_at, v.notes, u.name AS uploader_name
             FROM documents d JOIN document_versions v ON v.id = d.current_version_id LEFT JOIN users u ON u.id = v.uploaded_by
             WHERE d.process_id = ? AND d.is_removed = 0 ORDER BY d.doc_type_code, d.id',
            [$processId]
        );
    }

    /** Semua versi sebuah dokumen (riwayat). @return list<array<string,mixed>> */
    public function versions(int $documentId): array
    {
        return Db::fetchAll(
            'SELECT v.*, u.name AS uploader_name FROM document_versions v LEFT JOIN users u ON u.id = v.uploaded_by WHERE v.document_id = ? ORDER BY v.version_no DESC',
            [$documentId]
        );
    }

    public const VERSION_STATUSES = ['current', 'superseded', 'rejected', 'approved'];

    /**
     * Pusat dokumen (PRD §9.1): pencarian & filter project, part, tipe, status, pengunggah, tanggal.
     * Hanya dokumen yang sudah terkait project (lampiran NPR draft tidak tampil).
     * @param array{q?:?string,project_id?:?int,part_id?:?int,doc_type?:?string,status?:?string,uploader_id?:?int,from?:?string,to?:?string,removed?:bool} $f
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function search(User $user, array $f, int $page = 1, int $perPage = 30): array
    {
        if (!Gate::can($user, 'document.view')) {
            throw new AuthorizationException(I18n::t('error.forbidden'));
        }
        $w = ['d.project_id IS NOT NULL', 'd.is_removed = ?'];
        $p = [!empty($f['removed']) && $user->isAdmin() ? 1 : 0];
        if (!empty($f['q'])) {
            $like = '%' . addcslashes((string) $f['q'], '%_\\') . '%';
            $w[] = '(d.title LIKE ? OR v.original_name LIKE ? OR pj.code LIKE ? OR pj.name LIKE ?)';
            array_push($p, $like, $like, $like, $like);
        }
        foreach (['project_id' => 'd.project_id', 'part_id' => 'd.part_id', 'uploader_id' => 'v.uploaded_by'] as $k => $col) {
            if (!empty($f[$k])) {
                $w[] = "$col = ?";
                $p[] = (int) $f[$k];
            }
        }
        if (!empty($f['doc_type'])) {
            $w[] = 'd.doc_type_code = ?';
            $p[] = (string) $f['doc_type'];
        }
        if (!empty($f['status']) && in_array($f['status'], self::VERSION_STATUSES, true)) {
            $w[] = 'v.status = ?';
            $p[] = $f['status'];
        }
        foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
            if (!empty($f[$k]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $f[$k])) {
                $w[] = "DATE(v.uploaded_at) $op ?";
                $p[] = $f[$k];
            }
        }
        $where = implode(' AND ', $w);
        $from = 'FROM documents d JOIN document_versions v ON v.id = d.current_version_id JOIN projects pj ON pj.id = d.project_id
                 LEFT JOIN project_parts pp ON pp.id = d.part_id LEFT JOIN processes pr ON pr.id = d.process_id LEFT JOIN users u ON u.id = v.uploaded_by';
        $total = (int) Db::value("SELECT COUNT(*) $from WHERE $where", $p);
        $perPage = max(5, min(100, $perPage));
        $offset = max(0, ($page - 1) * $perPage);
        $rows = Db::fetchAll(
            "SELECT d.*, v.id AS version_id, v.version_no, v.original_name, v.extension, v.size_bytes, v.status AS version_status, v.uploaded_at, v.notes,
                    u.name AS uploader_name, pj.code AS project_code, pj.name AS project_name, pp.name AS part_name, pr.code AS process_code, pr.name AS process_name, pr.name_en AS process_name_en
             $from WHERE $where ORDER BY v.uploaded_at DESC, d.id DESC LIMIT $perPage OFFSET $offset",
            $p
        );
        return ['rows' => $rows, 'total' => $total];
    }

    /** Dokumen project per part › proses (tab Dokumen). @return list<array<string,mixed>> */
    public function forProject(int $projectId): array
    {
        return Db::fetchAll(
            'SELECT d.*, v.id AS version_id, v.version_no, v.original_name, v.extension, v.size_bytes, v.status AS version_status, v.uploaded_at, u.name AS uploader_name,
                    pp.name AS part_name, pr.code AS process_code, pr.name AS process_name, pr.name_en AS process_name_en
             FROM documents d JOIN document_versions v ON v.id = d.current_version_id LEFT JOIN users u ON u.id = v.uploaded_by
             LEFT JOIN project_parts pp ON pp.id = d.part_id LEFT JOIN processes pr ON pr.id = d.process_id
             WHERE d.project_id = ? AND d.is_removed = 0 ORDER BY d.part_id IS NOT NULL, pp.sort_order, pr.sort_order, d.doc_type_code, d.id',
            [$projectId]
        );
    }

    /** Lepas dokumen proses (Admin, alasan wajib). File & versi tetap tersimpan; tercatat di audit. */
    public function removeDocument(User $actor, int $documentId, string $reason): void
    {
        if (!$actor->isAdmin()) {
            throw new AuthorizationException(I18n::t('error.forbidden'));
        }
        if (trim($reason) === '') {
            throw new ValidationException(['reason' => I18n::t('npr.v.reason_required')]);
        }
        Db::transaction(function () use ($actor, $documentId, $reason): void {
            $doc = Db::fetch('SELECT * FROM documents WHERE id = ? AND is_removed = 0 FOR UPDATE', [$documentId]);
            if (!$doc) {
                throw new NotFoundException(I18n::t('error.not_found'));
            }
            Db::update('documents', ['is_removed' => 1, 'removed_by' => $actor->id, 'removed_at' => Clock::nowString()], ['id' => $documentId]);
            AuditLogger::log('document.remove', 'document', $documentId, ['title' => $doc['title'], 'type' => $doc['doc_type_code']], null, $reason,
                $doc['project_id'] !== null ? (int) $doc['project_id'] : null, $actor);
        });
    }

    /**
     * Versi dokumen untuk diunduh setelah cek hak akses (PRD §9.1: unduh hanya lewat sesi + cek akses).
     * @return array<string,mixed>
     */
    public function versionForDownload(User $user, int $versionId): array
    {
        $v = Db::fetch(
            'SELECT v.*, d.npr_id, d.project_id, d.is_removed, d.title FROM document_versions v JOIN documents d ON d.id = v.document_id WHERE v.id = ?',
            [$versionId]
        );
        if (!$v) {
            throw new NotFoundException(I18n::t('error.not_found'));
        }
        if (!Gate::can($user, 'document.view')) {
            throw new AuthorizationException(I18n::t('error.forbidden'));
        }
        if ($v['npr_id'] !== null) {
            $nprService = new NprService();
            $nprService->assertView($user, $nprService->find((int) $v['npr_id']));
        }
        return $v;
    }
}
