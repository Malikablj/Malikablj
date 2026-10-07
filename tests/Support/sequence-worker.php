<?php
declare(strict_types=1);

/** Worker konkurensi: mengambil N nomor NPR dalam transaksi terpisah, mencetak satu per baris. */
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

use App\Core\Db;
use App\Core\NumberSequence;

$n = (int) ($argv[1] ?? 10);
$at = new DateTimeImmutable('2099-10-15 10:00:00');
for ($i = 0; $i < $n; $i++) {
    $num = Db::transaction(static function () use ($at): string {
        $r = NumberSequence::nextNprNumber($at);
        usleep(random_int(0, 3000)); // perlebar jendela balapan
        return $r['number'];
    });
    echo $num, "\n";
}
