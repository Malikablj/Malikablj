<?php

declare(strict_types=1);

/*
 * MATRIKS HAK AKSES (Role-Based Access Control)
 *
 * Format permission: "<modul>.<aksi>"
 *   aksi : view | create | edit | delete | export
 *   "*"          = semua permission
 *   "customers.*" = semua aksi di modul customers
 *
 * Modul:
 *   dashboard, customers, contacts, leads, activities, followups,
 *   purchase_orders (= Order Entry Form, termasuk PO line), deliveries (= Surat Jalan),
 *   purchase_orders_bulk (purchase_orders_bulk.delete = hapus banyak OEF sekaligus dari daftar;
 *     sengaja modul terpisah agar "purchase_orders.*" tidak ikut memberinya — default khusus Admin),
 *   returns (= Complaint & Return; returns.resolve = tombol Selesai / Tidak selesai),
 *   ppic (ppic.approve = tombol "Bisa diproses" / "Tidak bisa diproses" di OEF),
 *   products, stock, leadtime, inbound (= Inbound Maklon), inbound_supplier,
 *   reports (sub: reports.customer, reports.lead, reports.activity,
 *            reports.po, reports.delivery, reports.complaint, reports.export),
 *   users, settings, audit, migration, import (khusus Admin)
 *
 * Pembagian tugas per divisi:
 *   - Sales / Marketing mengisi Order Entry Form (customer & produk diketik manual,
 *     otomatis tercatat di menu Customers & Products).
 *   - PPIC hanya meninjau OEF (Bisa / Tidak bisa diproses) dan satu-satunya divisi
 *     yang mengisi Surat Jalan di menu Deliveries.
 *   - Produksi & Gudang mengisi Stock (nama produk diketik manual, dikelompokkan otomatis).
 *   - Gudang mengisi Inbound Maklon & Inbound Supplier.
 *   - Invoice & pembayaran dikelola divisi Keuangan di luar aplikasi ini.
 *
 * Permission ini dicek di BACKEND pada setiap route (lihat app/routes.php)
 * dan juga dipakai untuk menyembunyikan menu/tombol di tampilan.
 * Ubah file ini bila kebijakan akses perusahaan berubah.
 */
return [
    'Admin' => ['*'],

    'Marketing' => [
        'dashboard.view',
        'customers.*', 'contacts.*',
        'leads.*', 'activities.*', 'followups.*',
        'purchase_orders.*', 'deliveries.view', 'returns.*', 'leadtime.*',
        'products.*',
    ],

    // Sales boleh menginput Order Entry Form & complaint customer
    'Sales' => [
        'dashboard.view',
        'customers.*', 'contacts.*',
        'leads.*', 'activities.*', 'followups.*',
        'purchase_orders.view', 'purchase_orders.create', 'purchase_orders.edit',
        'deliveries.view', 'returns.view', 'returns.create', 'products.view',
    ],

    // PPIC: hanya meninjau OEF (bisa / tidak bisa diproses) + menu Delivery (mengisi Surat Jalan)
    'PPIC' => [
        'dashboard.view',
        'purchase_orders.view', 'ppic.approve',
        'deliveries.*',
    ],

    // Produksi: mengisi stok (nama produk diketik manual)
    'Produksi' => [
        'dashboard.view',
        'stock.*',
    ],

    // Gudang: stok, inbound maklon, inbound supplier
    'Gudang' => [
        'dashboard.view',
        'stock.*', 'inbound.*', 'inbound_supplier.*',
    ],

    'Management' => [
        'dashboard.view',
        'reports.*',
        'customers.*', 'contacts.*',
        'purchase_orders.*', 'deliveries.view', 'returns.*', 'leadtime.*',
        'stock.view', 'inbound.view', 'inbound_supplier.view',
    ],

    // Read-only untuk seluruh modul operasional (tanpa area Admin & export)
    'Viewer' => [
        'dashboard.view',
        'customers.view', 'contacts.view',
        'leads.view', 'activities.view', 'followups.view',
        'purchase_orders.view', 'deliveries.view', 'returns.view',
        'products.view', 'stock.view', 'leadtime.view', 'inbound.view', 'inbound_supplier.view',
        'reports.view', 'reports.customer', 'reports.lead', 'reports.activity',
        'reports.po', 'reports.delivery', 'reports.complaint',
    ],
];
