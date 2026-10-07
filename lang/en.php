<?php
declare(strict_types=1);

/**
 * Katalog teks antarmuka (en). Digabung dari lang/en/*.php (satu file per modul).
 * Kunci harus identik dengan katalog bahasa lain (diuji otomatis: tests/Unit/I18nTest.php).
 */
$catalog = [];
foreach (glob(__DIR__ . '/en/*.php') ?: [] as $file) {
    $catalog = array_merge($catalog, require $file);
}
return $catalog;
