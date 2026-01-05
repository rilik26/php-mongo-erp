<?php
/**
 * scripts/setup_indexes.php (FINAL)
 *
 * - index name conflict safe
 */

require_once __DIR__ . '/../core/bootstrap.php';

function ensureIndex($colName, $keys, $options = [])
{
  $col = MongoManager::collection($colName);

  $exists = false;
  foreach ($col->listIndexes() as $idx) {
    $k = $idx->getKey();
    if ($k == $keys) { $exists = true; break; }
  }

  if ($exists) return;

  $col->createIndex($keys, $options);
}

try {
  // STOK uniq: tenant + code
  ensureIndex('STOK01E', ['CDEF01_id'=>1,'PERIOD01T_id'=>1,'code'=>1], ['unique'=>true, 'name'=>'uniq_stok_code_per_tenant']);

  // SNAP01E target_key + version
  ensureIndex('SNAP01E', ['target_key'=>1,'version'=>1], ['unique'=>true, 'name'=>'uniq_snap_target_version']);

  // EVENT01E search speed
  ensureIndex('EVENT01E', ['created_at'=>-1], ['name'=>'idx_event_created_at']);
  ensureIndex('EVENT01E', ['target_key'=>1,'created_at'=>-1], ['name'=>'idx_event_target_created']);

  echo "INDEXES OK";
} catch (Throwable $e) {
  echo "ERROR: " . $e->getMessage();
}
