<?php
/**
 * public/api/lock_status.php
 *
 * Amaç:
 * - UI listelerinde (DataTables) satır bazlı lock ikonları göstermek için
 * - Toplu lock sorgusu: doc_id listesi ver, lock map dön.
 *
 * POST JSON:
 * {
 *   "module": "i18n",
 *   "doc_type": "LANG01T",
 *   "doc_ids": ["DICT", "INV-123", ...]
 * }
 *
 * Response:
 * {
 *   ok: true,
 *   locks: {
 *     "DICT": {
 *       lock_id: "...",
 *       status: "editing",
 *       username: "admin",
 *       session_id: "...",
 *       expires_at: "2025-12-27T12:34:56+00:00",
 *       ttl_left_sec: 123
 *     }
 *   }
 * }
 */

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/auth/SessionManager.php';
require_once __DIR__ . '/../../core/base/Context.php';

header('Content-Type: application/json; charset=utf-8');

function j($a, int $code = 200): void {
  http_response_code($code);
  echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function read_json_body(): array {
  $raw = file_get_contents('php://input');
  if (!$raw) return [];
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function utc_to_iso($v): ?string {
  if ($v instanceof MongoDB\BSON\UTCDateTime) {
    return $v->toDateTime()->format('c');
  }
  if (is_string($v) && $v !== '') return $v;
  return null;
}

SessionManager::start();

// Context (tenant filtresi için)
$ctx = [];
try {
  if (class_exists('Context')) {
    // session'dan boot edilmiş olmayabilir ama genelde var
    if (isset($_SESSION['context']) && is_array($_SESSION['context'])) {
      Context::bootFromSession();
      $ctx = Context::get();
    }
  }
} catch (Throwable $e) {
  $ctx = $_SESSION['context'] ?? [];
}

$body = read_json_body();

$module  = trim((string)($body['module'] ?? ''));
$docType = trim((string)($body['doc_type'] ?? ''));
$docIds  = $body['doc_ids'] ?? [];

if ($module === '' || $docType === '' || !is_array($docIds) || empty($docIds)) {
  j(['ok'=>false,'error'=>'module,doc_type,doc_ids_required'], 400);
}

// doc_ids sanitize
$docIds = array_values(array_unique(array_filter(array_map(function($x){
  $s = trim((string)$x);
  return $s !== '' ? $s : null;
}, $docIds))));

if (empty($docIds)) {
  j(['ok'=>true,'locks'=>[]]);
}

$nowMs = (int) floor(microtime(true) * 1000);
$nowUtc = new MongoDB\BSON\UTCDateTime($nowMs);

// tenant filters
$cdef     = $ctx['CDEF01_id'] ?? null;
$period   = $ctx['period_id'] ?? null;
$facility = $ctx['facility_id'] ?? null;

// base filter
$filter = [
  'target.module'   => $module,
  'target.doc_type' => $docType,
  'target.doc_id'   => ['$in' => $docIds],

  // aktif lock
  'expires_at' => ['$gt' => $nowUtc],
];

// aynı firma içinde kalsın
if ($cdef) $filter['context.CDEF01_id'] = $cdef;

// period: hem current hem GLOBAL kabul edelim (senin yapında vardı)
if ($period) {
  $filter['$or'] = [
    ['context.period_id' => $period],
    ['context.period_id' => 'GLOBAL'],
  ];
}

// facility sabitlenmişse ekleyelim (null ise dokunmayalım)
if ($facility !== null) $filter['context.facility_id'] = $facility;

$cur = MongoManager::collection('LOCK01E')->find(
  $filter,
  [
    'projection' => [
      '_id' => 1,
      'target.doc_id' => 1,
      'status' => 1,
      'expires_at' => 1,
      'context.username' => 1,
      'context.session_id' => 1,
    ],
    'sort' => ['expires_at' => 1],
    'limit' => 2000,
  ]
);

$locks = [];

foreach ($cur as $doc) {
  if ($doc instanceof MongoDB\Model\BSONDocument) $doc = $doc->getArrayCopy();

  $id = $doc['_id'] ?? null;
  if ($id instanceof MongoDB\BSON\ObjectId) $id = (string)$id;

  $t = $doc['target'] ?? [];
  if ($t instanceof MongoDB\Model\BSONDocument) $t = $t->getArrayCopy();

  $did = (string)($t['doc_id'] ?? '');
  if ($did === '') continue;

  $c = $doc['context'] ?? [];
  if ($c instanceof MongoDB\Model\BSONDocument) $c = $c->getArrayCopy();

  $expiresIso = utc_to_iso($doc['expires_at'] ?? null);
  $ttlLeftSec = null;

  if (($doc['expires_at'] ?? null) instanceof MongoDB\BSON\UTCDateTime) {
    $expMs = (int)$doc['expires_at']->toDateTime()->format('U') * 1000;
    $ttlLeftSec = max(0, (int) floor(($expMs - $nowMs) / 1000));
  } else if (is_string($expiresIso)) {
    $ts = strtotime($expiresIso);
    if ($ts !== false) {
      $expMs = $ts * 1000;
      $ttlLeftSec = max(0, (int) floor(($expMs - $nowMs) / 1000));
    }
  }

  // aynı doc_id için birden çok lock teorik olarak olmamalı (unique index var),
  // ama olursa en erken expire olanı gösteriyoruz (sort expires_at asc)
  if (!isset($locks[$did])) {
    $locks[$did] = [
      'lock_id' => $id,
      'status' => (string)($doc['status'] ?? 'editing'),
      'username' => (string)($c['username'] ?? ''),
      'session_id' => (string)($c['session_id'] ?? ''),
      'expires_at' => $expiresIso,
      'ttl_left_sec' => $ttlLeftSec,
    ];
  }
}

j([
  'ok' => true,
  'locks' => $locks
]);
