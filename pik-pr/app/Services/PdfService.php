<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Core\View;
use App\Models\PrStatus;
use App\Repositories\ApprovalLogRepository;
use App\Repositories\PrRepository;
use App\Repositories\SettingRepository;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Dokumen PDF PR. Seluruh isi diambil dari database, bukan dari data browser.
 */
final class PdfService
{
    /**
     * @return array<string, mixed>
     */
    public function data(int $prId): array
    {
        $prs = new PrRepository();
        $pr = $prs->find($prId);
        if ($pr === null) {
            throw new HttpException(404);
        }
        if (!in_array($pr['status'], [PrStatus::Approved->value, PrStatus::Completed->value], true)) {
            throw new HttpException(422, 'PDF hanya tersedia untuk PR yang sudah disetujui.');
        }

        $signatures = [[
            'label' => 'Dibuat oleh',
            'name' => $pr['requester_name'],
            'title' => $pr['requester_job_title'] ?: 'Pemohon',
            'date' => $pr['submitted_at'],
        ]];
        foreach ((new ApprovalLogRepository())->forRound($prId, (int) $pr['submission_round']) as $log) {
            if ($log['action'] !== ApprovalService::ACTION_APPROVED) {
                continue;
            }
            $signatures[] = [
                'label' => $log['step_label'] . ' oleh',
                'name' => $log['approver_name'],
                'title' => $log['approver_job_title'] ?: 'Approver',
                'date' => $log['acted_at'],
            ];
        }

        return [
            'pr' => $pr,
            'items' => $prs->items($prId),
            'signatures' => $signatures,
            'settings' => (new SettingRepository())->all(),
            'generatedAt' => date('Y-m-d H:i:s'),
        ];
    }

    public function html(int $prId): string
    {
        return View::render('pdf/pr', $this->data($prId), null);
    }

    public function render(int $prId): string
    {
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot(BASE_PATH);
        $cache = BASE_PATH . '/storage/cache';
        if (is_dir($cache) && is_writable($cache)) {
            $options->setTempDir($cache);
        }

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($prId), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * @param array<string, mixed> $pr
     */
    public static function filename(array $pr): string
    {
        return str_replace(['/', '\\', ' '], '-', (string) $pr['pr_number']) . '.pdf';
    }
}
