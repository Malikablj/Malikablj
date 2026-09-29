<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Auth;
use App\Helpers\Request;
use App\Helpers\Validator;
use App\Models\Customer;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\User;
use DomainException;

final class FollowUpController extends Controller
{
    private const FIELDS = ['customer_id', 'lead_id', 'pic_user_id', 'follow_up_date', 'follow_up_time', 'follow_up_type', 'purpose', 'status', 'result', 'next_follow_up', 'reminder', 'notes'];

    public function index(): void
    {
        $today = today();
        FollowUp::refreshOverdue($today);
        $tab = Request::queryString('tab', 'today');
        if (!isset(FollowUp::TABS[$tab])) {
            $tab = 'today';
        }
        $filters = [
            'q'           => Request::queryString('q'),
            'pic'         => Request::queryString('pic') === 'me' ? (int) Auth::id() : Request::queryInt('pic'),
            'customer_id' => Request::queryInt('customer_id'),
            'type'        => Request::queryString('type'),
        ];
        $this->view('followups/index', [
            'title'     => 'Follow Up',
            'tab'       => $tab,
            'counts'    => FollowUp::tabCounts($today, $filters),
            'followups' => FollowUp::paginate($tab, $today, $filters, $this->page()),
            'filters'   => $filters,
            'pics'      => User::picOptions(),
            'today'     => $today,
        ]);
    }

    public function create(): void
    {
        $preset = [
            'customer_id'    => Request::queryInt('customer_id') ?: null,
            'lead_id'        => Request::queryInt('lead_id') ?: null,
            'pic_user_id'    => Auth::id(),
            'follow_up_date' => date('Y-m-d', strtotime('+1 day')),
            'follow_up_type' => 'WhatsApp',
            'status'         => 'Planned',
            'reminder'       => '1',
        ];
        $this->view('followups/form', $this->formData(null) + ['errors' => [], 'preset' => $preset]);
    }

    public function store(): void
    {
        $v = $this->validate();
        if ($v->fails()) {
            $this->invalid('followups/form', $this->formData(null) + ['preset' => []], $v->errors(), $this->old());
            return;
        }
        $data = ActivityController::fillCustomerFromLead($v->validated());
        FollowUp::saveFollowUp(null, $data);
        $this->success('Follow up dijadwalkan untuk ' . fmt_date((string) $data['follow_up_date']) . '.', $this->returnTo('/follow-ups?tab=' . $this->tabFor((string) $data['follow_up_date'])));
    }

    public function edit(int $id): void
    {
        $fu = $this->found(FollowUp::findFull($id));
        if ($fu['follow_up_time'] !== null) {
            $fu['follow_up_time'] = substr((string) $fu['follow_up_time'], 0, 5);
        }
        $this->view('followups/form', $this->formData($fu) + ['errors' => [], 'preset' => []]);
    }

    public function update(int $id): void
    {
        $fu = $this->found(FollowUp::findFull($id));
        $v = $this->validate();
        if ($v->fails()) {
            $this->invalid('followups/form', $this->formData($fu) + ['preset' => []], $v->errors(), $this->old());
            return;
        }
        FollowUp::saveFollowUp($id, ActivityController::fillCustomerFromLead($v->validated()));
        $this->success('Follow up diperbarui.', $this->returnTo('/follow-ups'));
    }

    /** Tandai selesai (opsional: hasil & jadwal lanjutan). */
    public function done(int $id): void
    {
        $this->found(FollowUp::find($id));
        $v = Validator::make($_POST, [
            'result'         => 'nullable|string|max:5000',
            'next_follow_up' => 'nullable|date',
            'next_purpose'   => 'nullable|string|max:255',
        ], ['result' => 'Hasil', 'next_follow_up' => 'Tanggal lanjutan', 'next_purpose' => 'Tujuan lanjutan']);
        $data = $v->validated();
        if (!$v->fails() && $data['next_follow_up'] !== null && $data['next_follow_up'] < today()) {
            $v->addError('next_follow_up', 'Tanggal follow up lanjutan tidak boleh di masa lalu.');
        }
        if ($v->fails()) {
            $this->failure(implode(' ', $v->errors()), '/follow-ups/' . $id . '/edit');
        }
        try {
            $newId = FollowUp::markDone($id, $data['result'], $data['next_follow_up'], $data['next_purpose']);
        } catch (DomainException $e) {
            $this->failure($e->getMessage(), '/follow-ups/' . $id . '/edit');
        }
        $this->success('Follow up ditandai selesai.' . ($newId ? ' Follow up lanjutan dijadwalkan ' . fmt_date((string) $data['next_follow_up']) . '.' : ''), $this->returnTo('/follow-ups'));
    }

