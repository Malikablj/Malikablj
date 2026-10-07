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
use App\Npr\NprService;

/**
 * Dokumen berversi (PRD §9.1). Fase 2: lampiran NPR (Contoh Bentuk Produk, Referensi Spek)
 * dan unduhan aman. Dokumen project › part › proses ditambahkan di fase 7.
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
