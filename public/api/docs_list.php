<?php
/**
 * public/api/docs_list.php
 *
 * Evrak Listesi API (V1)
 * - SNAP01E üzerinden "latest snapshot per target_key" listeler
 * - LOCK01E ile birleştirir (aktif lock varsa)
 *
 * Response:
 * {
 *   ok: true,
 *   rows: [
 *     {
 *       target_key, module, doc_type, doc_id, doc_no,
 *       snapshot_id, version, created_at, username,
 *       lock: { status, username, expires_at, session_id } | null
 *     }
 *   ]
 * }
 */

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/auth/SessionManager.php';
require_once __DIR__ . '/../../core/base/Context.php';
require_once __DIR__ . '/../../core/base/ContextException.php';

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

SessionManager::start();
$ctx = [];
try {
  if (isset($_SESSION['context']) && is_array($_SESSION['context'])) {
    Context::bootFromSession();
    $ctx = Context::get();
  } else {
    j(['ok'=>false,'error'=>'login_required'], 401);
  }
} catch (Throwable $e) {
  j(['ok'=>false,'error'=>'login_required'], 401);
}

// filters (şimdilik minimal)
$limit = (int)($_GET['limit'] ?? 500);
if ($limit < 1) $limit = 1;
if ($limit > 2000) $limit = 2000;

// ---- SNAP01E: latest snapshot per target_key ----
// aggregation: sort by version desc then group first doc per target_key
$pipe = [
  ['$sort' => ['target_key' => 1, 'version' => -1]],
  ['$group' => [
    '_id' => '$target_key',
    'doc' => ['$first' => '$$ROOT']
  ]],
  ['$replaceRoot' => ['newRoot' => '$doc']],
  ['$limit' => $limit],
];

$snapsCur = MongoManager::collection('SNAP01E')->aggregate($pipe, [
  'allowDiskUse' => true,
]);

$snaps = [];
$targetKeys = [];
foreach ($snapsCur as $s) {
  $a = bson_to_array($s);
  $snaps[] = $a;
  if (!empty($a['target_key'])) $targetKeys[] = (string)$a['target_key'];
}

$targetKeys = array_values(array_unique($targetKeys));

// ---- LOCK01E: aktif lockları çek ----
$locksMap = [];
if (!empty($targetKeys)) {
  $nowMs = (int) floor(microtime(true) * 1000);

  $locksCur = MongoManager::collection('LOCK01E')->find([
    'target_key' => ['$in' => $targetKeys],
    'expires_at' => ['$gt' => new MongoDB\BSON\UTCDateTime($nowMs)],
  ], [
    'projection' => [
      'target_key' => 1,
      'status' => 1,
      'expires_at' => 1,
      'context.username' => 1,
      'context.session_id' => 1,
      'locked_at' => 1,
    ],
  ]);

  foreach ($locksCur as $l) {
    $l = bson_to_array($l);
    $tk = (string)($l['target_key'] ?? '');
    if ($tk !== '') $locksMap[$tk] = $l;
  }
}

// ---- merge rows ----
$rows = [];
foreach ($snaps as $s) {
  $tk = (string)($s['target_key'] ?? '');
  $t = (array)($s['target'] ?? []);

  $row = [
    'target_key'  => $tk,
    'module'      => (string)($t['module'] ?? ''),
    'doc_type'    => (string)($t['doc_type'] ?? ''),
    'doc_id'      => (string)($t['doc_id'] ?? ''),
    'doc_no'      => (string)($t['doc_no'] ?? ''),
    'snapshot_id' => (string)($s['_id'] ?? ''),
    'version'     => (int)($s['version'] ?? 0),
    'created_at'  => (string)($s['created_at'] ?? ''),
    'username'    => (string)($s['context']['username'] ?? ''),
    'lock'        => null,
  ];

  if ($tk !== '' && isset($locksMap[$tk])) {
    $l = $locksMap[$tk];
    $row['lock'] = [
      'status'     => (string)($l['status'] ?? ''),
      'username'   => (string)($l['context']['username'] ?? ''),
      'session_id' => (string)($l['context']['session_id'] ?? ''),
      'expires_at' => (string)($l['expires_at'] ?? ''),
      'locked_at'  => (string)($l['locked_at'] ?? ''),
    ];
  }

  $rows[] = $row;
}

j(['ok'=>true, 'rows'=>$rows]);
