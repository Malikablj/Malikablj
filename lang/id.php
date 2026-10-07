<?php
declare(strict_types=1);

/**
 * Katalog teks antarmuka (id). Digabung dari lang/id/*.php (satu file per modul).
 * Kunci harus identik dengan katalog bahasa lain (diuji otomatis: tests/Unit/I18nTest.php).
 */
$catalog = [];
foreach (glob(__DIR__ . '/id/*.php') ?: [] as $file) {
    $catalog = array_merge($catalog, require $file);
}
return $catalog;
