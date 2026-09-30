<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\MasterDataRepository;

final class SupplierController extends MasterDataController
{
    protected function repository(): MasterDataRepository
    {
        return MasterDataRepository::suppliers();
    }

    protected function entity(): string
    {
        return 'supplier';
    }

    protected function baseUrl(): string
    {
        return '/suppliers';
    }

    protected function title(): string
    {
        return 'Supplier';
    }

    protected function fields(): array
    {
        return [
            'code' => [
                'label' => 'Kode',
                'type' => 'code',
                'required' => true,
                'max' => 20,
                'unique' => true,
                'pattern' => '/^[A-Z0-9-]+$/',
                'pattern_message' => 'Kode hanya boleh huruf kapital, angka, dan tanda "-".',
            ],
            'name' => ['label' => 'Nama supplier', 'type' => 'text', 'required' => true, 'max' => 150],
            'contact' => ['label' => 'Kontak', 'type' => 'text', 'max' => 150, 'hint' => 'Nama PIC, telepon, atau email.'],
            'address' => ['label' => 'Alamat', 'type' => 'textarea', 'max' => 1000],
        ];
    }

    protected function columns(): array
    {
        return ['code' => 'Kode', 'name' => 'Nama', 'contact' => 'Kontak'];
    }
}
