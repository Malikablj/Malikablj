<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\AuthorizationException;
use App\Core\BusinessRuleException;
use App\Core\Clock;
use App\Core\ConflictException;
use App\Core\Db;
use App\Core\User;
use App\Core\ValidationException;
use App\Npr\NprFeedbackService;
use App\Npr\NprService;
use Tests\Support\DbTestCase;

/** PRD §4 — NPR digital, UAT-01..03, UAT-05, FR-NPR-01..11. */
final class NprServiceTest extends DbTestCase
{
    private NprService $npr;
    private NprFeedbackService $fb;
    private User $sales;
    private User $npd;
    private int $customerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->npr = new NprService();
        $this->fb = new NprFeedbackService($this->npr);
        $this->sales = $this->makeUser('admin_sales');
        $this->npd = $this->makeUser('npd_staff');
        $this->customerId = Db::insert('customers', ['code' => 'CUST' . random_int(100, 999), 'name' => 'PT Sanitya Utama', 'invoice_address' => 'Jl. A', 'shipping_address' => 'Jl. B', 'phone' => '021-1']);
        Clock::freeze('2026-10-07 09:00:00');
    }

    /** @return array<string,mixed> isian biru lengkap */
    private function header(): array
    {
        return [
            'product_name' => 'Botol Lotion 250ml',
            'request_types' => ['produk_baru'],
            'customer_id' => (string) $this->customerId,
            'invoice_address' => 'Jl. Invoice 1, Jakarta',
            'shipping_address' => 'Jl. Gudang 2, Bekasi',
            'phone' => '0812-0000-1111',
            'product_applications' => ['kosmetik'],
            'product_contents' => ['cair'],
            'net_volume_ml' => '250',
            'qty_per_month' => '50000',
            'qty_per_year' => '600000',
            'packaging' => ['box'],
            'regulation_compliance' => 'no',
            'attach_sample' => 'ada',
            'attach_technical_drawing' => 'tidak_ada',
            'attach_mockup' => 'tidak_ada',
            'launching_target' => '2027-03-31',
            'test_methods' => ['leaking_test' => ['checked' => '1', 'value' => '2', 'unit' => 'Kg/Cm²']],
        ];
    }

    /** @return array<string,mixed> */
    private function partSpec(string $name, string $type, string $dev = 'new_mould', ?string $custom = null): array
    {
        return [
            'part_name_code' => $name,
            'part_name_custom' => $custom,
            'part_type' => $type,
            'development_type' => $dev,
            'mold_supplier' => $dev === 'existing_mould' ? 'Supplier X' : '',
            'resin_code' => 'hdpe',
            'color_code' => 'opaque',
            'pantone' => 'PMS 286C',
            'surface_code' => 'glossy',
        ];
    }

    /** NPR 3 part: Body New Mold, Cap Subcont, part "Lainnya" (UAT-01). @return array{0:int,1:list<int>} */
    private function filledNpr(): array
    {
        $id = $this->npr->createDraft($this->sales);
        $parts = $this->npr->activeParts($id);
        $p2 = $this->npr->addPart($this->sales, $id);
        $p3 = $this->npr->addPart($this->sales, $id);
        $this->npr->saveSales($this->sales, $id, [
            'npr' => $this->header(),
            'parts' => [
                $parts[0]['id'] => $this->partSpec('body', 'new_mold'),
                $p2 => $this->partSpec('cap', 'subcont', 'existing_mould'),
                $p3 => $this->partSpec('__other', 'new_mold', 'new_mould', 'Plug Khusus'),
            ],
        ]);
        return [$id, [(int) $parts[0]['id'], $p2, $p3]];
    }

    /** @return array<string,mixed> isian pink lengkap */
    private function feedback(string $decision, bool $mould = true): array
    {
        return array_filter([
            'weight_gr' => '22.5',
            'mould_method_code' => $mould ? 'extrusion_blow' : null,
            'cavity' => $mould ? '4' : null,
            'mould_price_pik_pct' => $mould ? '60' : null,
            'mould_price_cust_pct' => $mould ? '40' : null,
            'mould_lead_time_days' => $mould ? '45' : null,
            'needs_new_masterbatch' => '1',
            'feedback_text' => 'Catatan NPD ' . $decision,
            'decision' => $decision,
            'decision_reason' => $decision === 'not_feasible' ? 'Geometri tidak memungkinkan' : null,
        ], static fn ($v) => $v !== null);
    }

    public function testUat01SubmitGeneratesNumberLocksBlueAndNotifiesNpd(): void
    {
        [$id] = $this->filledNpr();
        $res = $this->npr->submit($this->sales, $id);
        $this->assertSame('001/PIK/NPR/X/2026', $res['npr_number']);
        $this->assertMatchesRegularExpression('/^NPD-2026-\d{3}$/', $res['project_code']);
        $npr = $this->npr->find($id);
        $this->assertSame('submitted', $npr['status']);
        $this->assertSame($this->sales->name, $npr['requested_by_name']);
        $this->assertSame('2026-10-07 09:00:00', $npr['requested_at']);
        // project + 3 part dibuat
        $project = Db::fetch('SELECT * FROM projects WHERE npr_id = ?', [$id]);
        $this->assertSame($res['project_code'], $project['code']);
        $this->assertSame('2027-03-31', $project['target_finish']);
        $this->assertSame(3, (int) Db::value('SELECT COUNT(*) FROM project_parts WHERE project_id = ?', [$project['id']]));
        $this->assertSame('Plug Khusus', Db::value("SELECT name FROM project_parts WHERE project_id = ? AND sort_order = 30", [$project['id']]));
        // kolom biru terkunci (Sales maupun Admin)
        $this->assertFalse($this->npr->abilities($this->sales, $npr)['edit_blue']);
        $this->assertFalse($this->npr->abilities($this->makeUser('admin'), $npr)['edit_blue']);
        // notifikasi web ke NPD
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'npr_submitted'", [$this->npd->id]));
        $this->assertSame(0, (int) Db::value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'npr_submitted'", [$this->sales->id]), 'pengirim tidak dinotifikasi');
    }

    public function testEmailQueuedWhenMailEnabled(): void
    {
        Db::execute("UPDATE application_settings SET setting_value = '1' WHERE setting_key = 'mail.enabled'");
        \App\Core\Settings::flush();
        [$id] = $this->filledNpr();
        $this->npr->submit($this->sales, $id);
        $row = Db::fetch("SELECT d.* FROM notification_deliveries d WHERE d.user_id = ? ORDER BY id DESC LIMIT 1", [$this->npd->id]);
        $this->assertNotNull($row, 'email npr_submitted masuk antrean');
        $this->assertSame('pending', $row['status']);
        $this->assertStringContainsString('001/PIK/NPR/X/2026', $row['subject']);
        $this->assertStringNotContainsString('<script', $row['body_html']);
    }

    public function testSubmitRequiresCompleteBlueFields(): void
    {
        $id = $this->npr->createDraft($this->sales);
        try {
            $this->npr->submit($this->sales, $id);
            $this->fail('NPR kosong tidak boleh dikirim');
        } catch (ValidationException $e) {
            $errors = $e->errors();
            foreach (['npr.product_name', 'npr.customer_id', 'npr.request_types', 'npr.product_applications', 'npr.net_volume_ml', 'npr.attach_sample'] as $k) {
                $this->assertArrayHasKey($k, $errors, $k);
            }
            $partId = $this->npr->activeParts($id)[0]['id'];
            $this->assertArrayHasKey('parts.' . $partId . '.part_type', $errors);
            $this->assertArrayHasKey('parts.' . $partId . '.resin_code', $errors);
        }
        $this->assertNull($this->npr->find($id)['npr_number'], 'nomor hanya dibuat saat kirim berhasil');
    }

    public function testExistingMouldRequiresSupplierAndOtherNeedsText(): void
    {
        $id = $this->npr->createDraft($this->sales);
        $pid = $this->npr->activeParts($id)[0]['id'];
        $spec = $this->partSpec('cap', 'subcont', 'existing_mould');
        $spec['mold_supplier'] = '';
        $h = $this->header();
        $h['request_types'] = ['lain_lain'];
        $this->npr->saveSales($this->sales, $id, ['npr' => $h, 'parts' => [$pid => $spec]]);
        try {
            $this->npr->submit($this->sales, $id);
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('parts.' . $pid . '.mold_supplier', $e->errors());
            $this->assertArrayHasKey('npr.request_type_other', $e->errors());
        }
    }

    public function testInvalidMasterCodeRejected(): void
    {
        $id = $this->npr->createDraft($this->sales);
        $this->expectException(ValidationException::class);
        $this->npr->saveSales($this->sales, $id, ['npr' => ['request_types' => ['bukan_kode']]]);
    }

    public function testUat02SalesCannotFillPinkAndNpdCannotFillBlueAfterSubmit(): void
    {
        [$id, $parts] = $this->filledNpr();
        // Sales mencoba kolom pink (sebelum & sesudah kirim)
        try {
            $this->fb->save($this->sales, $id, [$parts[0] => ['weight_gr' => '10']]);
            $this->fail('Sales tidak boleh mengisi kolom pink');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        $this->npr->submit($this->sales, $id);
        try {
            $this->fb->save($this->sales, $id, [$parts[0] => ['weight_gr' => '10']]);
            $this->fail('Sales tidak boleh mengisi kolom pink');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        // NPD mencoba kolom biru setelah kirim
        try {
            $this->npr->saveSales($this->npd, $id, ['npr' => ['product_name' => 'Diubah NPD']]);
            $this->fail('NPD tidak boleh mengisi kolom biru');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        // Sales pun tidak boleh mengubah kolom biru setelah kirim
        $this->expectException(AuthorizationException::class);
        $this->npr->saveSales($this->sales, $id, ['npr' => ['product_name' => 'Diubah Sales']]);
    }

    public function testOtherSalesCannotEditDraft(): void
    {
        $id = $this->npr->createDraft($this->sales);
        $other = $this->makeUser('admin_sales');
        $this->assertFalse($this->npr->canView($other, $this->npr->find($id)), 'draft orang lain tidak terlihat');
        $this->expectException(AuthorizationException::class);
        $this->npr->saveSales($other, $id, ['npr' => ['product_name' => 'X']]);
    }

    public function testManagementAndDrafterCannotCreateNpr(): void
    {
        foreach (['management', 'drafter', 'purchasing'] as $role) {
            try {
                $this->npr->createDraft($this->makeUser($role));
                $this->fail($role);
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testNpdCreatesOnBehalfOfSalesPic(): void
    {
        try {
            $this->npr->createDraft($this->npd);
            $this->fail('NPD wajib memilih Sales PIC');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('sales_pic_id', $e->errors());
        }
        $id = $this->npr->createDraft($this->npd, $this->sales->id);
        $npr = $this->npr->find($id);
        $this->assertSame($this->sales->id, (int) $npr['sales_pic_id']);
        $this->assertTrue($this->npr->abilities($this->npd, $npr)['edit_blue'], 'NPD pembuat boleh mengisi draft-nya (OQ-02)');
        $this->assertTrue($this->npr->abilities($this->sales, $npr)['edit_blue'], 'Sales PIC boleh melanjutkan draft');
    }

    public function testUat03FeedbackPerPartCancelsNotFeasibleOnly(): void
    {
        [$id, $parts] = $this->filledNpr();
        $this->npr->submit($this->sales, $id);
        $this->fb->save($this->npd, $id, [
            $parts[0] => $this->feedback('feasible'),
            $parts[1] => $this->feedback('feasible_with_notes', false),
            $parts[2] => $this->feedback('not_feasible', false),
        ]);
        // draft feedback belum dipublikasikan
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM npr_feedback WHERE published_at IS NOT NULL AND npr_part_id IN (?, ?, ?)', $parts));
        $res = $this->fb->complete($this->npd, $id);
        $this->assertSame('feedback_completed', $res['result']);
        $this->assertSame([$parts[0], $parts[1]], $res['accepted']);
        $this->assertSame([$parts[2]], $res['cancelled']);
        $this->assertFalse($res['project_cancelled']);
        $npr = $this->npr->find($id);
        $this->assertSame('feedback_completed', $npr['status']);
        $this->assertSame($this->npd->name, $npr['received_by_name']);
        $this->assertSame('cancelled', Db::value('SELECT status FROM npr_parts WHERE id = ?', [$parts[2]]));
        $this->assertSame('cancelled', Db::value('SELECT status FROM project_parts WHERE npr_part_id = ?', [$parts[2]]));
        $this->assertNotNull(Db::value('SELECT accepted_at FROM project_parts WHERE npr_part_id = ?', [$parts[0]]));
        $this->assertNotSame('cancelled', Db::value('SELECT p.status FROM projects p WHERE p.npr_id = ?', [$id]));
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'npr_feedback_completed'", [$this->sales->id]));
    }

    public function testFeedbackCompletenessAndPercentRule(): void
    {
        [$id, $parts] = $this->filledNpr();
        $this->npr->submit($this->sales, $id);
        // FR-NPR-09: % PIK + % customer harus 100
        try {
            $this->fb->save($this->npd, $id, [$parts[0] => ['mould_price_pik_pct' => '70', 'mould_price_cust_pct' => '40']]);
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('feedback.' . $parts[0] . '.mould_price_cust_pct', $e->errors());
        }
        $this->fb->save($this->npd, $id, [$parts[0] => ['decision' => 'feasible', 'feedback_text' => 'ok']]);
        try {
            $this->fb->complete($this->npd, $id);
            $this->fail();
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $this->assertArrayHasKey('feedback.' . $parts[0] . '.weight_gr', $errors);
            $this->assertArrayHasKey('feedback.' . $parts[0] . '.cavity', $errors, 'mould wajib untuk part mould baru');
            $this->assertArrayHasKey('feedback.' . $parts[0] . '.needs_new_masterbatch', $errors);
            $this->assertArrayHasKey('feedback.' . $parts[1] . '.decision', $errors);
        }
    }

    public function testAllPartsNotFeasibleCancelsProject(): void
    {
        [$id, $parts] = $this->filledNpr();
        $this->npr->submit($this->sales, $id);
        $this->fb->save($this->npd, $id, array_fill_keys($parts, $this->feedback('not_feasible', false)));
        $res = $this->fb->complete($this->npd, $id);
        $this->assertTrue($res['project_cancelled']);
        $this->assertSame('cancelled', Db::value('SELECT status FROM projects WHERE npr_id = ?', [$id]));
    }

    public function testUat05ReturnResubmitRecordsRevisionHistoryWithoutRevisionNumber(): void
    {
        [$id, $parts] = $this->filledNpr();
        $first = $this->npr->submit($this->sales, $id);
        $this->fb->save($this->npd, $id, [$parts[1] => $this->feedback('feasible', false)]);
        try {
            $this->fb->returnToSales($this->npd, $id, '');
            $this->fail('alasan wajib');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
        $this->fb->returnToSales($this->npd, $id, 'Warna body perlu dikonfirmasi');
        $npr = $this->npr->find($id);
        $this->assertSame('returned', $npr['status']);
        $this->assertSame('Warna body perlu dikonfirmasi', $npr['return_reason']);
        $this->assertTrue($this->npr->abilities($this->sales, $npr)['edit_blue'], 'kolom biru terbuka untuk Sales PIC');
        $this->assertFalse($this->npr->abilities($this->npd, $npr)['edit_blue']);
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'npr_returned'", [$this->sales->id]));

        $spec = $this->partSpec('body', 'new_mold');
        $spec['color_code'] = 'translucent';
        $this->npr->saveSales($this->sales, $id, ['parts' => [$parts[0] => $spec]]);
        Clock::freeze('2026-10-09 10:00:00');
        $second = $this->npr->submit($this->sales, $id);
        $this->assertTrue($second['resubmitted']);
        $this->assertSame($first['npr_number'], $second['npr_number'], 'nomor NPR tetap (tanpa nomor revisi)');
        $this->assertSame($first['project_code'], $second['project_code']);
        $rev = Db::fetch("SELECT * FROM revision_history WHERE npr_id = ? AND revision_type = 'npr_resubmit'", [$id]);
        $details = json_decode($rev['details_json'], true);
        $this->assertSame('color_code', $details['diff']['parts'][(string) $parts[0]]['fields'][0]['field']);
        $this->assertSame('opaque', $details['diff']['parts'][(string) $parts[0]]['fields'][0]['old']);
        $this->assertSame('translucent', $details['diff']['parts'][(string) $parts[0]]['fields'][0]['new']);
        // hanya part yang diubah ditandai Perlu ditinjau ulang; keputusan part lain tetap
        $this->assertSame(1, (int) Db::value('SELECT needs_review FROM npr_parts WHERE id = ?', [$parts[0]]));
        $this->assertSame(0, (int) Db::value('SELECT needs_review FROM npr_parts WHERE id = ?', [$parts[1]]));
        $this->assertSame('feasible', Db::value('SELECT decision FROM npr_feedback WHERE npr_part_id = ?', [$parts[1]]));
        $types = Db::column('SELECT revision_type FROM revision_history WHERE npr_id = ? ORDER BY id', [$id]);
        $this->assertSame(['npr_submit', 'npr_return', 'npr_resubmit'], $types);
    }

    public function testNeedsRevisionDecisionReturnsNprOnComplete(): void
    {
        [$id, $parts] = $this->filledNpr();
        $this->npr->submit($this->sales, $id);
        $this->fb->save($this->npd, $id, [
            $parts[0] => $this->feedback('feasible'),
            $parts[1] => ['decision' => 'needs_revision', 'feedback_text' => 'Supplier cap belum jelas'],
            $parts[2] => $this->feedback('feasible'),
        ]);
        $res = $this->fb->complete($this->npd, $id);
        $this->assertSame('returned', $res['result']);
        $npr = $this->npr->find($id);
        $this->assertSame('returned', $npr['status']);
        $this->assertStringContainsString('Supplier cap belum jelas', $npr['return_reason']);
        // keputusan part lain tetap tersimpan (belum dipublikasikan)
        $this->assertSame('feasible', Db::value('SELECT decision FROM npr_feedback WHERE npr_part_id = ?', [$parts[0]]));
        // kirim ulang tanpa perubahan → part needs_revision direset
        $this->npr->submit($this->sales, $id);
        $this->assertNull(Db::value('SELECT decision FROM npr_feedback WHERE npr_part_id = ?', [$parts[1]]));
        $this->assertSame('feasible', Db::value('SELECT decision FROM npr_feedback WHERE npr_part_id = ?', [$parts[0]]));
    }

    public function testRemovePartRulesAndCancel(): void
    {
        [$id, $parts] = $this->filledNpr();
        $this->npr->removePart($this->sales, $id, $parts[2]); // draft: hapus bebas
        $this->assertSame(2, count($this->npr->activeParts($id)));
        $this->npr->submit($this->sales, $id);
        $this->fb->save($this->npd, $id, [$parts[1] => ['feedback_text' => 'mulai dinilai']]);
        try {
            $this->npr->removePart($this->npd, $id, $parts[1], 'tidak jadi');
            $this->fail('part dengan feedback tidak boleh dihapus');
        } catch (BusinessRuleException) {
            $this->addToAssertionCount(1);
        }
        $this->npr->cancelPart($this->npd, $id, $parts[1], 'Customer membatalkan cap');
        $this->assertSame('cancelled', Db::value('SELECT status FROM npr_parts WHERE id = ?', [$parts[1]]));
        $this->assertSame('cancelled', Db::value('SELECT status FROM project_parts WHERE npr_part_id = ?', [$parts[1]]));
        $this->assertSame('Customer membatalkan cap', Db::value("SELECT reason FROM audit_logs WHERE action = 'npr.part_cancel' ORDER BY id DESC LIMIT 1"));
    }

    public function testNpdCanAddPartAfterSubmitWithReason(): void
    {
        [$id] = $this->filledNpr();
        $this->npr->submit($this->sales, $id);
        try {
            $this->npr->addPart($this->npd, $id, $this->partSpec('spatula', 'subcont', 'new_mould'));
            $this->fail('alasan wajib');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
        }
        $pid = $this->npr->addPart($this->npd, $id, $this->partSpec('spatula', 'subcont', 'new_mould'), 'Tambahan spatula dari customer');
        $this->assertSame('Spatula', Db::value('SELECT name FROM project_parts WHERE npr_part_id = ?', [$pid]));
        $this->expectException(AuthorizationException::class);
        $this->npr->addPart($this->sales, $id, $this->partSpec('plug', 'subcont'), 'x');
    }

    public function testOptimisticLockDetectsConcurrentEdit(): void
    {
        $id = $this->npr->createDraft($this->sales);
        $v = (int) $this->npr->find($id)['lock_version'];
        $this->npr->saveSales($this->sales, $id, ['npr' => ['product_name' => 'A']], $v);
        $this->expectException(ConflictException::class);
        $this->npr->saveSales($this->sales, $id, ['npr' => ['product_name' => 'B']], $v);
    }

    public function testAdminCorrectionAfterCompletionRequiresReason(): void
    {
        [$id, $parts] = $this->filledNpr();
        $this->npr->submit($this->sales, $id);
        $this->fb->save($this->npd, $id, array_fill_keys($parts, $this->feedback('feasible')));
        $this->fb->complete($this->npd, $id);
        $admin = $this->makeUser('admin');
        try {
            $this->npr->saveSales($admin, $id, ['npr' => ['product_name' => 'Botol Lotion 300ml']]);
            $this->fail('alasan wajib');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
        $this->npr->saveSales($admin, $id, ['npr' => ['product_name' => 'Botol Lotion 300ml']], null, 'Ralat volume dari customer');
        $this->assertSame('Botol Lotion 300ml', Db::value('SELECT name FROM projects WHERE npr_id = ?', [$id]));
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM revision_history WHERE npr_id = ? AND revision_type = 'npr_change'", [$id]));
        // feedback setelah selesai: NPD boleh ubah dengan alasan, keputusan terkunci
        try {
            $this->fb->save($this->npd, $id, [$parts[0] => ['decision' => 'not_feasible']], null, 'x');
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('feedback.' . $parts[0] . '.decision', $e->errors());
        }
        $this->fb->save($this->npd, $id, [$parts[0] => ['weight_gr' => '23']], null, 'Berat aktual sampel');
        $this->assertSame('23.00', Db::value('SELECT weight_gr FROM npr_feedback WHERE npr_part_id = ?', [$parts[0]]));
        // Sales tidak boleh mengoreksi setelah selesai
        $this->expectException(AuthorizationException::class);
        $this->npr->saveSales($this->sales, $id, ['npr' => ['product_name' => 'X']], null, 'x');
    }

    public function testDiscardDraft(): void
    {
        $id = $this->npr->createDraft($this->sales);
        $this->npr->discard($this->sales, $id);
        $this->assertSame('discarded', $this->npr->find($id)['status']);
        $this->assertSame([], array_filter($this->npr->list($this->sales), static fn ($r) => (int) $r['id'] === $id));
    }

    public function testAutosaveDoesNotFloodAudit(): void
    {
        $id = $this->npr->createDraft($this->sales);
        $before = (int) Db::value('SELECT COUNT(*) FROM audit_logs');
        for ($i = 0; $i < 5; $i++) {
            $this->npr->saveSales($this->sales, $id, ['npr' => ['product_name' => 'Ketik ' . $i]], null, null, true);
        }
        $this->assertSame($before, (int) Db::value('SELECT COUNT(*) FROM audit_logs'));
        $this->assertSame('Ketik 4', $this->npr->find($id)['product_name']);
    }
}
