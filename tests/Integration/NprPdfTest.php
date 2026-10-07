<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;
use App\Report\NprPdf;
use Tests\Support\DbTestCase;
use Tests\Support\NprFixtures;

/** UAT-04 / FR-NPR-08, FR-NPR-11, FR-NPR-12, I18N-04: PDF NPR dibuat server (mPDF). */
final class NprPdfTest extends DbTestCase
{
    use NprFixtures;

    private function text(string $pdf): string
    {
        if (!is_executable('/usr/bin/pdftotext')) {
            $this->markTestSkipped('pdftotext tidak tersedia');
        }
        $f = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($f, $pdf);
        $txt = (string) shell_exec('/usr/bin/pdftotext -layout ' . escapeshellarg($f) . ' -');
        @unlink($f);
        return $txt;
    }

    public function testUat04FinalPdfContainsFormDataFeedbackAndNoSignatureBlock(): void
    {
        Clock::freeze('2026-10-07 09:00:00');
        $sales = $this->makeUser('admin_sales', ['name' => 'Sari Wulandari', 'job_title' => 'Admin Sales']);
        $npd = $this->makeUser('npd_staff', ['name' => 'Rizki Ramadhan', 'job_title' => 'Admin NPD']);
        [$id] = $this->completedNpr($sales, $npd, $this->makeCustomer(), [['body', 'new_mold'], ['cap', 'subcont', 'existing_mould']], ['feasible', 'not_feasible']);
        $out = (new NprPdf())->build($npd, $id);
        $this->assertTrue($out['final']);
        $this->assertStringStartsWith('%PDF-', $out['content']);
        $this->assertSame('NPR_001-PIK-NPR-X-2026_Botol_Lotion_250ml.pdf', $out['filename']);
        $txt = $this->text($out['content']);
        foreach (['PT. Permata Indo Kemas', 'NEW PROJECT REQUEST', 'PIK-FORM-NPD-01', '001/PIK/NPR/X/2026', 'Botol Lotion 250ml',
                  'DEVELOPMENT YANG DIBUTUHKAN', 'DATA CUSTOMER', 'KOMPONEN PRODUK', 'KEBUTUHAN CETAKAN', 'METODE TEST', 'LAMPIRAN DARI CUSTOMER',
                  'Requested by', 'Received by', 'Sari Wulandari', 'Rizki Ramadhan', 'Tidak Feasible', 'Catatan NPD feasible', 'Extrusion Blow', 'Supplier X'] as $needle) {
            $this->assertStringContainsString($needle, $txt, $needle);
        }
        // Blok tanda tangan form kertas dihilangkan (PRD §4.2)
        foreach (['Reviewed By', 'Approved By', 'Received & Checked'] as $absent) {
            $this->assertStringNotContainsStringIgnoringCase($absent, $txt);
        }
        // Nomor NPR & nomor halaman di setiap halaman
        $pages = array_values(array_filter(preg_split("/\f/", $txt), static fn ($pg) => trim($pg) !== ''));
        $this->assertGreaterThanOrEqual(2, count($pages));
        foreach ($pages as $i => $page) {
            $this->assertStringContainsString('No. NPR: 001/PIK/NPR/X/2026', $page, 'halaman ' . ($i + 1));
            $this->assertMatchesRegularExpression('/Halaman ' . ($i + 1) . ' dari ' . count($pages) . '/', $page);
        }
        $this->assertStringNotContainsString('BELUM SELESAI FEEDBACK', $txt);
    }

    public function testPreviewHasWatermarkAndHidesDraftFeedbackFromSales(): void
    {
        $sales = $this->makeUser('admin_sales');
        $npd = $this->makeUser('npd_staff');
        [$id, $parts] = $this->submittedNpr($sales, $this->makeCustomer(), [['body', 'new_mold']]);
        (new \App\Npr\NprFeedbackService())->save($npd, $id, [$parts[0] => ['feedback_text' => 'RAHASIA DRAFT NPD']]);
        $salesPdf = (new NprPdf())->build($sales, $id);
        $this->assertFalse($salesPdf['final']);
        $txt = $this->text($salesPdf['content']);
        $this->assertStringContainsString('PRATINJAU — BELUM SELESAI FEEDBACK', $txt);
        $this->assertStringNotContainsString('RAHASIA DRAFT NPD', $txt, 'draft feedback tidak boleh terlihat Sales');
        $npdTxt = $this->text((new NprPdf())->build($npd, $id)['content']);
        $this->assertStringContainsString('RAHASIA DRAFT NPD', $npdTxt);
    }

    public function testPdfAlwaysIndonesianEvenForEnglishUser(): void
    {
        $sales = $this->makeUser('admin_sales', ['language' => 'en']);
        [$id] = $this->submittedNpr($sales, $this->makeCustomer(), [['body', 'new_mold']]);
        \App\Core\I18n::setLocale('en');
        $txt = $this->text((new NprPdf())->build(\App\Core\User::find($sales->id), $id)['content']);
        $this->assertStringContainsString('Nama Produk / Project', $txt);
        $this->assertStringContainsString('Halaman 1 dari', $txt);
    }
}
