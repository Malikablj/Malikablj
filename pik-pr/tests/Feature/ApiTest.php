<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class ApiTest extends TestCase
{
    public function test_full_workflow_through_json_api(): void
    {
        $this->loginAs('mitha@pik.local');

        $created = $this->json('POST', '/api/pr', $this->prInput(['tax_rate' => '11']));
        self::assertSame(201, $created->status);
        $data = json_decode($created->body, true)['data'];
        self::assertSame('draft', $data['status']);
        self::assertSame('310800.00', $data['grand_total']);
        self::assertCount(2, $data['items']);
        $id = $data['id'];

        $input = $this->prInput(['tax_rate' => '0']);
        $updated = json_decode($this->json('PUT', '/api/pr/' . $id, $input)->body, true)['data'];
        self::assertSame('280000.00', $updated['grand_total']);

        $submitted = json_decode($this->json('POST', '/api/pr/' . $id . '/submit')->body, true)['data'];
        self::assertSame('submitted', $submitted['status']);
        self::assertNotNull($submitted['pr_number']);
        $this->logout();

        $this->loginAs('budi@pik.local');
        $step1 = json_decode($this->json('POST', '/api/pr/' . $id . '/approve', ['comment' => 'OK'])->body, true)['data'];
        self::assertSame('in_review', $step1['status']);
        $this->logout();

        $this->loginAs('hendra@pik.local');
        $step2 = json_decode($this->json('POST', '/api/pr/' . $id . '/approve')->body, true)['data'];
        self::assertSame('approved', $step2['status']);
        self::assertCount(2, $step2['approval_history']);

        $pdf = $this->json('GET', '/api/pr/' . $id . '/pdf');
        self::assertSame(200, $pdf->status);
        self::assertStringStartsWith('%PDF-', $pdf->body);
    }

    public function test_api_validation_errors_are_json(): void
    {
        $this->loginAs('mitha@pik.local');
        $response = $this->json('POST', '/api/pr', $this->prInput(['items' => [['name' => '', 'quantity' => 'x', 'unit_price' => '1']]]));

        self::assertSame(422, $response->status);
        $body = json_decode($response->body, true);
        self::assertArrayHasKey('items.0.name', $body['errors']);
        self::assertArrayHasKey('items.0.quantity', $body['errors']);
    }

    public function test_api_reject_and_revision_require_comment(): void
    {
        $prId = (int) $this->scalar("SELECT id FROM purchase_requisitions WHERE status = 'submitted' AND pr_number LIKE '%PDPR%' LIMIT 1");
        $this->loginAs('budi@pik.local');

        self::assertSame(422, $this->json('POST', '/api/pr/' . $prId . '/reject')->status);
        self::assertSame(422, $this->json('POST', '/api/pr/' . $prId . '/revision')->status);

        $revision = $this->json('POST', '/api/pr/' . $prId . '/revision', ['comment' => 'Lengkapi spesifikasi']);
        self::assertSame(200, $revision->status);
        self::assertSame('revision_required', json_decode($revision->body, true)['data']['status']);
    }

    public function test_create_and_submit_in_one_request(): void
    {
        $this->loginAs('mitha@pik.local');
        $response = $this->json('POST', '/api/pr', $this->prInput() + ['submit' => true]);

        self::assertSame(201, $response->status);
        self::assertSame('submitted', json_decode($response->body, true)['data']['status']);
    }
}