    public function reschedule(int $id): void
    {
        $this->found(FollowUp::find($id));
        $v = Validator::make($_POST, ['new_date' => 'required|date', 'note' => 'nullable|string|max:500'], ['new_date' => 'Tanggal baru', 'note' => 'Catatan']);
        $data = $v->validated();
        if (!$v->fails() && $data['new_date'] < today()) {
            $v->addError('new_date', 'Tanggal baru tidak boleh di masa lalu.');
        }
        if ($v->fails()) {
            $this->failure(implode(' ', $v->errors()), '/follow-ups/' . $id . '/edit');
        }
        try {
            FollowUp::reschedule($id, (string) $data['new_date'], $data['note']);
        } catch (DomainException $e) {
            $this->failure($e->getMessage(), '/follow-ups/' . $id . '/edit');
        }
        $this->success('Follow up dijadwalkan ulang ke ' . fmt_date((string) $data['new_date']) . '.', $this->returnTo('/follow-ups'));
    }

    public function destroy(int $id): void
    {
        $fu = $this->found(FollowUp::find($id));
        FollowUp::remove($id);
        $this->success('Follow up "' . $fu['purpose'] . '" dihapus.', $this->returnTo('/follow-ups'));
    }

    private function tabFor(string $date): string
    {
        $today = today();
        return $date === $today ? 'today' : ($date > $today ? 'upcoming' : 'overdue');
    }

    private function validate(): Validator
    {
        $v = Validator::make($_POST, [
            'customer_id'    => 'nullable|integer|exists:customers,id',
            'lead_id'        => 'nullable|integer|exists:leads,id',
            'pic_user_id'    => 'nullable|integer|exists:users,id',
            'follow_up_date' => 'required|date',
            'follow_up_time' => 'nullable|time',
            'follow_up_type' => ['required', ['in', FollowUp::TYPES]],
            'purpose'        => 'required|string|max:255',
            'status'         => ['required', ['in', FollowUp::EDITABLE_STATUSES]],
            'result'         => 'nullable|string|max:5000',
            'next_follow_up' => 'nullable|date',
            'reminder'       => 'boolean',
            'notes'          => 'nullable|string|max:5000',
        ], [
            'customer_id' => 'Customer', 'lead_id' => 'Lead', 'pic_user_id' => 'PIC', 'follow_up_date' => 'Tanggal follow up',
            'follow_up_time' => 'Jam', 'follow_up_type' => 'Jenis', 'purpose' => 'Tujuan', 'status' => 'Status', 'result' => 'Hasil',
            'next_follow_up' => 'Follow up berikutnya', 'notes' => 'Catatan',
        ]);
        ActivityController::checkCustomerLead($v);
        return $v;
    }

    /** @return array<string,mixed> */
    private function old(): array
    {
        $old = [];
        foreach (self::FIELDS as $f) {
            $old[$f] = $_POST[$f] ?? '';
        }
        return $old;
    }

    /** @return array<string,mixed> */
    private function formData(?array $fu): array
    {
        return [
            'title'     => $fu ? 'Follow Up' : 'Jadwalkan Follow Up',
            'followup'  => $fu,
            'customers' => Customer::selectOptions(),
            'leads'     => Lead::selectOptions(),
            'pics'      => User::picOptions(),
            'return'    => $this->returnTo(''),
            'today'     => today(),
        ];
    }
}
