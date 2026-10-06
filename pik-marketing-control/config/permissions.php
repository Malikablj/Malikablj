<?php

declare(strict_types=1);

/*
 * MATRIKS HAK AKSES (Role-Based Access Control)
 *
 * Format permission: "<modul>.<aksi>"
 *   aksi : view | create | edit | delete | export
 *   "*"            = semua permission
 *   "customers.*"  = semua aksi di modul customers
 *   "!modul.aksi"  = DIKECUALIKAN walaupun role punya "*" / "modul.*"
 *
 * Modul:
 *   dashboard, customers, contacts, leads, activities, followups,
 *   purchase_orders (= Order Entry Form / OEF, termasuk baris produk),
 *   oef_review.approve (konfirmasi "Bisa diproses" / "Tidak bisa diproses"),
 *   deliveries (deliveries.sj = mengisi nomor Surat Jalan),
 *   returns (Retur & Komplain), products, stock, leadtime,
 *   inbound (Inbound Maklon), inbound_supplier (Inbound Supplier),
 *   po_values.view (arsip nilai/harga PO hasil import — data keuangan),
 *   reports (sub: reports.customer, reports.lead, reports.activity,
 *            reports.po, reports.delivery, reports.complaint, reports.export),
 *   users, settings, audit, migration, import (khusus Admin)
 *
 * Menu Finance (Invoice & Payment, PO Financials) sudah dihapus karena
 * ranah divisi keuangan. Tabel & datanya tetap tersimpan di database.
 *
 * Permission ini dicek di BACKEND pada setiap route (lihat app/routes.php)
 * dan juga dipakai untuk menyembunyikan menu/tombol di tampilan.
 * Ubah file ini bila kebijakan akses perusahaan berubah.
 */
return [
    // Review OEF dan pengisian Surat Jalan hanya oleh PPIC. Hapus baris "!..."
    // bila Admin juga boleh melakukannya.
    'Admin' => ['*', '!oef_review.approve', '!deliveries.sj'],

    'Marketing' => [
        'dashboard.view',
        'customers.*', 'contacts.*',
        'leads.*', 'activities.*', 'followups.*',
        'purchase_orders.*', 'deliveries.view', 'returns.*', 'leadtime.*',
        'products.*',
    ],

    'Sales' => [
        'dashboard.view',
        'customers.*', 'contacts.*',
        'leads.*', 'activities.*', 'followups.*',
    ],

    // Pemantauan: melihat seluruh data operasional + laporan
    'Management' => [
        'dashboard.view',
        'reports.*',
        'customers.*', 'contacts.*',
        'purchase_orders.view', 'deliveries.view', 'returns.view', 'leadtime.view',
        'products.view', 'stock.view', 'inbound.view', 'inbound_supplier.view',
    ],

    // Read-only untuk seluruh modul operasional (tanpa area Admin)
    'Viewer' => [
        'dashboard.view',
        'customers.view', 'contacts.view',
        'leads.view', 'activities.view', 'followups.view',
        'purchase_orders.view', 'deliveries.view', 'returns.view',
        'products.view', 'stock.view', 'leadtime.view', 'inbound.view', 'inbound_supplier.view',
        'reports.view', 'reports.customer', 'reports.lead', 'reports.activity',
        'reports.po', 'reports.delivery', 'reports.complaint',
    ],

    // Review OEF + menu Delivery (termasuk Surat Jalan)
    'PPIC' => [
        'dashboard.view',
        'purchase_orders.view', 'oef_review.approve',
        'deliveries.*',
    ],

    'Produksi' => [
        'dashboard.view',
        'stock.*',
    ],

    'Gudang' => [
        'dashboard.view',
        'stock.*', 'inbound.*',
    ],

    'Purchasing' => [
        'dashboard.view',
        'inbound_supplier.*',
    ],
];
