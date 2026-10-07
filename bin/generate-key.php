<?php
declare(strict_types=1);

/** Cetak APP_KEY baru (32 byte, base64) untuk .env. */
echo 'APP_KEY=base64:' . base64_encode(random_bytes(32)) . PHP_EOL;
