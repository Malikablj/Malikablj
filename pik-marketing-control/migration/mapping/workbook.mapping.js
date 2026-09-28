/**
 * Mapping: PIK_Master_Database_AppSheet.xlsx -> PostgreSQL
 *
 * STATUS: PROVISIONAL. THE SOURCE WORKBOOK HAS NOT BEEN PROVIDED YET.
 * Sheet names follow the entity list of the Technical Specification; column names are
 * PLACEHOLDERS based on the specified fields. Before any real import:
 *   1. npm run migrate:profile   -> docs/DATA_PROFILE.md lists the real sheets/columns
 *   2. edit this file to the real names, value maps and formats
 *   3. set status: 'VERIFIED' and update docs/MIGRATION_MAPPING.md
 * `npm run migrate` refuses to run while status is PROVISIONAL.
 *
 * Format reference (see docs/MIGRATION_MAPPING.md for the full rules):
 *   fields:  { targetField: 'Source Column' }
 *            { targetField: { column, valueMap: { 'source label': 'CODE' }, default, dateFormat, numberFormat } }
 *   refs:    { targetFk: [ { via: 'key'|'code'|'name'|'user'|'po_number', column, customerColumn? }, ... ] }
 *            strategies are tried in order (Level 1 identifiers first, then Level 2 names)
 *   key:     [ ['Col A'], ['Col B', 'Col C'] ]  alternatives identifying a row across re-runs
 *   ignoredSheets: { 'Sheet name': 'reason' }  every sheet must be mapped or ignored
 */
