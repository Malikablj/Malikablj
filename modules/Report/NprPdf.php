<?php
declare(strict_types=1);

namespace App\Report;

use App\Core\Gate;
use App\Core\I18n;
use App\Core\Settings;
use App\Core\User;
use App\Document\DocumentStorage;
use App\Document\UploadValidator;
use App\Master\MasterService;
use App\Npr\NprFields;
use App\Npr\NprService;

/**
 * PDF NPR resmi meniru PIK-FORM-NPD-01 rev 00 (PRD §4.6): A4 portrait, kop perusahaan, pita hitam,
 * kotak centang, warna biru (Sales) & pink (Admin NPD), seluruh data NPR + feedback per part,
 * Requested by & Received by (tanpa blok tanda tangan), nomor halaman & No. NPR di setiap halaman.
 * Bahasa selalu Indonesia (bahasa form resmi). Sebelum Selesai Feedback: watermark pratinjau.
 */
final class NprPdf
{
    private const L = 'id';
    private const BLUE = '#DCE9F7';
    private const PINK = '#FADBE6';

    public function __construct(private NprService $npr = new NprService())
    {
    }

    /** @return array{filename:string,content:string,final:bool} */
    public function build(User $viewer, int $nprId): array
    {
        $npr = $this->npr->load($nprId);
        $this->npr->assertView($viewer, $npr);
        $final = $npr['status'] === 'feedback_completed';
        $showDraftFeedback = Gate::can($viewer, 'npr.edit_npd_fields');

        $mpdf = PdfFactory::make('P');
        $mpdf->SetTitle('NPR ' . ($npr['npr_number'] ?? ''));
        $number = $npr['npr_number'] ?: '—';
        $mpdf->SetHTMLHeader($this->header($number, $final));
        $mpdf->SetHTMLFooter('<table width="100%" style="font-size:7pt;color:#555"><tr><td>No. NPR: ' . $this->h($number) . ' · PIK-FORM-NPD-01 Rev 00</td><td align="right">' . $this->h(I18n::t('npr.pdf_page', [], self::L)) . '</td></tr></table>');
        if (!$final) {
            $mpdf->SetWatermarkText(I18n::t('npr.pdf_watermark', [], self::L), 0.08);
            $mpdf->showWatermarkText = true;
        }
        $mpdf->WriteHTML($this->css(), \Mpdf\HTMLParserMode::HEADER_CSS);
        $mpdf->WriteHTML($this->body($npr, $showDraftFeedback), \Mpdf\HTMLParserMode::HTML_BODY);

        $filename = PdfFactory::safeFilename('NPR_' . ($npr['npr_number'] ?: 'DRAFT-' . $nprId) . '_' . $npr['product_name']) . '.pdf';
        return ['filename' => $filename, 'content' => $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN), 'final' => $final];
    }

    private function header(string $number, bool $final = true): string
    {
        $preview = $final ? '' : '<div style="color:#B00020;font-weight:bold;font-size:7.5pt;text-align:center;border:0.6px solid #B00020;padding:1px">'
            . $this->h(I18n::t('npr.pdf_preview_banner', [], self::L)) . '</div>';
        $company = $this->h((string) Settings::get('company.name', 'PT. Permata Indo Kemas'));
        $dept = $this->h((string) Settings::get('company.department', 'New Product and Development Dept'));
        return '<table class="kop" width="100%"><tr>'
            . '<td width="18%"><img src="' . $this->h(PdfFactory::logoPath()) . '" height="36"></td>'
            . '<td width="50%"><div class="co">' . $company . '</div><div class="dept">' . $dept . '</div><div class="title">NEW PROJECT REQUEST</div></td>'
            . '<td width="32%"><table class="docno"><tr><td>No. Dokumen</td><td>: PIK-FORM-NPD-01</td></tr><tr><td>No. Revisi</td><td>: 00</td></tr><tr><td>No. NPR</td><td>: ' . $this->h($number) . '</td></tr></table></td>'
            . '</tr></table>' . $preview;
    }

    private function css(): string
    {
        return 'body{font-family:dejavusans;color:#111}
        .kop td{vertical-align:middle}.co{font-weight:bold;font-size:10pt}.dept{font-size:8pt;color:#333}
        .title{margin-top:3px;font-weight:bold;font-size:11pt;letter-spacing:1px;border:1px solid #000;padding:2px 6px;display:inline-block}
        .docno{font-size:7.5pt}.docno td{padding:0 2px}
        .band{background:#000;color:#fff;font-weight:bold;font-size:8.5pt;padding:2.5px 6px;margin-top:7px;text-transform:uppercase}
        table.f{width:100%;border-collapse:collapse;margin-top:3px}
        table.f td,table.f th{border:0.6px solid #777;padding:3px 4px;vertical-align:top;font-size:8pt}
        table.f th{background:#eee;font-weight:bold;text-align:left}
        .lbl{width:28%;color:#222}.b{background:' . self::BLUE . '}.p{background:' . self::PINK . '}
        .cb{font-size:9pt}.legend span{padding:1px 6px;margin-right:6px;font-size:7pt;border:0.5px solid #999}
        .muted{color:#666}.small{font-size:7pt}.sig td{font-size:8pt}';
    }

    private function body(array $npr, bool $showDraftFeedback): string
    {
        $o = '';
        $o .= '<table class="legend small" cellspacing="4"><tr><td class="b" style="border:0.5px solid #999;padding:1px 6px">' . $this->h(I18n::t('npr.legend_sales', [], self::L))
            . '</td><td class="p" style="border:0.5px solid #999;padding:1px 6px">' . $this->h(I18n::t('npr.legend_npd', [], self::L)) . '</td></tr></table>';
        $o .= '<table class="f"><tr><td class="lbl">No</td><td>' . $this->h($npr['npr_number'] ?: '—') . '</td></tr>'
            . '<tr><td class="lbl">Nama Produk / Project</td><td class="b">' . $this->h($npr['product_name']) . '</td></tr>'
            . ($npr['project_code'] ? '<tr><td class="lbl">Kode Project</td><td>' . $this->h($npr['project_code']) . '</td></tr>' : '')
            . '</table>';

        // Jenis permintaan (form: STATUS PROJECT)
        $o .= $this->band('Status Project (Jenis Permintaan)');
        $o .= '<table class="f"><tr><td class="b">' . $this->checks('request_type', NprFields::decode($npr['request_types_json']))
            . ($npr['request_type_other'] ? ' — ' . $this->h($npr['request_type_other']) : '') . '</td></tr></table>';

        $activeParts = array_values(array_filter($npr['parts'], static fn ($p) => $p['status'] === 'active'));
        $allParts = $npr['parts'];

        // Development per part
        $o .= $this->band('Development yang Dibutuhkan');
        $o .= '<table class="f"><tr><th width="22%">Part</th><th>Development</th><th width="30%">Supplier Mold / Supplier Part</th></tr>';
        foreach ($allParts as $p) {
            $o .= '<tr><td>' . $this->partName($p) . '</td><td class="b">' . $this->checks('development_type', [$p['development_type']]) . '</td><td class="b">' . $this->h($p['mold_supplier'] ?: '') . '</td></tr>';
        }
        $o .= '</table><div class="small muted">*Sebutkan jika menggunakan mould existing</div>';

        // Data customer
        $o .= $this->band('Data Customer');
        $o .= '<table class="f">'
            . $this->row('Nama Customer', $npr['customer_name'] ?? '', 'b')
            . $this->row('Alamat pengiriman invoice', $npr['invoice_address'] ?? '', 'b')
            . $this->row('Alamat pengiriman barang', $npr['shipping_address'] ?? '', 'b')
            . $this->row('No. Telepon / Mobile Phone', $npr['phone'] ?? '', 'b')
            . '</table>';

        $o .= $this->band('Aplikasi Produk');
        $o .= '<table class="f"><tr><td class="b">' . $this->checks('product_application', NprFields::decode($npr['product_applications_json'])) . '</td></tr></table>';
        $o .= $this->band('Isi Produk');
        $o .= '<table class="f"><tr><td class="b">' . $this->checks('product_content', NprFields::decode($npr['product_contents_json']))
            . ($npr['product_content_other'] ? ' — ' . $this->h($npr['product_content_other']) : '') . '</td></tr></table>';
        $o .= $this->band('Berat Bersih / Netto Produk');
        $o .= '<table class="f"><tr><td class="b">' . $this->h($npr['net_volume_ml'] !== null ? I18n::number($npr['net_volume_ml'], 2, self::L) : '—') . ' ml</td><td class="b">'
            . $this->h($npr['net_weight_gr'] !== null ? I18n::number($npr['net_weight_gr'], 2, self::L) : '—') . ' gr</td></tr></table>';

        // Tabel komponen produk (biru) + berat & feedback (pink)
        $o .= $this->band('Komponen Produk');
        $o .= '<table class="f"><tr><th width="4%">No</th><th width="20%">Komponen Produk</th><th width="13%">Resin</th><th width="16%">Warna</th><th width="13%">Surface Mould / Finishing</th><th width="8%">Berat (gr)</th><th>Feedback</th></tr>';
        foreach ($allParts as $i => $p) {
            $fb = $p['published_at'] !== null || $showDraftFeedback;
            $neck = NprFields::isNeckPart($p);
            $comp = $this->partName($p) . '<br><span class="small">' . $this->h(I18n::t('part_type.' . ($p['part_type'] ?: 'new_mold'), [], self::L)) . '</span>'
                . ((int) $p['is_external_component'] === 1 ? '<br><span class="small">☑ Komponen Ext' . ($p['external_note'] ? ': ' . $this->h($p['external_note']) : '') . '</span>' : '')
                . ($p['status'] === 'cancelled' ? '<br><span class="small" style="color:#B00020">Dibatalkan: ' . $this->h($p['cancel_reason'] ?? '') . '</span>' : '');
            $resin = $neck ? $this->h(MasterService::label('neck_preform', $p['neck_preform_code'], self::L)) : $this->h(MasterService::label('resin', $p['resin_code'], self::L));
            $color = $neck ? '' : $this->h(MasterService::label('color', $p['color_code'], self::L)) . ($p['pantone'] ? '<br><span class="small">Pantone: ' . $this->h($p['pantone']) . '</span>' : '');
            $feedback = $fb ? ($p['decision'] ? '<b>' . $this->h(I18n::t('decision.' . $p['decision'], [], self::L)) . '</b><br>' : '') . nl2br($this->h($p['feedback_text'] ?? '')) . ($p['decision_reason'] ? '<br><i>' . $this->h($p['decision_reason']) . '</i>' : '') : '';
            $o .= '<tr><td>' . ($i + 1) . '</td><td class="b">' . $comp . '</td><td class="b">' . $resin . '</td><td class="b">' . $color . '</td><td class="b">'
                . ($neck ? '' : $this->h(MasterService::label('surface', $p['surface_code'], self::L))) . '</td><td class="p" align="right">'
                . ($fb && $p['weight_gr'] !== null ? $this->h(I18n::number($p['weight_gr'], 2, self::L)) : '') . '</td><td class="p">' . $feedback . '</td></tr>';
        }
        $o .= '</table>';

        // Decoration
        $o .= $this->band('Decoration');
        $o .= '<table class="f">'
            . '<tr><td class="lbl">' . $this->box((int) $npr['deco_printing'] === 1) . ' Printing</td><td class="b">Metode: ' . $this->h(MasterService::label('printing_method', $npr['deco_printing_method'], self::L) ?: '—')
            . ' · Komponen: ' . $this->h($npr['deco_printing_components'] ?: '—') . '<br>Varnish: ' . $this->h(MasterService::label('varnish', $npr['deco_varnish'], self::L) ?: '—') . ' · Komponen: ' . $this->h($npr['deco_varnish_components'] ?: '—') . '</td></tr>'
            . '<tr><td class="lbl">' . $this->box((int) $npr['deco_labelling'] === 1) . ' Labelling</td><td class="b">' . $this->checks('labelling_side', [$npr['deco_labelling_side']]) . ' · Komponen: ' . $this->h($npr['deco_labelling_components'] ?: '—') . '</td></tr>'
            . '<tr><td class="lbl">' . $this->box((int) $npr['deco_shrink'] === 1) . ' Shrink Labelled/Sealed</td><td class="b">Komponen: ' . $this->h($npr['deco_shrink_components'] ?: '—') . '</td></tr>'
            . '</table>';

        $o .= $this->band('Kebutuhan');
        $o .= '<table class="f"><tr><td class="b">' . $this->h($npr['qty_per_month'] !== null ? I18n::number($npr['qty_per_month'], 0, self::L) : '—') . ' per bulan</td><td class="b">'
            . $this->h($npr['qty_per_year'] !== null ? I18n::number($npr['qty_per_year'], 0, self::L) : '—') . ' per tahun</td></tr></table>';

        // Kebutuhan cetakan / mould per part (pink)
        $o .= $this->band('Kebutuhan Cetakan / Mould (per part)');
        $o .= '<table class="f"><tr><th width="18%">Part</th><th width="22%">Metode</th><th width="8%">Cavity</th><th width="10%">% PT. PIK</th><th width="10%">% Customer</th><th width="14%">Lead Time Mould</th><th>Masterbatch Baru</th></tr>';
        foreach ($activeParts as $p) {
            $fb = $p['published_at'] !== null || $showDraftFeedback;
            $method = $fb ? MasterService::label('mould_method', $p['mould_method_code'], self::L) . ($p['mould_method_other'] ? ' (' . $p['mould_method_other'] . ')' : '') : '';
            $o .= '<tr><td>' . $this->partName($p) . '</td><td class="p">' . $this->h($method) . '</td><td class="p">' . ($fb ? $this->h($p['cavity'] ?? '') : '')
                . '</td><td class="p">' . ($fb && $p['mould_price_pik_pct'] !== null ? $this->h(I18n::number($p['mould_price_pik_pct'], 0, self::L)) . '%' : '')
                . '</td><td class="p">' . ($fb && $p['mould_price_cust_pct'] !== null ? $this->h(I18n::number($p['mould_price_cust_pct'], 0, self::L)) . '%' : '')
                . '</td><td class="p">' . ($fb && $p['mould_lead_time_days'] !== null ? $this->h((string) $p['mould_lead_time_days']) . ' hari' : '') . ($fb && $p['mould_lead_time_note'] ? ' ' . $this->h($p['mould_lead_time_note']) : '')
                . '</td><td class="p">' . ($fb && $p['needs_new_masterbatch'] !== null ? ((int) $p['needs_new_masterbatch'] === 1 ? 'Ya' : 'Tidak') : '') . '</td></tr>';
        }
        $o .= '</table>';

        $o .= $this->band('Kemasan Produk');
        $o .= '<table class="f"><tr><td class="b">' . $this->checks('packaging', NprFields::decode($npr['packaging_json'])) . ($npr['packaging_other'] ? ' — ' . $this->h($npr['packaging_other']) : '') . '</td></tr></table>';

        $o .= $this->band('Metode Test');
        $tests = [];
        foreach (NprFields::decode($npr['test_methods_json']) as $tm) {
            $tests[$tm['code']] = $tm;
        }
        $o .= '<table class="f">';
        foreach (MasterService::options('test_method', true) as $tm) {
            $sel = $tests[$tm['code']] ?? null;
            if (!$sel && !$tm['is_active']) {
                continue;
            }
            $o .= '<tr><td class="lbl">' . $this->box($sel !== null) . ' ' . $this->h($tm['label_id']) . '</td><td class="b">' . ($sel && $sel['value'] !== '' ? $this->h($sel['value'] . ' ' . $sel['unit']) : '') . '</td></tr>';
        }
        $o .= '<tr><td class="lbl">' . $this->box((int) $npr['test_refer_spec_doc'] === 1) . ' Refer to Dokumen Spesifikasi Produk</td><td class="b">' . $this->h($npr['test_other'] ?? '') . '</td></tr></table>';

        $o .= $this->band('Kepatuhan terhadap Suatu Peraturan Perundangan / Regulasi');
        $o .= '<table class="f"><tr><td class="b">' . $this->box($npr['regulation_compliance'] === 'no') . ' Tidak &nbsp; ' . $this->box($npr['regulation_compliance'] === 'yes') . ' Ya'
            . ($npr['regulation_note'] ? '<br>Jika ya: ' . nl2br($this->h($npr['regulation_note'])) : '') . '</td></tr></table>';

        $o .= $this->band('Lampiran dari Customer');
        $o .= '<table class="f">';
        foreach (['attach_sample' => 'Sample Acuan', 'attach_technical_drawing' => 'Technical Drawing', 'attach_mockup' => 'Mock Up'] as $k => $label) {
            $o .= '<tr><td class="lbl">' . $label . '</td><td class="b">' . $this->box($npr[$k] === 'ada') . ' Ada &nbsp; ' . $this->box($npr[$k] === 'tidak_ada') . ' Tidak ada</td></tr>';
        }
        $o .= '</table>';
        foreach (['product_shape' => 'Contoh Bentuk Produk', 'spec_reference' => 'Referensi Spek'] as $cat => $label) {
            $files = array_values(array_filter($npr['attachments'], static fn ($a) => $a['npr_category'] === $cat));
            $o .= '<table class="f"><tr><th>' . $label . '</th></tr><tr><td class="b">';
            if (!$files) {
                $o .= '<span class="muted">—</span>';
            }
            foreach ($files as $f) {
                $doc = \App\Core\Db::fetch('SELECT stored_path FROM document_versions WHERE id = ?', [(int) $f['version_id']]);
                if ($doc && in_array($f['extension'], UploadValidator::IMAGE_EXT, true)) {
                    $path = DocumentStorage::absolute((string) $doc['stored_path']);
                    if (is_file($path)) {
                        $o .= '<img src="' . $this->h($path) . '" style="max-height:55mm;max-width:85mm;margin:2px 6px 2px 0"> ';
                    }
                }
                $o .= '<div class="small">' . $this->h($f['original_name']) . '</div>';
            }
            $o .= '</td></tr></table>';
        }

        // Requested by / Received by (tanpa blok tanda tangan — PRD §4.2)
        $o .= '<table class="f sig" style="margin-top:8px"><tr><th width="50%">Requested by</th><th>Received by</th></tr><tr>'
            . '<td>Nama: ' . $this->h($npr['requested_by_name'] ?? '—') . '<br>Jabatan: ' . $this->h($npr['requested_by_title'] ?? '—') . '<br>Tanggal: ' . $this->h($npr['requested_at'] ? I18n::date($npr['requested_at'], self::L) : '—') . '</td>'
            . '<td>Nama: ' . $this->h($npr['received_by_name'] ?? '—') . '<br>Jabatan: ' . $this->h($npr['received_by_title'] ?? '—') . '<br>Tanggal: ' . $this->h($npr['received_at'] ? I18n::date($npr['received_at'], self::L) : '—') . '</td>'
            . '</tr></table>';
        $o .= '<table class="f"><tr><td class="lbl">NOTE</td><td class="b">' . nl2br($this->h($npr['note'] ?? '')) . '</td></tr>'
            . '<tr><td class="lbl">Launching Target</td><td class="b">' . $this->h($npr['launching_target'] ? I18n::date($npr['launching_target'], self::L) : '—') . '</td></tr></table>';
        return $o;
    }

    private function band(string $title): string
    {
        return '<div class="band">' . $this->h($title) . '</div>';
    }

    private function row(string $label, string $value, string $cls): string
    {
        return '<tr><td class="lbl">' . $this->h($label) . '</td><td class="' . $cls . '">' . nl2br($this->h($value)) . '</td></tr>';
    }

    private function box(bool $checked): string
    {
        return '<span class="cb">' . ($checked ? '☑' : '☐') . '</span>';
    }

    /** Semua opsi master kategori dengan kotak centang (opsi nonaktif hanya bila dipilih). @param list<string|null> $selected */
    private function checks(string $category, array $selected): string
    {
        $parts = [];
        foreach (MasterService::options($category, true) as $opt) {
            $on = in_array($opt['code'], $selected, true);
            if (!$opt['is_active'] && !$on) {
                continue;
            }
            $parts[] = $this->box($on) . ' ' . $this->h($opt['label_id']);
        }
        return implode(' &nbsp; ', $parts);
    }

    private function partName(array $p): string
    {
        return $this->h(NprFields::partDisplayName($p, self::L) ?: '—');
    }

    private function h(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
