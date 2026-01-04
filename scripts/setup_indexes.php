<?php
/**
 * scripts/setup_indexes.php
 * STOK01E için MongoDB index kurulum scripti
 *
 * Not: MongoManager'a bağımlı DEĞİL. Direkt MongoDB\Client kullanır.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use MongoDB\Client;

$config = require __DIR__ . '/../config/database/mongo.php';

$uri = $config['uri'] ?? null;
$dbName = $config['database'] ?? null;

if (!$uri || !$dbName) {
  die("Mongo config missing (uri/database)\n");
}

$client = new Client($uri);
$db = $client->selectDatabase($dbName);


// --- STOK01E indexes (FINAL - code unique) ---
$stok = $db->selectCollection('STOK01E');

// eski uniq index adı varsa (stok_kodu üstünden), güvenli şekilde sil
try { $stok->dropIndex('uniq_stok_kodu_per_tenant'); } catch (Throwable $e) {}

// yeni unique: {CDEF01_id, PERIOD01T_id, code}
$stok->createIndex(
  ['CDEF01_id' => 1, 'PERIOD01T_id' => 1, 'code' => 1],
  ['name' => 'uniq_stok_code_per_tenant', 'unique' => true]
);

// listeleme hızlansın
$stok->createIndex(
  ['CDEF01_id' => 1, 'PERIOD01T_id' => 1, 'updated_at' => -1],
  ['name' => 'idx_stok_tenant_updated']
);
