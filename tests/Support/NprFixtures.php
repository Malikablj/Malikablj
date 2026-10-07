<?php
declare(strict_types=1);

namespace Tests\Support;

use App\Core\Db;
use App\Core\User;
use App\Npr\NprFeedbackService;
use App\Npr\NprService;

/** Fixture NPR lengkap untuk test integrasi (dipakai NPR, project, workflow). */
trait NprFixtures
{
    protected function makeCustomer(): int
    {
        return Db::insert('customers', ['code' => 'C' . random_int(10000, 99999), 'name' => 'PT Sanitya Utama', 'invoice_address' => 'Jl. A', 'shipping_address' => 'Jl. B', 'phone' => '021-1']);
    }

    /** @return array<string,mixed> */
    protected function nprHeader(int $customerId): array
    {
        return [
            'product_name' => 'Botol Lotion 250ml', 'request_types' => ['produk_baru'], 'customer_id' => (string) $customerId,
            'invoice_address' => 'Jl. Invoice 1, Jakarta', 'shipping_address' => 'Jl. Gudang 2, Bekasi', 'phone' => '0812-0000-1111',
            'product_applications' => ['kosmetik'], 'product_contents' => ['cair'], 'net_volume_ml' => '250',
            'qty_per_month' => '50000', 'qty_per_year' => '600000', 'packaging' => ['box'], 'regulation_compliance' => 'no',
            'attach_sample' => 'ada', 'attach_technical_drawing' => 'tidak_ada', 'attach_mockup' => 'tidak_ada', 'launching_target' => '2027-03-31',
            'test_methods' => ['leaking_test' => ['checked' => '1', 'value' => '2', 'unit' => 'Kg/Cm²']],
        ];
    }

    /** @return array<string,mixed> */
    protected function nprPart(string $name, string $type, string $dev = 'new_mould', ?string $custom = null): array
    {
        return [
            'part_name_code' => $name, 'part_name_custom' => $custom, 'part_type' => $type, 'development_type' => $dev,
            'mold_supplier' => $dev === 'existing_mould' ? 'Supplier X' : '', 'resin_code' => 'hdpe', 'color_code' => 'opaque',
            'pantone' => 'PMS 286C', 'surface_code' => 'glossy',
        ];
    }

    /** @return array<string,mixed> */
    protected function nprFeedback(string $decision, bool $mould = true, bool $masterbatch = true): array
    {
        return array_filter([
            'weight_gr' => '22.5', 'mould_method_code' => $mould ? 'extrusion_blow' : null, 'cavity' => $mould ? '4' : null,
            'mould_price_pik_pct' => $mould ? '60' : null, 'mould_price_cust_pct' => $mould ? '40' : null, 'mould_lead_time_days' => $mould ? '45' : null,
            'needs_new_masterbatch' => $masterbatch ? '1' : '0', 'feedback_text' => 'Catatan NPD ' . $decision, 'decision' => $decision,
            'decision_reason' => $decision === 'not_feasible' ? 'Geometri tidak memungkinkan' : null,
        ], static fn ($v) => $v !== null);
    }

    /**
     * NPR terkirim dengan part sesuai spesifikasi [[name, type, dev, custom], ...].
     * @param list<array{0:string,1:string,2?:string,3?:string|null}> $specs
     * @return array{0:int,1:list<int>}
     */
    protected function submittedNpr(User $sales, int $customerId, array $specs): array
    {
        $svc = new NprService();
        $id = $svc->createDraft($sales);
        $ids = [(int) $svc->activeParts($id)[0]['id']];
        for ($i = 1; $i < count($specs); $i++) {
            $ids[] = $svc->addPart($sales, $id);
        }
        $parts = [];
        foreach ($specs as $i => $s) {
            $parts[$ids[$i]] = $this->nprPart($s[0], $s[1], $s[2] ?? 'new_mould', $s[3] ?? null);
        }
        $svc->saveSales($sales, $id, ['npr' => $this->nprHeader($customerId), 'parts' => $parts]);
        $svc->submit($sales, $id);
        return [$id, $ids];
    }

    /**
     * NPR dengan feedback selesai. $decisions = [partIndex => decision].
     * @param list<array{0:string,1:string,2?:string,3?:string|null}> $specs
     * @param list<string> $decisions
     * @return array{0:int,1:list<int>,2:int} [nprId, nprPartIds, projectId]
     */
    protected function completedNpr(User $sales, User $npd, int $customerId, array $specs, array $decisions, bool $masterbatch = true): array
    {
        [$id, $ids] = $this->submittedNpr($sales, $customerId, $specs);
        $fb = [];
        foreach ($ids as $i => $pid) {
            $fb[$pid] = $this->nprFeedback($decisions[$i] ?? 'feasible', true, $masterbatch);
        }
        $service = new NprFeedbackService();
        $service->save($npd, $id, $fb);
        $service->complete($npd, $id);
        $projectId = (int) Db::value('SELECT id FROM projects WHERE npr_id = ?', [$id]);
        return [$id, $ids, $projectId];
    }
}
