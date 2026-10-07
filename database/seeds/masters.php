<?php
declare(strict_types=1);

/**
 * Daftar master NPR (PRD §4.4) — nilai awal diambil dari form PIK-FORM-NPD-01 rev 00.
 * Format: category => [[code, label_id, label_en, meta|null], ...]
 * Admin dapat menambah, mengubah, mengurutkan, menonaktifkan (tidak menghapus).
 */
return [
    'part_name' => [
        ['body', 'Body', 'Body', null],
        ['cap', 'Cap', 'Cap', null],
        ['plug', 'Plug', 'Plug', null],
        ['spatula', 'Spatula', 'Spatula', null],
        ['neck_preform', 'Neck dan Preform', 'Neck and Preform', ['neck_preform' => true]],
    ],
    // Form: "STATUS PROJECT" — di sistem bernama "Jenis Permintaan" (PRD §4.2)
    'request_type' => [
        ['produk_baru', 'Produk Baru', 'New Product', null],
        ['penggantian_mould', 'Penggantian mould', 'Mould Replacement', null],
        ['modifikasi_mould', 'Modifikasi Mould', 'Mould Modification', null],
        ['additional_mould', 'Additional mould/tooling', 'Additional mould/tooling', null],
        ['material_baru', 'Material Baru', 'New Material', null],
        ['new_artwork', 'New Art Work', 'New Artwork', null],
        ['free_item', 'Free Item', 'Free Item', null],
        ['lain_lain', 'Lain-lain', 'Others', ['other' => true]],
    ],
    'development_type' => [
        ['new_mould', 'Pembuatan Mould Baru', 'New Mould', null],
        ['existing_mould', 'Mould Existing', 'Existing Mould', ['requires_supplier' => true]],
        ['modify_mould', 'Modifikasi Mould', 'Mould Modification', null],
    ],
    'product_application' => [
        ['bhn_kimia', 'Bhn Kimia', 'Chemicals', null],
        ['bhn_pelumas', 'Bhn pelumas', 'Lubricants', null],
        ['makanan', 'Makanan', 'Food', null],
        ['kosmetik', 'Kosmetik', 'Cosmetics', null],
        ['rumah_tangga', 'Rumah Tangga', 'Household', null],
        ['medical', 'Medical', 'Medical', null],
        ['hotel', 'Hotel', 'Hotel', null],
    ],
    'product_content' => [
        ['cair', 'Cair', 'Liquid', null],
        ['bubuk', 'Bubuk', 'Powder', null],
        ['krim', 'Krim', 'Cream', null],
        ['makanan', 'Makanan', 'Food', null],
        ['foam', 'Foam', 'Foam', null],
        ['lain_lain', 'Lain-lain', 'Others', ['other' => true]],
    ],
    'resin' => [
        ['hdpe', 'HDPE', 'HDPE', null],
        ['ldpe', 'LDPE', 'LDPE', null],
        ['pp', 'PP', 'PP', null],
        ['ps', 'PS', 'PS', null],
        ['pet', 'PET', 'PET', null],
        ['pvc', 'PVC', 'PVC', null],
        ['petg', 'PETG', 'PETG', null],
        ['pptg', 'PPTG', 'PPTG', null],
        ['abs', 'ABS', 'ABS', null],
        ['glass', 'GLASS', 'GLASS', null],
    ],
    'color' => [
        ['pearlized', 'Pearlized', 'Pearlized', null],
        ['dark_light', 'Dark / Light', 'Dark / Light', null],
        ['opaque', 'Opaque', 'Opaque', null],
        ['translucent', 'Translucent', 'Translucent', null],
    ],
    'surface' => [
        ['matte', 'Matte', 'Matte', null],
        ['glossy', 'Glossy', 'Glossy', null],
    ],
    'neck_preform' => [
        ['n18_9', 'Neck Ø 18 mm - 9 Gram', 'Neck Ø 18 mm - 9 Gram', null],
        ['n24_13', 'Neck Ø 24 mm - 13 Gram', 'Neck Ø 24 mm - 13 Gram', null],
        ['n24_23', 'Neck Ø 24 mm - 23 Gram', 'Neck Ø 24 mm - 23 Gram', null],
        ['n24_30', 'Neck Ø 24 mm - 30 Gram', 'Neck Ø 24 mm - 30 Gram', null],
    ],
    'printing_method' => [
        ['offset', 'Offset', 'Offset', null],
        ['screen', 'Screen', 'Screen', null],
    ],
    'varnish' => [
        ['glossy', 'Glossy', 'Glossy', null],
        ['matte', 'Matte', 'Matte', null],
    ],
    'labelling_side' => [
        ['front', 'Front Side', 'Front Side', null],
        ['back', 'Back Side', 'Back Side', null],
        ['front_back_360', 'Front Side and Back Side (360°)', 'Front and Back Side (360°)', null],
    ],
    'mould_method' => [
        ['extrusion_blow', 'Extrusion Blow', 'Extrusion Blow', null],
        ['injection_stretch_blow', 'Injection Stretch Blow', 'Injection Stretch Blow', null],
        ['injection_blow', 'Injection Blow', 'Injection Blow', null],
        ['injection', 'Injection', 'Injection', null],
        ['tube', 'Tube', 'Tube', null],
        ['other', 'Lainnya', 'Other', ['other' => true]],
    ],
    'packaging' => [
        ['box', 'Box', 'Box', null],
        ['plastic', 'Plastic', 'Plastic', null],
        ['lain_lain', 'Lain-lain', 'Others', ['other' => true]],
    ],
    'test_method' => [
        ['leaking_test', 'Leaking Test', 'Leaking Test', ['units' => ['Kg/Cm²']]],
        ['drop_test', 'Drop Test', 'Drop Test', ['units' => ['Feet', 'Meter']]],
        ['passing_test', 'Passing test', 'Passing Test', null],
        ['tape_test', 'Tape Test', 'Tape Test', null],
        ['product_capability', 'Product Capability / Chemical Resistance', 'Product Capability / Chemical Resistance', null],
        ['sealing_test', 'Sealing Test', 'Sealing Test', null],
        ['lof_test', 'LOF Test', 'LOF Test', null],
        ['lain_lain', 'Lain-lain', 'Others', ['other' => true]],
    ],
    // Lampiran B.1 — 18 tipe dokumen
    'document_type' => [
        ['npr', 'NPR', 'NPR', null],
        ['feedback', 'Feedback', 'Feedback', null],
        ['artwork', 'Artwork', 'Artwork', null],
        ['technical_drawing', 'Technical Drawing', 'Technical Drawing', ['note' => 'Menggantikan 3D Drawing']],
        ['prototype_3d_document', '3D Prototype Document', '3D Prototype Document', ['note' => 'Nama sementara']],
        ['layout_decoration', 'Layout Decoration', 'Layout Decoration', ['note' => 'Sebelumnya 2D Drawing']],
        ['mold_drawing', 'Mold Drawing', 'Mold Drawing', null],
        ['approval', 'Approval', 'Approval', null],
        ['trial_report', 'Trial Report', 'Trial Report', null],
        ['trial_photo', 'Trial Photo', 'Trial Photo', null],
        ['trial_video', 'Trial Video', 'Trial Video', null],
        ['material_request', 'Material Request', 'Material Request', null],
        ['coa', 'CoA', 'CoA', null],
        ['material_spec', 'Material Spec', 'Material Spec', null],
        ['validation_report', 'Validation Report', 'Validation Report', null],
        ['customer_document', 'Customer Document', 'Customer Document', null],
        ['supplier_document', 'Supplier Document', 'Supplier Document', null],
        ['other', 'Other', 'Other', null],
    ],
];
