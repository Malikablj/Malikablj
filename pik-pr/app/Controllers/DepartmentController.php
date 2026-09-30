<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\MasterDataRepository;

final class DepartmentController extends MasterDataController
{
    protected function repository(): MasterDataRepository
    {
        return MasterDataRepository::departments();
    }

    protected function entity(): string
    {
        return 'department';
    }

    protected function baseUrl(): string
    {
        return '/departments';
    }

    protected function title(): string
    {
        return 'Department';
    }

    protected function fields(): array
    {
        return [
            'code' => [
                'label' => 'Kode',
                'type' => 'code',
                'required' => true,
                'max' => 10,
                'unique' => true,
                'pattern' => '/^[A-Z0-9]+$/',
                'pattern_message' => 'Kode hanya boleh huruf kapital dan angka tanpa spasi.',
                'hint' => 'Dipakai pada nomor PR, contoh: PD → PR/PIK/SEPT/2026-PDPR077',
            ],
            'name' => ['label' => 'Nama department', 'type' => 'text', 'required' => true, 'max' => 100, 'unique' => true],
        ];
    }

    protected function columns(): array
    {
        return ['code' => 'Kode', 'name' => 'Nama'];
    }
}
