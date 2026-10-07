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
 *   purchase_orders (= Order Entry Form, termasuk PO line), deliveries,
 *   returns (= Complaint & Return; returns.resolve = tombol Selesai / Tidak selesai),
 *   ppic (ppic.approve = tombol "Bisa diproses" / "Tidak bisa diproses" di OEF),
 *   products, stock, leadtime, inbound, finance,
 *   reports (sub: reports.customer, reports.lead, reports.activity,
 *            reports.po, reports.delivery, reports.complaint, reports.financial,
 *            reports.export),
 *   users, settings, audit, migration, import (khusus Admin)
 *
 * Interpretasi PRD untuk modul yang tidak disebut per role:
 *   - Lead Time mengikuti akses PO/Delivery (Marketing & Management).
 *   - Inbound Maklon mengikuti akses Stock (Management).
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
        'purchase_orders.*', 'deliveries.*', 'returns.*', 'leadtime.*',
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

    // PPIC: konfirmasi OEF (bisa / tidak bisa diproses) & mengatur jadwal produksi/kirim
    'PPIC' => [
        'dashboard.view',
        'customers.view',
        'purchase_orders.view', 'ppic.approve',
        'deliveries.view', 'deliveries.edit', 'leadtime.*',
        'products.view', 'stock.view', 'inbound.view', 'returns.view',
    ],

    'Management' => [
        'dashboard.view',
        'reports.*',
        'customers.*', 'contacts.*',
        'purchase_orders.*', 'deliveries.*', 'returns.*', 'leadtime.*',
        'stock.*', 'inbound.*',
    ],

    // Read-only untuk seluruh modul operasional (tanpa Finance & area Admin)
    'Viewer' => [
        'dashboard.view',
        'customers.view', 'contacts.view',
        'leads.view', 'activities.view', 'followups.view',
        'purchase_orders.view', 'deliveries.view', 'returns.view',
        'products.view', 'stock.view', 'leadtime.view', 'inbound.view',
        'reports.view', 'reports.customer', 'reports.lead', 'reports.activity',
        'reports.po', 'reports.delivery', 'reports.complaint',
    ],
];