export default {
  version: '2026-09-28.provisional-1',
  status: 'PROVISIONAL',
  sourceFile: 'PIK_Master_Database_AppSheet.xlsx',

  // How to read dates/numbers stored as TEXT (real Excel dates/numbers need no format).
  // Leave null until the profile shows which convention the workbook uses.
  formats: { date: null, number: null },

  ignoredSheets: {},

  entities: {
    customers: {
      sheet: 'Customers',
      key: [['Customer Code'], ['Customer Name']],
      fields: {
        customer_code: 'Customer Code',
        name: 'Customer Name',
        industry: 'Industry',
        address: 'Address',
        phone: 'Phone',
        email: 'Email',
        website: 'Website',
        status: {
          column: 'Status',
          valueMap: { aktif: 'ACTIVE', active: 'ACTIVE', potensial: 'POTENTIAL', prospect: 'POTENTIAL', dormant: 'DORMANT', 'tidak aktif': 'INACTIVE', inactive: 'INACTIVE' },
          default: 'ACTIVE',
        },
        notes: 'Notes',
      },
    },

    contacts: {
      sheet: 'Contacts',
      fields: {
        name: 'Contact Name',
        position: 'Position',
        phone: 'Phone',
        email: 'Email',
        whatsapp: 'WhatsApp',
        is_primary: 'Primary',
        notes: 'Notes',
      },
      refs: {
        customer_id: [
          { via: 'code', column: 'Customer Code' },
          { via: 'name', column: 'Customer Name' },
        ],
      },
    },

    products: {
      sheet: 'Products',
      key: [['Product Code']],
      fields: {
        product_code: 'Product Code',
        name: 'Product Name',
        category: 'Category',
        description: 'Description',
        unit: 'Unit',
        lead_time_days: 'Lead Time (Days)',
        status: {
          column: 'Status',
          valueMap: { aktif: 'ACTIVE', active: 'ACTIVE', development: 'DEVELOPMENT', pengembangan: 'DEVELOPMENT', discontinued: 'DISCONTINUED' },
          default: 'ACTIVE',
        },
      },
      refs: { customer_id: [{ via: 'name', column: 'Customer Name' }] },
    },

    leads: {
      sheet: 'Leads',
      fields: {
        name: 'Lead Name',
        source: 'Source',
        estimated_value: 'Estimated Value',
        status: {
          column: 'Status',
          valueMap: { new: 'NEW', baru: 'NEW', contacted: 'CONTACTED', qualified: 'QUALIFIED', quotation: 'QUOTATION', penawaran: 'QUOTATION', negotiation: 'NEGOTIATION', negosiasi: 'NEGOTIATION', won: 'WON', deal: 'WON', lost: 'LOST', batal: 'LOST', dormant: 'DORMANT' },
          default: 'NEW',
        },
        priority: { column: 'Priority', valueMap: { low: 'LOW', rendah: 'LOW', medium: 'MEDIUM', sedang: 'MEDIUM', high: 'HIGH', tinggi: 'HIGH' } },
        expected_closing_date: 'Expected Closing Date',
        notes: 'Notes',
      },
      refs: {
        customer_id: [{ via: 'code', column: 'Customer Code' }, { via: 'name', column: 'Customer Name' }],
        contact_id: [{ via: 'name', column: 'Contact Name' }],
        product_id: [{ via: 'code', column: 'Product Code' }],
        owner_user_id: [{ via: 'user', column: 'PIC' }],
      },
    },

    activities: {
      sheet: 'Activities',
      fields: {
        type: {
          column: 'Type',
          valueMap: { whatsapp: 'WHATSAPP', wa: 'WHATSAPP', call: 'CALL', telepon: 'CALL', email: 'EMAIL', meeting: 'MEETING', visit: 'VISIT', kunjungan: 'VISIT', quotation: 'QUOTATION', penawaran: 'QUOTATION', sample: 'SAMPLE', sampel: 'SAMPLE', presentation: 'PRESENTATION', 'follow up': 'FOLLOW_UP', complaint: 'COMPLAINT', komplain: 'COMPLAINT', note: 'NOTE', catatan: 'NOTE', other: 'OTHER', lainnya: 'OTHER' },
          default: 'OTHER',
        },
        subject: 'Subject',
        description: 'Description',
        activity_date: 'Date',
        activity_time: 'Time',
      },
      refs: {
        customer_id: [{ via: 'code', column: 'Customer Code' }, { via: 'name', column: 'Customer Name' }],
        contact_id: [{ via: 'name', column: 'Contact Name' }],
        lead_id: [{ via: 'name', column: 'Lead Name' }],
        owner_user_id: [{ via: 'user', column: 'PIC' }],
      },
    },

    follow_ups: {
      sheet: 'Follow Ups',
      fields: {
        follow_up_date: 'Follow Up Date',
        follow_up_time: 'Time',
        priority: { column: 'Priority', valueMap: { low: 'LOW', rendah: 'LOW', medium: 'MEDIUM', sedang: 'MEDIUM', high: 'HIGH', tinggi: 'HIGH' } },
        status: {
          column: 'Status',
          valueMap: { planned: 'PLANNED', rencana: 'PLANNED', done: 'DONE', selesai: 'DONE', reschedule: 'RESCHEDULE', cancelled: 'CANCELLED', batal: 'CANCELLED', overdue: 'OVERDUE' },
          default: 'PLANNED',
        },
        notes: 'Notes',
      },
      refs: {
        customer_id: [{ via: 'code', column: 'Customer Code' }, { via: 'name', column: 'Customer Name' }],
        lead_id: [{ via: 'name', column: 'Lead Name' }],
        owner_user_id: [{ via: 'user', column: 'PIC' }],
      },
    },

    purchase_orders: {
      sheet: 'Purchase Orders',
      key: [['Customer Name', 'PO Number']],
      fields: {
        po_number: 'PO Number',
        po_date: 'PO Date',
        expected_delivery_date: 'Expected Delivery Date',
        status: {
          column: 'Status',
          valueMap: { open: 'OPEN', 'on process': 'ON_PROCESS', proses: 'ON_PROCESS', partial: 'PARTIAL', sebagian: 'PARTIAL', closed: 'CLOSED', selesai: 'CLOSED', cancelled: 'CANCELLED', batal: 'CANCELLED' },
          default: 'OPEN',
        },
        notes: 'Notes',
      },
      refs: {
        customer_id: [{ via: 'code', column: 'Customer Code' }, { via: 'name', column: 'Customer Name' }],
        owner_user_id: [{ via: 'user', column: 'PIC' }],
      },
    },

    po_lines: {
      sheet: 'PO Lines',
      fields: {
        line_no: 'Line No',
        order_quantity: 'Order Qty',
        unit: 'Unit',
        unit_price: 'Unit Price',
        notes: 'Notes',
      },
      refs: {
        purchase_order_id: [{ via: 'po_number', column: 'PO Number', customerColumn: 'Customer Name' }],
        product_id: [{ via: 'code', column: 'Product Code' }, { via: 'name', column: 'Product Name' }],
      },
    },

    deliveries: {
      sheet: 'Deliveries',
      fields: {
        delivery_date: 'Delivery Date',
        quantity: 'Qty',
        status: {
          column: 'Status',
          valueMap: { scheduled: 'SCHEDULED', dijadwalkan: 'SCHEDULED', 'on delivery': 'ON_DELIVERY', dikirim: 'ON_DELIVERY', delivered: 'DELIVERED', terkirim: 'DELIVERED', delayed: 'DELAYED', terlambat: 'DELAYED', cancelled: 'CANCELLED', batal: 'CANCELLED' },
          // Legacy delivery rows record completed deliveries unless a status says otherwise.
          default: 'DELIVERED',
        },
        delivery_number: 'DO Number',
        notes: 'Notes',
      },
      refs: {
        purchase_order_id: [{ via: 'po_number', column: 'PO Number', customerColumn: 'Customer Name' }],
        product_id: [{ via: 'code', column: 'Product Code' }, { via: 'name', column: 'Product Name' }],
      },
    },

    returns: {
      sheet: 'Returns',
      fields: {
        return_date: 'Return Date',
        quantity: 'Qty',
        reason: 'Reason',
        status: {
          column: 'Status',
          valueMap: { reported: 'REPORTED', dilaporkan: 'REPORTED', received: 'RECEIVED', diterima: 'RECEIVED', resolved: 'RESOLVED', selesai: 'RESOLVED', cancelled: 'CANCELLED', batal: 'CANCELLED' },
          // Legacy return rows record goods that came back unless a status says otherwise.
          default: 'RECEIVED',
        },
        return_number: 'Return Number',
        notes: 'Notes',
      },
      refs: {
        customer_id: [{ via: 'code', column: 'Customer Code' }, { via: 'name', column: 'Customer Name' }],
        purchase_order_id: [{ via: 'po_number', column: 'PO Number', customerColumn: 'Customer Name' }],
        product_id: [{ via: 'code', column: 'Product Code' }, { via: 'name', column: 'Product Name' }],
      },
    },

    stock: {
      sheet: 'Stock',
      fields: {
        stock_type: { column: 'Stock Type', valueMap: { fg: 'FG', 'finished goods': 'FG', wip: 'WIP', ready: 'READY', reserved: 'RESERVED' } },
        quantity: 'Qty',
        warehouse: 'Warehouse',
        stock_date: 'Stock Date',
        notes: 'Notes',
      },
      refs: { product_id: [{ via: 'code', column: 'Product Code' }, { via: 'name', column: 'Product Name' }] },
    },

    leadtime: {
      sheet: 'Lead Time',
      fields: { lead_time_days: 'Lead Time (Days)', notes: 'Notes' },
      refs: {
        product_id: [{ via: 'code', column: 'Product Code' }, { via: 'name', column: 'Product Name' }],
        customer_id: [{ via: 'name', column: 'Customer Name' }],
      },
    },

    inbound_maklon: {
      sheet: 'Inbound Maklon',
      fields: {
        inbound_date: 'Date',
        quantity: 'Qty',
        unit: 'Unit',
        document_number: 'Document Number',
        notes: 'Notes',
      },
      refs: {
        customer_id: [{ via: 'name', column: 'Customer Name' }],
        purchase_order_id: [{ via: 'po_number', column: 'PO Number', customerColumn: 'Customer Name' }],
        product_id: [{ via: 'code', column: 'Product Code' }, { via: 'name', column: 'Product Name' }],
      },
    },

    invoices_payments: {
      sheet: 'Invoices Payments',
      key: [['Invoice Number']],
      fields: {
        invoice_number: 'Invoice Number',
        invoice_date: 'Invoice Date',
        due_date: 'Due Date',
        amount: 'Amount',
        paid_amount: 'Paid Amount',
        payment_status: { column: 'Payment Status', valueMap: { unpaid: 'UNPAID', 'belum bayar': 'UNPAID', partial: 'PARTIAL', sebagian: 'PARTIAL', paid: 'PAID', lunas: 'PAID', cancelled: 'CANCELLED', batal: 'CANCELLED' } },
        payment_date: 'Payment Date',
        notes: 'Notes',
      },
      refs: { purchase_order_id: [{ via: 'po_number', column: 'PO Number', customerColumn: 'Customer Name' }] },
    },

    po_financials: {
      sheet: 'PO Financials',
      fields: {
        po_value: 'PO Value',
        tax_amount: 'Tax',
        total_amount: 'Total',
        invoiced_amount: 'Invoiced',
        paid_amount: 'Paid',
        outstanding_amount: 'Outstanding Amount',
        notes: 'Notes',
      },
      refs: { purchase_order_id: [{ via: 'po_number', column: 'PO Number', customerColumn: 'Customer Name' }] },
    },
  },

  // Legacy computed columns to compare with the database calculations (npm run migrate:verify).
  reconcile: {},
};
