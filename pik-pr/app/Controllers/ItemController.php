<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\MasterDataRepository;

final class ItemController extends MasterDataController
{
    protected function repository(): MasterDataRepository
    {
        return MasterDataRepository::items();
    }

    protected function entity(): string
    {
        return 'item';
    }

    protected function baseUrl(): string
    {
        return '/items';
    }

    protected function title(): string
    {
        return 'Item';
    }

    protected function fields(): array
    {
        return [
            'code' => [
                'label' => 'Kode',
                'type' => 'code',
                'required' => true,
                'max' => 30,
                'unique' => true,
                'pattern' => '/^[A-Z0-9._-]+$/',
                'pattern_message' => 'Kode hanya boleh huruf kapital, angka, titik, "_" dan "-".',
            ],
            'name' => ['label' => 'Nama item', 'type' => 'text', 'required' => true, 'max' => 150],
            'description' => ['label' => 'Deskripsi', 'type' => 'textarea', 'max' => 1000],
            'unit' => ['label' => 'Satuan', 'type' => 'text', 'required' => true, 'max' => 20, 'default' => 'pcs'],
            'default_price' => ['label' => 'Harga default', 'type' => 'money', 'hint' => 'Mengisi harga satuan otomatis saat item dipilih di form PR.'],
        ];
    }

    protected function columns(): array
    {
        return ['code' => 'Kode', 'name' => 'Nama', 'unit' => 'Satuan', 'default_price' => 'Harga default'];
    }
}
