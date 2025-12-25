<?php
/**
 * seed_perm_first.php
 *
 * AMAÇ:
 * - admin rolünü oluştur
 * - admin'e lang.manage yetkisi ver
 */

require_once __DIR__ . '/../core/bootstrap.php';

$roleCol = MongoManager::collection('ROLE01E');
$permCol = MongoManager::collection('PERM01T');

// admin rol
$roleCol->updateOne(
    ['role_code' => 'admin'],
    ['$set' => ['title' => 'Admin', 'is_active' => true]],
    ['upsert' => true]
);

// admin -> lang.manage
$permCol->updateOne(
    ['role_code' => 'admin', 'perm' => 'lang.manage'],
    ['$set' => ['allow' => true]],
    ['upsert' => true]
);

echo "First permission seed tamam: admin -> lang.manage\n";
