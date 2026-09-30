<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Repositories\SettingRepository;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Support\Decimal;

final class SettingsController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->view('settings/index', [
            'title' => 'Pengaturan',
            'settings' => (new SettingRepository())->all(),
            'canManageSystem' => $this->user()['role'] === 'super_admin',
        ]);
    }

    public function updatePassword(Request $request): Response
    {
        (new AuthService())->changePassword(
            $this->user(),
            (string) ($request->post['current_password'] ?? ''),
            (string) ($request->post['password'] ?? ''),
            (string) ($request->post['password_confirmation'] ?? ''),
        );

        return $this->redirect('/settings', 'Password berhasil diubah.');
    }

    public function updateSystem(Request $request): Response
    {
        $user = $this->user();
        $input = $request->all();
        $v = new Validator($input);
        $v->required('company_name', 'Nama perusahaan')->maxLength('company_name', 150, 'Nama perusahaan')
            ->maxLength('company_address', 500, 'Alamat perusahaan')
            ->required('pr_prefix', 'Prefix nomor PR')->maxLength('pr_prefix', 20, 'Prefix nomor PR')
            ->pattern('pr_prefix', '#^[A-Z0-9/-]+$#', 'Prefix hanya boleh huruf kapital, angka, "/" dan "-".')
            ->required('default_tax_rate', 'Pajak default')->decimal('default_tax_rate', 'Pajak default', '0', '100');
        $v->throwIfFailed();

        $repo = new SettingRepository();
        $before = $repo->all();
        $after = [
            'company_name' => $v->value('company_name'),
            'company_address' => $v->value('company_address'),
            'pr_prefix' => $v->value('pr_prefix'),
            'default_tax_rate' => (string) Decimal::parse($v->value('default_tax_rate')),
        ];

        Database::transaction(function () use ($repo, $user, $before, $after): void {
            foreach ($after as $key => $value) {
                $repo->set($key, $value, (int) $user['id']);
            }
            [$old, $new] = AuditService::diff($before, $after);
            (new AuditService())->log((int) $user['id'], 'settings.update', 'settings', null, $old, $new);
        });

        return $this->redirect('/settings', 'Pengaturan sistem disimpan.');
    }
}
