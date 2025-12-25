<?php
/**
 * seed_periods.php
 *
 * AMAÇ:
 * - PERIOD01T koleksiyonunu firma bazlı dönemlerle doldurmak
 *
 * NASIL KULLANILIR:
 * - CLI: php scripts/seed_periods.php 694849ac619312509a5e4535
 *
 * NOT:
 * - Argüman: CDEF01_id (firma id string)
 * - Mevcut dönemi varsa tekrar eklemez (upsert)
 */

require_once __DIR__ . '/../core/bootstrap.php';

use MongoDB\BSON\UTCDateTime;

$companyId = $argv[1] ?? null;

if (!$companyId) {
    echo "KULLANIM: php scripts/seed_periods.php <CDEF01_id>\n";
    exit(1);
}

$now = new UTCDateTime();

$periods = [
    ['period_id' => '2024', 'title' => '2024 Dönemi', 'is_open' => false],
    ['period_id' => '2025', 'title' => '2025 Dönemi', 'is_open' => true],
    ['period_id' => '2026', 'title' => '2026 Dönemi', 'is_open' => false],
];

$col = MongoManager::collection('PERIOD01T');

foreach ($periods as $p) {
    $col->updateOne(
        ['CDEF01_id' => $companyId, 'period_id' => $p['period_id']],
        [
            '$set' => [
                'CDEF01_id'  => $companyId,
                'period_id'  => $p['period_id'],
                'title'      => $p['title'],
                'is_open'    => $p['is_open'],
                'updated_at' => $now,
            ],
            '$setOnInsert' => [
                'created_at' => $now
            ]
        ],
        ['upsert' => true]
    );

    echo "OK: {$companyId} / {$p['period_id']} (open=" . ($p['is_open'] ? 'true' : 'false') . ")\n";
}

echo "Seed tamam.\n";
