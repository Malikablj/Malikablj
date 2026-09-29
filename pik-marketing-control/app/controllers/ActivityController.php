<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Auth;
use App\Helpers\Database;
use App\Helpers\Request;
use App\Helpers\Validator;
use App\Models\Activity;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use DomainException;

final class ActivityController extends Controller
{
    private const FIELDS = ['customer_id', 'lead_id', 'activity_date', 'activity_type', 'pic_user_id', 'subject', 'description', 'next_action', 'next_follow_up', 'attachment', 'create_follow_up'];

    public function index(): void
    {
        $filters = [
            'q'           => Request::queryString('q'),
            'type'        => Request::queryString('type'),
            'pic'         => Request::queryInt('pic'),
            'customer_id' => Request::queryInt('customer_id'),
            'from'        => Validator::parseDate(Request::queryString('from')) ?? '',
            'to'          => Validator::parseDate(Request::queryString('to')) ?? '',
        ];
        $this->view('activities/index', [
            'title'      => 'Activities',
            'activities' => Activity::paginate($filters, $this->page()),
            'filters'    => $filters,
            'pics'       => User::picOptions(),
            'customers'  => Customer::selectOptions(),
        ]);
    }

    public function create(): void
    {
        $preset = [
            'customer_id'      => Request::queryInt('customer_id') ?: null,
            'lead_id'          => Request::queryInt('lead_id') ?: null,
            'activity_date'    => date('Y-m-d\TH:i'),
            'activity_type'    => 'WhatsApp',
            'pic_user_id'      => Auth::id(),
            'create_follow_up' => '1',
        ];
        $this->view('activities/form', $this->formData(null) + ['errors' => [], 'preset' => $preset]);
    }

    public function store(): void
    {
        $v = $this->validate();
        if ($v->fails()) {
            $this->invalid('activities/form', $this->formData(null) + ['preset' => []], $v->errors(), $this->old());
            return;
        }
        $data = self::fillCustomerFromLead($v->validated());
        $createFollowUp = (int) $data['create_follow_up'] === 1;
        unset($data['create_follow_up']);
        $result = Activity::saveActivity(null, $data, $createFollowUp);
        $msg = 'Aktivitas tersimpan.' . ($result['follow_up_id'] ? ' Follow up untuk ' . fmt_date((string) $data['next_follow_up']) . ' otomatis dijadwalkan.' : '');
        $fallback = $data['lead_id'] ? '/leads/' . $data['lead_id'] : ($data['customer_id'] ? '/customers/' . $data['customer_id'] . '?tab=activities' : '/activities');
        $this->success($msg, $this->returnTo($fallback));
    }

    public function edit(int $id): void
    {
        $activity = $this->found(Activity::findFull($id));
        $activity['activity_date'] = str_replace(' ', 'T', substr((string) $activity['activity_date'], 0, 16));
        $activity['create_follow_up'] = '0';
        $this->view('activities/form', $this->formData($activity) + ['errors' => [], 'preset' => []]);
    }

    public function update(int $id): void
    {
        $activity = $this->found(Activity::findFull($id));
        $v = $this->validate();
        if ($v->fails()) {
            $this->invalid('activities/form', $this->formData($activity) + ['preset' => []], $v->errors(), $this->old());
            return;
        }
        $data = self::fillCustomerFromLead($v->validated());
        $createFollowUp = (int) $data['create_follow_up'] === 1;
        unset($data['create_follow_up']);
        Activity::saveActivity($id, $data, $createFollowUp);
        $this->success('Aktivitas diperbarui.', $this->returnTo('/activities'));
    }

    public function destroy(int $id): void
    {
        $activity = $this->found(Activity::find($id));
        try {
            Activity::remove($id);
        } catch (DomainException $e) {
            $this->failure($e->getMessage(), '/activities');
        }
        $this->success('Aktivitas "' . $activity['subject'] . '" dihapus.', $this->returnTo('/activities'));
    }

    private function validate(): Validator
    {
        $v = Validator::make($_POST, [
            'customer_id'      => 'nullable|integer|exists:customers,id',
            'lead_id'          => 'nullable|integer|exists:leads,id',
            'activity_date'    => 'required|datetime',
            'activity_type'    => ['required', ['in', Activity::TYPES]],
            'pic_user_id'      => 'nullable|integer|exists:users,id',
            'subject'          => 'required|string|max:190',
            'description'      => 'nullable|string|max:10000',
            'next_action'      => 'nullable|string|max:255',
            'next_follow_up'   => 'nullable|date',
            'attachment'       => 'nullable|url|max:500',
            'create_follow_up' => 'boolean',
        ], [
            'customer_id' => 'Customer', 'lead_id' => 'Lead', 'activity_date' => 'Tanggal & jam', 'activity_type' => 'Jenis aktivitas',
            'pic_user_id' => 'PIC', 'subject' => 'Judul', 'description' => 'Deskripsi', 'next_action' => 'Tindak lanjut',
            'next_follow_up' => 'Tanggal follow up', 'attachment' => 'Link lampiran',
        ]);
        self::checkCustomerLead($v);
        return $v;
    }

    /**
     * Aktivitas/follow up wajib terhubung ke customer atau lead. Bila lead
     * dipilih, customer otomatis mengikuti customer lead tersebut.
     */
    public static function checkCustomerLead(Validator $v): void
    {
        $data = $v->validated();
        if ($v->fails()) {
            return;
        }
        if (empty($data['customer_id']) && empty($data['lead_id'])) {
            $v->addError('customer_id', 'Pilih customer atau lead.');
            return;
        }
        if (!empty($data['lead_id'])) {
            $leadCustomer = Database::fetchValue('SELECT customer_id FROM leads WHERE id = :id', ['id' => $data['lead_id']]);
            if ($leadCustomer !== null && !empty($data['customer_id']) && (int) $leadCustomer !== (int) $data['customer_id']) {
                $v->addError('lead_id', 'Lead ini milik customer lain.');
            }
        }
    }

    /** Lengkapi customer_id dari lead (dipanggil setelah validasi). */
    public static function fillCustomerFromLead(array $data): array
    {
        if (empty($data['customer_id']) && !empty($data['lead_id'])) {
            $leadCustomer = Database::fetchValue('SELECT customer_id FROM leads WHERE id = :id', ['id' => $data['lead_id']]);
            $data['customer_id'] = $leadCustomer !== null ? (int) $leadCustomer : null;
        }
        return $data;
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
    private function formData(?array $activity): array
    {
        return [
            'title'     => $activity ? 'Edit Aktivitas' : 'Log Aktivitas',
            'activity'  => $activity,
            'customers' => Customer::selectOptions(),
            'leads'     => Lead::selectOptions(),
            'pics'      => User::picOptions(),
            'return'    => $this->returnTo(''),
        ];
    }
}
