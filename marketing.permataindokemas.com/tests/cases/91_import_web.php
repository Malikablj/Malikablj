<?php

declare(strict_types=1);

use App\Helpers\Database;
use App\Services\Migration\WorkbookImporter;

group('Import · Halaman web (Admin)');

test('import web: hanya Admin; validasi file; cek dulu tidak menyimpan; import hanya ke database kosong', function () {
    foreach (['Marketing', 'Management', 'Viewer'] as $role) {
        assert_status(403, client_as($role)->get('/import'), $role);
    }
    $fx = build_import_fixture();
    $c = client_as('Admin');
    assert_status(200, $c->get('/import'));

    // file tidak valid
    $txt = sys_get_temp_dir() . '/pik-bukan-excel-' . getmypid() . '.txt';
    file_put_contents($txt, 'bukan excel');
    $r1 = $c->upload('/import', ['mode' => 'dry', 'apply_corrections' => '1'], ['master' => $txt]);
    assert_status(422, $r1);
    assert_contains('bukan .xlsx', $r1->body);
    $fake = sys_get_temp_dir() . '/pik-palsu-' . getmypid() . '.xlsx';
    file_put_contents($fake, 'bukan zip');
    $r2 = $c->upload('/import', ['mode' => 'dry'], ['master' => $fake]);
    assert_contains('bukan file .xlsx yang valid', $r2->body);
    $r3 = $c->upload('/import', ['mode' => 'dry'], []);
    assert_contains('Pilih file master workbook', $r3->body);

    // database berisi data (dari test sebelumnya): cek dulu boleh, import ditolak
    WorkbookImporter::wipeBusinessData();
    Database::insert('customers', ['code' => 'CUS-WEB0000001', 'name' => 'Data yang sudah ada', 'status' => 'Active']);
    $dry = $c->upload('/import', ['mode' => 'dry', 'apply_corrections' => '1'], ['master' => $fx['master'], 'legacy[0]' => $fx['legacy']]);
    assert_status(200, $dry);
    assert_contains('Hasil pengecekan (belum disimpan)', $dry->body);
    assert_contains('Verifikasi terhadap file legacy', $dry->body);
    assert_same(1, (int) Database::fetchValue('SELECT COUNT(*) FROM customers'), 'cek dulu tidak menyimpan');
    $refused = $c->upload('/import', ['mode' => 'import', 'apply_corrections' => '1'], ['master' => $fx['master']]);
    assert_status(422, $refused);
    assert_contains('Database sudah berisi data', $refused->body);
    assert_same(1, (int) Database::fetchValue('SELECT COUNT(*) FROM customers'));

    // database kosong: import berhasil & tercatat
    WorkbookImporter::wipeBusinessData();
    $ok = $c->upload('/import', ['mode' => 'import', 'apply_corrections' => '1'], ['master' => $fx['master'], 'legacy[0]' => $fx['legacy']]);
    assert_status(200, $ok);
    assert_contains('Import selesai', $ok->body);
    assert_same(6, (int) Database::fetchValue('SELECT COUNT(*) FROM deliveries'));
    assert_true((int) Database::fetchValue('SELECT COUNT(*) FROM customers') > 0);
    assert_true((bool) Database::fetchValue("SELECT 1 FROM audit_logs WHERE action = 'import' AND entity_type = 'import'"));
    $last = json_decode((string) Database::fetchValue("SELECT setting_value FROM settings WHERE setting_key = 'last_import'"), true);
    assert_same(basename($fx['master']), $last['file']);
    assert_same([basename($fx['legacy'])], $last['legacy_files']);
    // file upload sementara sudah dihapus
    assert_same([], glob(APP_ROOT . '/storage/imports/upload-*') ?: []);
    assert_status(403, client_as('Viewer')->upload('/import', ['mode' => 'import'], ['master' => $fx['master']]));
    @unlink($txt);
    @unlink($fake);
    @unlink($fx['master']);
    @unlink($fx['legacy']);
});
