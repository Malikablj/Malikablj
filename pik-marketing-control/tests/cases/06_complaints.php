<?php

declare(strict_types=1);

use App\Helpers\Database;
use App\Helpers\Mailer;
use App\Models\Customer;
use App\Models\PoLine;
use App\Models\ReturnAttachment;

group('Retur & Komplain · bukti, hasil, email QC');

$cmpSetup = static function (): array {
    static $ids = null;
    if ($ids !== null) {
        return $ids;
    }
    $cid = Customer::create(['name' => 'PT Komplain Uji', 'status' => 'Active']);
    $prod = Database::insert('products', ['code' => 'PRD-CMP0000001', 'name' => 'Botol Komplain 30ml', 'unit' => 'pcs']);
    $po = Database::insert('purchase_orders', ['code' => 'PO-CMP0000001', 'order_number' => 'OEF/CMP/001', 'po_number' => 'PO/CMP/001', 'customer_id' => $cid, 'po_date' => today(), 'status' => 'Open']);
    $line = Database::insert('po_lines', ['code' => 'POL-CMP000001', 'po_id' => $po, 'product_id' => $prod, 'order_qty' => 1000]);
    Database::insert('deliveries', ['code' => 'DEL-CMP000001', 'po_id' => $po, 'po_line_id' => $line, 'product_id' => $prod, 'delivery_date' => today(), 'sj_number' => 'SJ-CMP-01', 'delivered_qty' => 1000, 'status' => 'Delivered']);
    $dir = sys_get_temp_dir() . '/pik-cmp-' . getmypid();
    @mkdir($dir);
    file_put_contents($dir . '/foto-cacat.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    file_put_contents($dir . '/laporan-qc.pdf', "%PDF-1.4\n1 0 obj<< /Type /Catalog >>endobj\ntrailer<< /Root 1 0 R >>\n%%EOF\n");
    file_put_contents($dir . '/bukan-gambar.jpg', "ini teks biasa, bukan gambar\n");
    file_put_contents($dir . '/palsu.jpg', "\xFF\xD8\xFF" . str_repeat('A', 64));
    file_put_contents($dir . '/skrip.php', "<?php echo 'x';");
    return $ids = ['customer' => $cid, 'product' => $prod, 'po' => $po, 'line' => $line, 'dir' => $dir];
};

test('komplain: detail wajib, file bukti divalidasi dari ISI file (bukan nama)', function () use ($cmpSetup) {
    $ids = $cmpSetup();
    $c = client_as('Marketing');
    $form = $c->get('/returns/create', ['po_line_id' => $ids['line']]);
    assert_status(200, $form);
    assert_contains('enctype="multipart/form-data"', $form->body);
    $r = $c->upload('/returns', ['case_type' => 'Komplain', 'po_line_id' => (string) $ids['line'], 'return_date' => today(), 'reason' => 'Quality Issue', 'note' => '', 'qc_email' => 'qc@pik.test'],
        ['evidence[0]' => $ids['dir'] . '/bukan-gambar.jpg', 'evidence[1]' => $ids['dir'] . '/palsu.jpg', 'evidence[2]' => $ids['dir'] . '/skrip.php']);
    assert_status(422, $r);
    assert_contains('Detail masalah wajib diisi', $r->body);
    assert_contains('bukan-gambar.jpg: hanya gambar JPG, PNG, WEBP, atau PDF', $r->body);
    assert_contains('palsu.jpg: hanya gambar JPG, PNG, WEBP, atau PDF', $r->body, 'magic bytes JPEG tanpa isi gambar ditolak');
    assert_contains('skrip.php: hanya gambar', $r->body);
    $bad = $c->upload('/returns', ['case_type' => 'Komplain', 'po_line_id' => (string) $ids['line'], 'return_date' => today(), 'reason' => 'Quality Issue', 'note' => 'Botol bocor',
        'qc_email' => 'qc@pik.test, bukan email'], []);
    assert_contains('Email QC tidak valid', $bad->body);
    assert_same(0, (int) Database::fetchValue('SELECT COUNT(*) FROM returns WHERE po_line_id = :l', ['l' => $ids['line']]), 'tidak ada data setengah jadi');
});

test('komplain tersimpan dengan bukti gambar & PDF, email ke QC tercatat, outstanding tidak berubah', function () use ($cmpSetup) {
    $ids = $cmpSetup();
    $c = client_as('Marketing');
    $res = $c->upload('/returns', ['case_type' => 'Komplain', 'po_line_id' => (string) $ids['line'], 'return_date' => today(), 'reason' => 'Quality Issue', 'qty' => '120',
        'note' => 'Botol bocor di bagian leher', 'qc_email' => 'QC@pik.test; kepala.qc@pik.test', 'send_email' => '1'],
        ['evidence[0]' => $ids['dir'] . '/foto-cacat.png', 'evidence[1]' => $ids['dir'] . '/laporan-qc.pdf']);
    assert_redirect($res, '/returns/');
    $row = Database::fetch('SELECT * FROM returns WHERE po_line_id = :l', ['l' => $ids['line']]);
    assert_same('Komplain', $row['case_type']);
    assert_same(null, $row['return_qty'], 'komplain tanpa barang kembali');
    assert_same(120, (int) $row['affected_qty']);
    assert_same('Open', $row['resolution_status']);
    assert_same('QC@pik.test, kepala.qc@pik.test', $row['qc_email']);
    assert_same(0, PoLine::totals($ids['line'])['outstanding_qty'], 'komplain tidak menambah outstanding');
    $files = ReturnAttachment::forReturn((int) $row['id']);
    assert_same(2, count($files));
    assert_same('image/png', $files[0]['mime_type']);
    assert_same('application/pdf', $files[1]['mime_type']);
    assert_true(str_starts_with((string) $files[0]['stored_name'], 'returns/'), 'disimpan di storage/uploads (di luar public)');
    assert_true(is_file(APP_ROOT . '/storage/uploads/' . $files[0]['stored_name']));
    assert_false(str_contains((string) $files[0]['stored_name'], 'foto-cacat'), 'nama file disimpan acak');
    $log = Database::fetch("SELECT * FROM email_logs WHERE entity_type = 'return' AND entity_id = :id", ['id' => $row['id']]);
    assert_true($log !== null, 'percobaan kirim email tercatat');
    assert_same('LOGGED', $log['status'], 'MAIL_DRIVER=log: dicatat, tidak benar-benar dikirim');
    assert_contains('QC@pik.test', $log['recipients']);
    $page = $c->get('/returns/' . $row['id']);
    assert_contains('Email TIDAK dikirim karena server memakai MAIL_DRIVER=log', $page->body, 'user diberi tahu dengan jujur');
    assert_contains('foto-cacat.png', $page->body);
    assert_contains('Belum ada hasil', $page->body);
    $mailLog = (string) @file_get_contents(APP_ROOT . '/storage/logs/mail-' . date('Y-m') . '.log');
    assert_contains('[Komplain] ' . $row['code'], $mailLog);
    assert_contains('Botol bocor di bagian leher', $mailLog);
});

test('file bukti hanya bisa dibuka user berhak, dengan header aman', function () use ($cmpSetup) {
    $ids = $cmpSetup();
    $row = Database::fetch('SELECT * FROM returns WHERE po_line_id = :l', ['l' => $ids['line']]);
    $files = ReturnAttachment::forReturn((int) $row['id']);
    $c = client_as('Viewer');
    $img = $c->raw('/returns/' . $row['id'] . '/files/' . $files[0]['id']);
    assert_status(200, $img);
    assert_same('image/png', $img->headers['content-type'] ?? '');
    assert_same('nosniff', $img->headers['x-content-type-options'] ?? '');
    assert_contains('sandbox', $img->headers['content-security-policy'] ?? '');
    assert_same(file_get_contents($ids['dir'] . '/foto-cacat.png'), $img->body);
    $pdf = $c->raw('/returns/' . $row['id'] . '/files/' . $files[1]['id'], ['download' => '1']);
    assert_contains('attachment', $pdf->headers['content-disposition'] ?? '');
    assert_status(403, client_as('Sales')->raw('/returns/' . $row['id'] . '/files/' . $files[0]['id']), 'Sales tidak punya akses retur');
    assert_status(403, client_as('PPIC')->raw('/returns/' . $row['id'] . '/files/' . $files[0]['id']));
    assert_status(404, $c->raw('/returns/' . $row['id'] . '/files/999999'));
    $otherRet = Database::insert('returns', ['code' => 'RET-CMP0000099', 'po_id' => $ids['po'], 'po_line_id' => $ids['line'], 'product_id' => $ids['product'], 'return_date' => today(), 'case_type' => 'Komplain', 'reason' => 'Other']);
    assert_status(404, $c->raw('/returns/' . $otherRet . '/files/' . $files[0]['id']), 'file milik kasus lain tidak bisa dibuka lewat id kasus lain');
    Database::delete('returns', 'id = :id', ['id' => $otherRet]);
    assert_status(403, $c->post('/returns/' . $row['id'] . '/files/' . $files[0]['id'] . '/delete'), 'Viewer tidak boleh menghapus');
    $anon = new HttpClient(TEST_BASE_URL);
    assert_redirect($anon->raw('/returns/' . $row['id'] . '/files/' . $files[0]['id']), '/login');
    assert_status(404, $anon->raw('/storage/uploads/' . $files[0]['stored_name']), 'folder storage tidak bisa diakses langsung');
});

test('tombol "Tidak selesai" wajib alasan; "Selesai" & buka kembali; hasil dikirim ke QC', function () use ($cmpSetup) {
    $ids = $cmpSetup();
    $row = Database::fetch('SELECT * FROM returns WHERE po_line_id = :l', ['l' => $ids['line']]);
    $id = (int) $row['id'];
    $c = client_as('Marketing');
    $page = $c->get('/returns/' . $id);
    assert_contains('value="Selesai" class="btn btn-success"', $page->body, 'tombol hijau');
    assert_contains('value="Tidak selesai" class="btn btn-danger"', $page->body, 'tombol merah');
    assert_redirect($c->post('/returns/' . $id . '/resolve', ['resolution_status' => 'Tidak selesai', 'resolution_note' => '']), '/returns/' . $id);
    assert_same('Open', Database::fetchValue('SELECT resolution_status FROM returns WHERE id = :id', ['id' => $id]));
    assert_contains('Tuliskan alasan', $c->get('/returns/' . $id)->body);
    assert_redirect($c->post('/returns/' . $id . '/resolve', ['resolution_status' => 'Batal', 'resolution_note' => 'x']), '/returns/' . $id);
    assert_same('Open', Database::fetchValue('SELECT resolution_status FROM returns WHERE id = :id', ['id' => $id]), 'status tidak dikenal ditolak');
    $before = (int) Database::fetchValue("SELECT COUNT(*) FROM email_logs WHERE entity_type = 'return' AND entity_id = :id", ['id' => $id]);
    assert_redirect($c->post('/returns/' . $id . '/resolve', ['resolution_status' => 'Tidak selesai', 'resolution_note' => 'Customer menolak penggantian, minta potongan harga']), '/returns/' . $id);
    $after = Database::fetch('SELECT * FROM returns WHERE id = :id', ['id' => $id]);
    assert_same('Tidak selesai', $after['resolution_status']);
    assert_same('Customer menolak penggantian, minta potongan harga', $after['resolution_note']);
    assert_true($after['resolved_at'] !== null && $after['resolved_by'] !== null);
    assert_same($before + 1, (int) Database::fetchValue("SELECT COUNT(*) FROM email_logs WHERE entity_type = 'return' AND entity_id = :id", ['id' => $id]), 'hasil otomatis dikirim ke QC');
    assert_contains('Alasan tidak selesai', (string) file_get_contents(APP_ROOT . '/storage/logs/mail-' . date('Y-m') . '.log'));
    // sudah ada hasil → tidak bisa diubah sebelum dibuka kembali
    assert_redirect($c->post('/returns/' . $id . '/resolve', ['resolution_status' => 'Selesai']), '/returns/' . $id);
    assert_same('Tidak selesai', Database::fetchValue('SELECT resolution_status FROM returns WHERE id = :id', ['id' => $id]));
    assert_redirect($c->post('/returns/' . $id . '/reopen'), '/returns/' . $id);
    assert_same('Open', Database::fetchValue('SELECT resolution_status FROM returns WHERE id = :id', ['id' => $id]));
    assert_redirect($c->post('/returns/' . $id . '/resolve', ['resolution_status' => 'Selesai', 'resolution_note' => 'Diganti 120 pcs baru']), '/returns/' . $id);
    assert_same('Selesai', Database::fetchValue('SELECT resolution_status FROM returns WHERE id = :id', ['id' => $id]));
    assert_true((bool) Database::fetchValue("SELECT 1 FROM audit_logs WHERE entity_type = 'return' AND entity_id = :id AND changes LIKE '%Tidak selesai%'", ['id' => $id]), 'riwayat hasil tetap di audit log');
    // kirim ulang manual
    assert_redirect($c->post('/returns/' . $id . '/email'), '/returns/' . $id);
    assert_same($before + 3, (int) Database::fetchValue("SELECT COUNT(*) FROM email_logs WHERE entity_type = 'return' AND entity_id = :id", ['id' => $id]));
    // otorisasi aksi
    $v = client_as('Viewer');
    assert_status(403, $v->post('/returns/' . $id . '/resolve', ['resolution_status' => 'Selesai']));
    assert_status(403, $v->post('/returns/' . $id . '/reopen'));
    assert_status(403, client_as('Management')->post('/returns/' . $id . '/email'));
    assert_status(200, client_as('Management')->get('/returns/' . $id));
});

test('retur (barang kembali): qty wajib & menambah outstanding; tambah/hapus bukti; hapus kasus menghapus file', function () use ($cmpSetup) {
    $ids = $cmpSetup();
    $c = client_as('Marketing');
    $noQty = $c->upload('/returns', ['case_type' => 'Retur', 'po_line_id' => (string) $ids['line'], 'return_date' => today(), 'reason' => 'Damage', 'qty' => ''], []);
    assert_contains('Qty retur wajib diisi', $noQty->body);
    assert_redirect($c->upload('/returns', ['case_type' => 'Retur', 'po_line_id' => (string) $ids['line'], 'return_date' => today(), 'reason' => 'Damage', 'qty' => '50', 'note' => 'Pecah'], []), '/returns/');
    $ret = Database::fetch("SELECT * FROM returns WHERE po_line_id = :l AND case_type = 'Retur'", ['l' => $ids['line']]);
    assert_same(50, (int) $ret['return_qty']);
    assert_same(null, $ret['affected_qty']);
    assert_same(50, PoLine::totals($ids['line'])['outstanding_qty'], 'retur menambah outstanding');
    assert_same(0, (int) Database::fetchValue('SELECT COUNT(*) FROM email_logs WHERE entity_id = :id', ['id' => $ret['id']]), 'tanpa email QC tidak dikirim');
    assert_redirect($c->upload('/returns/' . $ret['id'] . '/files', [], ['evidence[0]' => $ids['dir'] . '/foto-cacat.png']), '/returns/' . $ret['id']);
    $files = ReturnAttachment::forReturn((int) $ret['id']);
    assert_same(1, count($files));
    $path = APP_ROOT . '/storage/uploads/' . $files[0]['stored_name'];
    assert_true(is_file($path));
    assert_redirect($c->upload('/returns/' . $ret['id'] . '/files', [], ['evidence[0]' => $ids['dir'] . '/bukan-gambar.jpg']), '/returns/' . $ret['id']);
    assert_contains('Bukti gagal diunggah', $c->get('/returns/' . $ret['id'])->body);
    assert_redirect($c->post('/returns/' . $ret['id'] . '/files/' . $files[0]['id'] . '/delete'), '/returns/' . $ret['id']);
    assert_false(is_file($path), 'file fisik ikut dihapus');
    assert_redirect($c->upload('/returns/' . $ret['id'] . '/files', [], ['evidence[0]' => $ids['dir'] . '/laporan-qc.pdf']), '/returns/' . $ret['id']);
    $pdfPath = APP_ROOT . '/storage/uploads/' . ReturnAttachment::forReturn((int) $ret['id'])[0]['stored_name'];
    // ubah jenis menjadi komplain → qty pindah ke qty bermasalah, outstanding kembali
    assert_redirect($c->upload('/returns/' . $ret['id'], ['case_type' => 'Komplain', 'po_line_id' => (string) $ids['line'], 'return_date' => today(), 'reason' => 'Damage', 'qty' => '50', 'note' => 'Pecah, tidak dikembalikan'], []), '/returns/' . $ret['id']);
    assert_same(0, PoLine::totals($ids['line'])['outstanding_qty']);
    assert_redirect(client_as('Admin')->post('/returns/' . $ret['id'] . '/delete'), '/purchase-orders/' . $ids['po']);
    assert_false((bool) Database::fetchValue('SELECT 1 FROM returns WHERE id = :id', ['id' => $ret['id']]));
    assert_false((bool) Database::fetchValue('SELECT 1 FROM return_attachments WHERE return_id = :id', ['id' => $ret['id']]));
    assert_false(is_file($pdfPath), 'file bukti ikut terhapus');
});

test('laporan Retur & Komplain: hasil, alasan, bukti, status email; export', function () use ($cmpSetup) {
    $ids = $cmpSetup();
    $row = Database::fetch('SELECT * FROM returns WHERE po_line_id = :l', ['l' => $ids['line']]);
    $m = client_as('Management');
    $rep = $m->get('/reports/complaint', ['customer_id' => $ids['customer']]);
    assert_status(200, $rep);
    foreach ([$row['code'], 'Selesai', 'Masalah kualitas', 'OEF/CMP/001', 'Botol Komplain 30ml', 'Diganti 120 pcs baru'] as $text) {
        assert_contains($text, $rep->body, $text);
    }
    $csv = $m->get('/reports/complaint/export', ['format' => 'csv', 'customer_id' => $ids['customer']]);
    assert_status(200, $csv);
    assert_contains($row['code'], $csv->body);
    assert_contains('Dicatat (log)', $csv->body, 'status email QC ikut diekspor');
    $list = client_as('Marketing')->get('/returns', ['resolution' => 'Selesai', 'type' => 'Komplain']);
    assert_contains($row['code'], $list->body);
    assert_contains('2 bukti', $list->body);
    assert_not_contains($row['code'], client_as('Marketing')->get('/returns', ['resolution' => 'Open'])->body);
});

test('SMTP: email + lampiran terkirim ke server SMTP (server tiruan), gagal koneksi dilaporkan jujur', function () use ($cmpSetup) {
    $ids = $cmpSetup();
    $port = random_int(19000, 19900);
    $transcript = $ids['dir'] . '/smtp.txt';
    $script = $ids['dir'] . '/fake_smtp.php';
    // server SMTP tiruan: melayani beberapa sesi berurutan, mencatat semua baris yang diterima
    file_put_contents($script, <<<'PHP'
<?php
[$self, $port, $out] = $argv;
$srv = stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);
while ($conn = @stream_socket_accept($srv, 20)) {
    $say = static function (string $s) use ($conn): void { fwrite($conn, $s . "\r\n"); };
    $say('220 fake.smtp ESMTP');
    $inData = false;
    $auth = 0;
    while (($line = fgets($conn)) !== false) {
        file_put_contents($out, $line, FILE_APPEND);
        $t = rtrim($line, "\r\n");
        if ($inData) {
            if ($t === '.') { $inData = false; $say('250 2.0.0 queued'); }
            continue;
        }
        if ($auth === 1) { $auth = 2; $say('334 UGFzc3dvcmQ6'); continue; }
        if ($auth === 2) { $auth = 3; $say('235 2.7.0 ok'); continue; }
        $u = strtoupper($t);
        if (str_starts_with($u, 'EHLO')) { fwrite($conn, "250-fake.smtp\r\n250-AUTH LOGIN\r\n250 OK\r\n"); }
        elseif ($u === 'AUTH LOGIN') { $auth = 1; $say('334 VXNlcm5hbWU6'); }
        elseif (str_starts_with($u, 'MAIL FROM') || str_starts_with($u, 'RCPT TO')) { $say('250 OK'); }
        elseif ($u === 'DATA') { $inData = true; $say('354 go'); }
        elseif ($u === 'QUIT') { $say('221 bye'); break; }
        else { $say('500 unknown'); }
    }
    fclose($conn);
}
PHP);
    $proc = proc_open([PHP_BINARY, $script, (string) $port, $transcript], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    for ($i = 0; $i < 60; $i++) {
        $probe = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.1);
        if ($probe) {
            fclose($probe);
            break;
        }
        usleep(50000);
    }
    Mailer::$override = ['driver' => 'smtp', 'host' => '127.0.0.1', 'port' => $port, 'encryption' => 'none', 'username' => 'qc-bot', 'password' => 'rahasia',
        'from' => 'noreply@pik.test', 'from_name' => 'PIK Marketing Control', 'timeout' => 5];
    try {
        $r = Mailer::send(['qc@pik.test'], 'Uji SMTP — Komplain', "Baris satu\n.baris diawali titik", [['name' => 'foto cacat.png', 'path' => $ids['dir'] . '/foto-cacat.png', 'mime' => 'image/png']]);
        assert_same('SENT', $r['status'], (string) $r['error']);
        assert_same(1, $r['attached']);
        $t = (string) @file_get_contents($transcript);
        assert_contains('MAIL FROM:<noreply@pik.test>', $t);
        assert_contains('RCPT TO:<qc@pik.test>', $t);
        assert_contains(base64_encode('qc-bot'), $t, 'AUTH LOGIN');
        assert_contains('Subject: =?UTF-8?B?' . base64_encode('Uji SMTP — Komplain') . '?=', $t, 'subjek UTF-8 di-encode');
        assert_contains('Content-Disposition: attachment; filename="foto cacat.png"', $t);
        assert_contains(substr(base64_encode((string) file_get_contents($ids['dir'] . '/foto-cacat.png')), 0, 40), $t, 'isi lampiran (base64)');
        assert_contains(base64_encode("Baris satu\n.baris diawali titik"), str_replace("\r\n", '', $t), 'isi email (base64)');
        // header injection: CR/LF di subjek tidak membuat header baru
        $r2 = Mailer::send(['qc@pik.test'], "Subjek\r\nBcc: korban@contoh.com", 'isi');
        assert_same('SENT', $r2['status'], (string) $r2['error']);
        $t = (string) file_get_contents($transcript);
        assert_contains('Subject: Subjek  Bcc: korban@contoh.com', $t);
        assert_false((bool) preg_match('/^Bcc:/mi', $t), 'tidak ada header Bcc sisipan');
        assert_same(null, Mailer::parseList("a@b.co\r\nBcc: c@d.co"), 'daftar email dengan header sisipan ditolak');
        assert_same(['a@b.co', 'c@d.co'], Mailer::parseList('a@b.co; c@d.co, A@B.co'));
        // server tidak bisa dihubungi → FAILED dengan pesan jelas (tidak pura-pura terkirim)
        Mailer::$override['port'] = $port === 19900 ? 19901 : $port + 1;
        $r3 = Mailer::send(['qc@pik.test'], 'Uji gagal', 'isi');
        assert_same('FAILED', $r3['status']);
        assert_contains('Tidak dapat terhubung ke server email', (string) $r3['error']);
        Mailer::$override = ['driver' => 'smtp', 'host' => '127.0.0.1', 'port' => $port, 'encryption' => 'none', 'username' => '', 'password' => '', 'from' => '', 'timeout' => 5];
        $r4 = Mailer::send(['qc@pik.test'], 'Uji', 'isi');
        assert_same('FAILED', $r4['status']);
        assert_contains('MAIL_FROM_ADDRESS', (string) $r4['error']);
    } finally {
        Mailer::$override = null;
        proc_terminate($proc);
        proc_close($proc);
    }
});

test('bersihkan file uji di storage/uploads', function () use ($cmpSetup) {
    $ids = $cmpSetup();
    foreach (Database::fetchAll('SELECT * FROM return_attachments') as $att) {
        ReturnAttachment::remove($att);
    }
    foreach (glob($ids['dir'] . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($ids['dir']);
    assert_same(0, (int) Database::fetchValue('SELECT COUNT(*) FROM return_attachments'));
});
