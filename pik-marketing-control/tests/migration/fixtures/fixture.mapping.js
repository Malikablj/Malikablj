/** Mapping for the synthetic test workbook (buildFixtureWorkbook.js). */
const status = (valueMap, fallback) => ({ valueMap, ...(fallback ? { default: fallback } : {}) });

export default {
  version: 'fixture-1',
  status: 'VERIFIED',
  sourceFile: 'fixture.xlsx',
  formats: { date: null, number: null },
  ignoredSheets: { Catatan: 'Catatan internal, bukan data.' },
  entities: {
    customers: {
      sheet: 'Pelanggan',
      key: [['Kode'], ['Nama Customer']],
      fields: {
        customer_code: 'Kode',
        name: 'Nama Customer',
        industry: 'Industri',
        phone: 'Telepon',
        email: 'Email',
        status: { column: 'Status', ...status({ aktif: 'ACTIVE', potensial: 'POTENTIAL', dormant: 'DORMANT' }, 'ACTIVE') },
        notes: 'Catatan',
      },
    },
    contacts: {
      sheet: 'Kontak',
      key: [['Nama Kontak']],
      fields: { name: 'Nama Kontak', position: 'Jabatan', whatsapp: 'WhatsApp', is_primary: 'Utama' },
      refs: {
        customer_id: [
          { via: 'code', column: 'Kode Customer' },
          { via: 'name', column: 'Nama Customer' },
        ],
      },
    },
    products: {
      sheet: 'Produk',
      key: [['Kode Produk']],
      fields: { product_code: 'Kode Produk', name: 'Nama Produk', category: 'Kategori', unit: 'Satuan', lead_time_days: 'Lead Time' },
      refs: { customer_id: [{ via: 'name', column: 'Customer' }] },
    },
    leads: {
      sheet: 'Leads',
      key: [['Nama Lead']],
      fields: {
        name: 'Nama Lead',
        status: { column: 'Status', valueMap: { penawaran: 'QUOTATION' }, default: 'NEW' },
        priority: { column: 'Prioritas', valueMap: { tinggi: 'HIGH', sedang: 'MEDIUM', rendah: 'LOW' } },
        estimated_value: 'Nilai',
        expected_closing_date: 'Target Closing',
      },
      refs: {
        customer_id: [{ via: 'code', column: 'Kode Customer' }],
        owner_user_id: [{ via: 'user', column: 'PIC' }],
      },
    },
    activities: {
      sheet: 'Aktivitas',
      fields: {
        activity_date: 'Tanggal',
        activity_time: 'Jam',
        type: { column: 'Jenis', valueMap: { wa: 'WHATSAPP', kunjungan: 'VISIT' }, default: 'OTHER' },
        subject: 'Judul',
        description: 'Deskripsi',
      },
      refs: {
        customer_id: [{ via: 'code', column: 'Kode Customer' }],
        lead_id: [{ via: 'name', column: 'Nama Lead' }],
        owner_user_id: [{ via: 'user', column: 'PIC' }],
      },
    },
    follow_ups: {
      sheet: 'Follow Up',
      fields: {
        follow_up_date: 'Tanggal',
        status: { column: 'Status', valueMap: { rencana: 'PLANNED', selesai: 'DONE' }, default: 'PLANNED' },
        notes: 'Catatan',
      },
      refs: {
        customer_id: [{ via: 'code', column: 'Kode Customer' }],
        lead_id: [{ via: 'name', column: 'Nama Lead' }],
        owner_user_id: [{ via: 'user', column: 'PIC' }],
      },
    },
    purchase_orders: {
      sheet: 'PO',
      key: [['Nama Customer', 'No PO']],
      fields: {
        po_number: 'No PO',
        po_date: 'Tanggal PO',
        expected_delivery_date: 'Target Kirim',
        status: { column: 'Status', valueMap: { open: 'OPEN', partial: 'PARTIAL' }, default: 'OPEN' },
      },
      refs: {
        customer_id: [{ via: 'name', column: 'Nama Customer' }],
        owner_user_id: [{ via: 'user', column: 'PIC' }],
      },
    },
    po_lines: {
      sheet: 'PO Detail',
      fields: { order_quantity: 'Qty', unit: 'Satuan', unit_price: 'Harga' },
      refs: {
        purchase_order_id: [{ via: 'po_number', column: 'No PO', customerColumn: 'Nama Customer' }],
        product_id: [
          { via: 'code', column: 'Kode Produk' },
          { via: 'name', column: 'Nama Produk' },
        ],
      },
    },
    deliveries: {
      sheet: 'Pengiriman',
      fields: {
        delivery_date: 'Tanggal Kirim',
        quantity: 'Qty',
        status: { column: 'Status', valueMap: { terkirim: 'DELIVERED', dijadwalkan: 'SCHEDULED' }, default: 'DELIVERED' },
        delivery_number: 'No Surat Jalan',
      },
      refs: {
        purchase_order_id: [{ via: 'po_number', column: 'No PO', customerColumn: 'Nama Customer' }],
        product_id: [
          { via: 'code', column: 'Kode Produk' },
          { via: 'name', column: 'Nama Produk' },
        ],
      },
    },
    returns: {
      sheet: 'Retur',
      fields: {
        return_date: 'Tanggal Retur',
        quantity: 'Qty',
        reason: 'Alasan',
        status: { column: 'Status', valueMap: { diterima: 'RECEIVED' }, default: 'RECEIVED' },
      },
      refs: {
        customer_id: [{ via: 'name', column: 'Nama Customer' }],
        purchase_order_id: [{ via: 'po_number', column: 'No PO', customerColumn: 'Nama Customer' }],
        product_id: [{ via: 'code', column: 'Kode Produk' }],
      },
    },
    stock: {
      sheet: 'Stok',
      fields: { warehouse: 'Gudang', stock_date: 'Tanggal' },
      quantityColumns: { FG: 'FG', WIP: 'WIP', READY: 'Ready', RESERVED: 'Reserved' },
      key: [['Kode Produk', 'Gudang', 'Tanggal']],
      refs: {
        product_id: [
          { via: 'code', column: 'Kode Produk' },
          { via: 'name', column: 'Nama Produk' },
        ],
      },
    },
    leadtime: {
      sheet: 'Lead Time',
      fields: { lead_time_days: 'Hari', notes: 'Catatan' },
      refs: {
        product_id: [{ via: 'code', column: 'Kode Produk' }],
        customer_id: [{ via: 'name', column: 'Nama Customer' }],
      },
    },
    inbound_maklon: {
      sheet: 'Maklon Masuk',
      fields: { inbound_date: 'Tanggal', quantity: 'Qty', unit: 'Satuan', document_number: 'No Dokumen' },
      refs: {
        customer_id: [{ via: 'name', column: 'Nama Customer' }],
        product_id: [{ via: 'name', column: 'Nama Barang' }],
      },
    },
    invoices_payments: {
      sheet: 'Invoice',
      key: [['No Invoice']],
      fields: {
        invoice_number: 'No Invoice',
        invoice_date: 'Tanggal Invoice',
        due_date: 'Jatuh Tempo',
        amount: 'Nilai',
        paid_amount: 'Dibayar',
        payment_status: { column: 'Status Bayar', valueMap: { lunas: 'PAID', sebagian: 'PARTIAL', 'belum bayar': 'UNPAID' } },
      },
      refs: { purchase_order_id: [{ via: 'po_number', column: 'No PO', customerColumn: 'Nama Customer' }] },
    },
    po_financials: {
      sheet: 'PO Keuangan',
      fields: { po_value: 'Nilai PO', tax_amount: 'PPN', total_amount: 'Total' },
      refs: { purchase_order_id: [{ via: 'po_number', column: 'No PO', customerColumn: 'Nama Customer' }] },
    },
  },
  reconcile: {
    po_lines: { outstanding_quantity: 'Outstanding' },
    po_financials: { computed_po_value: 'Nilai PO' },
  },
};
