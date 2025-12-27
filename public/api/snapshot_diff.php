<?php
/**
 * public/api/snapshot_diff.php (FINAL)
 *
 * Kullanım:
 *  A) snapshot_id ile:
 *     /public/api/snapshot_diff.php?snapshot_id=...
 *
 *  B) target_key ile:
 *     /public/api/snapshot_diff.php?target_key=module|doc_type|doc_id|CDEF01_id|period_id|facility_id
 *
 * Çıktı:
 * {
 *   ok: true,
 *   mode: "lang" | "generic",
 *   target_key: "...",
 *   prev: { id, version } | null,
 *   latest: { id, version },
 *   diff: {...},
 *   summary: {...},
 *   note: null | "no_prev_snapshot"
 * }
 */

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/snapshot/SnapshotDiff.php';

header('Content-Type: application/json; charset=utf-8');

function j($a, int $code = 200): void {
  http_response_code($code);
  echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function bson_to_array($v) {
  if ($v instanceof MongoDB\Model\BSONDocument || $v instanceof MongoDB\Model\BSONArray) {
    $v = $v->getArrayCopy();
  }
  if (is_array($v)) {
    $out = [];
    foreach ($v as $k => $vv) $out[$k] = bson_to_array($vv);
    return $out;
  }
  if ($v instanceof MongoDB\BSON\UTCDateTime) return $v->toDateTime()->format('c');
  if ($v instanceof MongoDB\BSON\ObjectId) return (string)$v;
  return $v;
}

function find_snapshot_by_id(string $id): ?array {
  try {
    $oid = new MongoDB\BSON\ObjectId($id);
  } catch (Throwable $e) {
    return null;
  }

  $doc = MongoManager::collection('SNAP01E')->findOne(['_id' => $oid]);
  if (!$doc) return null;

  if ($doc instanceof MongoDB\Model\BSONDocument) $doc = $doc->getArrayCopy();
  return bson_to_array($doc);
}

function find_latest_snapshot_by_target_key(string $targetKey): ?array {
  $doc = MongoManager::collection('SNAP01E')->findOne(
    ['target_key' => $targetKey],
    ['sort' => ['version' => -1]]
  );
  if (!$doc) return null;
  if ($doc instanceof MongoDB\Model\BSONDocument) $doc = $doc->getArrayCopy();
  return bson_to_array($doc);
}

function pick_prev_snapshot(array $latest): ?array {
  $prevId = $latest['prev_snapshot_id'] ?? null;
  if (!$prevId) return null;

  // prev_snapshot_id bazen string, bazen ObjectId stringe çevrilmiş olabilir
  $prevId = (string)$prevId;
  if ($prevId === '') return null;

  return find_snapshot_by_id($prevId);
}

function is_lang_snapshot(array $snap): bool {
  // LANG snapshot: data.rows = { key => {module,key,tr,en} }
  $rows = $snap['data']['rows'] ?? null;
  return is_array($rows);
}

// -------------------- input --------------------
$snapshotId = trim($_GET['snapshot_id'] ?? '');
$targetKey  = trim($_GET['target_key'] ?? '');

if ($snapshotId === '' && $targetKey === '') {
  j(['ok' => false, 'error' => 'snapshot_id_or_target_key_required'], 400);
}

// -------------------- load snapshots --------------------
$latest = null;

if ($snapshotId !== '') {
  $latest = find_snapshot_by_id($snapshotId);
  if (!$latest) j(['ok'=>false,'error'=>'snapshot_not_found'], 404);
  $targetKey = (string)($latest['target_key'] ?? $targetKey);
} else {
  $latest = find_latest_snapshot_by_target_key($targetKey);
  if (!$latest) j(['ok'=>false,'error'=>'latest_snapshot_not_found_for_target_key'], 404);
}

$prev = pick_prev_snapshot($latest);

if (!$prev) {
  // prev yoksa diff yok — ama latest bilgisi dönsün
  $mode = is_lang_snapshot($latest) ? 'lang' : 'generic';
  j([
    'ok' => true,
    'mode' => $mode,
    'target_key' => $targetKey,
    'prev' => null,
    'latest' => [
      'id' => (string)($latest['_id'] ?? ''),
      'version' => (int)($latest['version'] ?? 0),
    ],
    'diff' => [
      'added_keys' => [],
      'removed_keys' => [],
      'changed_keys' => [],
    ],
    'summary' => [
      'mode' => $mode,
      'note' => 'no_prev_snapshot',
    ],
    'note' => 'no_prev_snapshot',
  ]);
}

// -------------------- compute diff --------------------
$mode = null;
$diff = null;
$summary = null;

if (is_lang_snapshot($latest) && is_lang_snapshot($prev)) {
  $mode = 'lang';

  $oldRows = (array)($prev['data']['rows'] ?? []);
  $newRows = (array)($latest['data']['rows'] ?? []);

  $diff = SnapshotDiff::diffLangRows($oldRows, $newRows);
  $summary = SnapshotDiff::summarizeLangDiff($diff, 12);
} else {
  $mode = 'generic';

  // GENEL: data komple diff (rows değil)
  $oldData = (array)($prev['data'] ?? []);
  $newData = (array)($latest['data'] ?? []);

  $diff = SnapshotDiff::diffAssocPaths($oldData, $newData);
  $summary = SnapshotDiff::summarizeGenericDiff($diff, 12);
}

// -------------------- response --------------------
j([
  'ok' => true,
  'mode' => $mode,
  'target_key' => $targetKey,

  'prev' => [
    'id' => (string)($prev['_id'] ?? ''),
    'version' => (int)($prev['version'] ?? 0),
  ],
  'latest' => [
    'id' => (string)($latest['_id'] ?? ''),
    'version' => (int)($latest['version'] ?? 0),
  ],

  'diff' => $diff,
  'summary' => $summary,
  'note' => null,
]);
