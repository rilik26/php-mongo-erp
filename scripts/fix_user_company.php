<?php
/**
 * fix_user_company.php
 *
 * AMAÇ:
 * - UDEF01E içindeki bir kullanıcıya doğru CDEF01_id atamak (tek seferlik düzeltme)
 *
 * KULLANIM:
 * php scripts/fix_user_company.php admin 694849ac619312509a5e4535
 */

require_once __DIR__ . '/../core/bootstrap.php';

$username  = $argv[1] ?? null;
$companyId = $argv[2] ?? null;

if (!$username || !$companyId) {
    echo "KULLANIM: php scripts/fix_user_company.php <username> <CDEF01_id>\n";
    exit(1);
}

$result = MongoManager::collection('UDEF01E')->updateOne(
    ['username' => $username],
    ['$set' => ['CDEF01_id' => $companyId]]
);

echo "Matched: " . $result->getMatchedCount() . "\n";
echo "Modified: " . $result->getModifiedCount() . "\n";
echo "OK\n";
