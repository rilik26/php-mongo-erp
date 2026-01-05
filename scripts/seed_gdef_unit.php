<?php
/**
 * scripts/seed_gdef_unit.php
 *
 * Global Grup Tanımı (GDEF) için ilk seed:
 * - GDEF01E: unit / Birim
 * - GDEF01T: unit -> adet / ADET
 *
 * Çalıştırma:
 *   http://localhost/php-mongo-erp/scripts/seed_gdef_unit.php
 */

require_once __DIR__ . '/../core/bootstrap.php';

require_once __DIR__ . '/../app/modules/gdef/GDEF01ERepository.php';
require_once __DIR__ . '/../app/modules/gdef/GDEF01TRepository.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $g = GDEF01ERepository::upsert('unit', 'Birim', null, true);
    $t = GDEF01TRepository::upsert('unit', 'adet', 'ADET', null, true);

    echo "GDEF SEED OK\n";
    echo "Group: unit / Birim\n";
    echo "Item : unit -> adet / ADET\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage();
}
