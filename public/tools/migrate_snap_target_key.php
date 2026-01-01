<?php
/**
 * public/tools/migrate_snap_target_key.php
 *
 * Tek seferlik migration:
 * - SNAP01E koleksiyonunda target_key null/empty olan kayıtları düzeltir.
 *
 * Çalıştırma:
 *   php public/tools/migrate_snap_target_key.php
 */

require_once __DIR__ . '/../../core/bootstrap.php';

function toArray($v) {
  if ($v instanceof MongoDB\Model\BSONDocument || $v instanceof MongoDB\Model\BSONArray) return $v->getArrayCopy();
  return $v;
}

function mkTargetKey(array $snap): ?string {
  $t = toArray($snap['target'] ?? []);
  $c = toArray($snap['context'] ?? []);

  $module = strtolower(trim((string)($t['module'] ?? '')));
  $docType = strtoupper(trim((string)($t['doc_type'] ?? '')));
  $docId = trim((string)($t['doc_id'] ?? ''));

  if ($module === '' || $docType === '' || $docId === '') return null;

  $cdef = (string)($c['CDEF01_id'] ?? '');
  $periodId = (string)($c['period_id'] ?? ($c['PERIOD01T_id'] ?? ''));
  $facility = $c['facility_id'] ?? ($c['FACILITY01_id'] ?? null);
  $facilityStr = ($facility === null || $facility === '') ? 'null' : (string)$facility;

  return $module . '|' . $docType . '|' . $docId . '|' . $cdef . '|' . $periodId . '|' . $facilityStr;
}

$col = MongoManager::collection('SNAP01E');

$filter = [
  '$or' => [
    ['target_key' => null],
    ['target_key' => ''],
    ['target_key' => ['$exists' => false]],
  ]
];

$cur = $col->find($filter, ['sort' => ['created_at' => 1]]);

$total = 0;
$fixed = 0;
$skipped = 0;
$conflict = 0;

foreach ($cur as $snap) {
  $total++;
  $snapArr = toArray($snap);

  $id = (string)($snapArr['_id'] ?? '');
  $ver = (int)($snapArr['version'] ?? 0);

  $newKey = mkTargetKey($snapArr);
  if (!$newKey) {
    $skipped++;
    echo "[SKIP] {$id} (cannot build target_key)\n";
    continue;
  }

  // Unique index: (target_key, version) çakışıyor mu?
  $exists = $col->findOne(['target_key' => $newKey, 'version' => $ver], ['projection' => ['_id' => 1]]);
  if ($exists) {
    $conflict++;
    echo "[CONFLICT] {$id} -> {$newKey} v{$ver} already exists\n";
    continue;
  }

  $res = $col->updateOne(
    ['_id' => new MongoDB\BSON\ObjectId($id)],
    ['$set' => ['target_key' => $newKey]]
  );

  if ((int)$res->getModifiedCount() > 0) {
    $fixed++;
    echo "[FIX] {$id} -> {$newKey}\n";
  } else {
    $skipped++;
    echo "[SKIP] {$id} (no change)\n";
  }
}

echo "\n=== DONE ===\n";
echo "Total scanned : {$total}\n";
echo "Fixed        : {$fixed}\n";
echo "Skipped      : {$skipped}\n";
echo "Conflicts    : {$conflict}\n";
