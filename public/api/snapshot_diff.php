<?php
/**
 * public/api/snapshot_diff.php
 *
 * GET:
 *  A) ?snapshot_id=<SNAP01E _id>  -> bu snapshot vs prev_snapshot
 *  B) ?target_key=<...>           -> latest vs prev (same target_key)
 *
 * Optional:
 *  - &view=1 -> HTML view
 */

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/auth/SessionManager.php';

SessionManager::start();

function j($a, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
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

function esc($s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function fmt_tr($isoOrNull): string {
  if (!$isoOrNull) return '';
  try {
    $dt = new DateTime($isoOrNull, new DateTimeZone('UTC'));
    $dt->setTimezone(new DateTimeZone('Europe/Istanbul'));
    return $dt->format('d.m.Y H:i:s');
  } catch (Throwable $e) {
    return (string)$isoOrNull;
  }
}

/**
 * LANG rows diff:
 * rows[key] = { module,key,tr,en }
 * Diff output:
 *  added_keys, removed_keys, changed_keys[key][tr/en/from/to]
 */
function diff_lang_rows(array $oldRows, array $newRows): array {
  $oldKeys = array_keys($oldRows);
  $newKeys = array_keys($newRows);

  $added = array_values(array_diff($newKeys, $oldKeys));
  $removed = array_values(array_diff($oldKeys, $newKeys));

  $changed = [];
  foreach ($newRows as $k => $row) {
    if (!isset($oldRows[$k])) continue;
    $o = (array)$oldRows[$k];
    $n = (array)$row;

    foreach (['tr','en','module'] as $field) {
      $ov = (string)($o[$field] ?? '');
      $nv = (string)($n[$field] ?? '');
      if ($ov !== $nv) {
        if (!isset($changed[$k])) $changed[$k] = [];
        $changed[$k][$field] = ['from'=>$ov, 'to'=>$nv];
      }
    }
  }

  sort($added);
  sort($removed);

  ksort($changed);

  return [
    'added_keys' => $added,
    'removed_keys' => $removed,
    'changed_keys' => $changed
  ];
}

function summarize_lang_diff(array $diff, int $sample = 10): array {
  $added = $diff['added_keys'] ?? [];
  $removed = $diff['removed_keys'] ?? [];
  $changed = $diff['changed_keys'] ?? [];

  $changedKeys = array_keys($changed);

  return [
    'mode' => 'lang',
    'added_keys_count' => count($added),
    'removed_keys_count' => count($removed),
    'changed_keys_count' => count($changedKeys),
    'added_keys_sample' => array_slice($added, 0, $sample),
    'removed_keys_sample' => array_slice($removed, 0, $sample),
    'changed_keys_sample' => array_slice($changedKeys, 0, $sample),
  ];
}

$snapshotId = trim($_GET['snapshot_id'] ?? '');
$targetKey  = trim($_GET['target_key'] ?? '');
$view       = (string)($_GET['view'] ?? '') === '1';

if ($snapshotId === '' && $targetKey === '') {
  if ($view) { http_response_code(400); echo "snapshot_id veya target_key gerekli"; exit; }
  j(['ok'=>false,'error'=>'snapshot_id_or_target_key_required'], 400);
}

// resolve snapshots: prev + latest
$prev = null;
$latest = null;

if ($snapshotId !== '') {
  try {
    $latestDoc = MongoManager::collection('SNAP01E')->findOne(['_id' => new MongoDB\BSON\ObjectId($snapshotId)]);
  } catch (Throwable $e) {
    if ($view) { http_response_code(400); echo "Geçersiz snapshot_id"; exit; }
    j(['ok'=>false,'error'=>'invalid_snapshot_id'], 400);
  }
  if (!$latestDoc) {
    if ($view) { http_response_code(404); echo "Snapshot bulunamadı"; exit; }
    j(['ok'=>false,'error'=>'not_found'], 404);
  }
  $latest = bson_to_array($latestDoc);
  $targetKey = (string)($latest['target_key'] ?? '');

  $prevId = $latest['prev_snapshot_id'] ?? null;
  if ($prevId) {
    try {
      $prevDoc = MongoManager::collection('SNAP01E')->findOne(['_id' => new MongoDB\BSON\ObjectId((string)$prevId)]);
      if ($prevDoc) $prev = bson_to_array($prevDoc);
    } catch (Throwable $e) {
      $prev = null;
    }
  }
} else {
  // latest by target_key
  $latestDoc = MongoManager::collection('SNAP01E')->findOne(
    ['target_key' => $targetKey],
    ['sort' => ['version' => -1]]
  );

  if (!$latestDoc) {
    if ($view) { http_response_code(404); echo "Snapshot bulunamadı"; exit; }
    j(['ok'=>false,'error'=>'not_found'], 404);
  }
  $latest = bson_to_array($latestDoc);

  // prev = version-1
  $prevDoc = MongoManager::collection('SNAP01E')->findOne(
    ['target_key' => $targetKey, 'version' => (int)($latest['version'] ?? 0) - 1]
  );
  if ($prevDoc) $prev = bson_to_array($prevDoc);
}

// compute diff (LANG rows mode)
$diff = ['added_keys'=>[], 'removed_keys'=>[], 'changed_keys'=>[]];
$summary = ['mode'=>'lang','note'=>'no_prev_snapshot'];

if ($prev) {
  $oldRows = (array)($prev['data']['rows'] ?? []);
  $newRows = (array)($latest['data']['rows'] ?? []);

  $diff = diff_lang_rows($oldRows, $newRows);
  $summary = summarize_lang_diff($diff, 10);
}

$out = [
  'ok' => true,
  'target_key' => $targetKey,
  'prev' => $prev ? ['id'=>$prev['_id'] ?? null, 'version'=>$prev['version'] ?? null] : null,
  'latest' => ['id'=>$latest['_id'] ?? null, 'version'=>$latest['version'] ?? null],
  'diff' => $diff,
  'summary' => $summary,
];

if (!$view) {
  j($out);
}

// ---- HTML VIEW ----
header('Content-Type: text/html; charset=utf-8');

$latestTime = fmt_tr($latest['created_at'] ?? null);
$prevTime = $prev ? fmt_tr($prev['created_at'] ?? null) : '';

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>SNAPSHOT DIFF</title>
  <style>
    body{font-family:Arial,sans-serif;margin:16px;}
    .card{border:1px solid #eee;border-radius:10px;padding:12px;margin-bottom:12px;}
    .h{display:flex;gap:10px;flex-wrap:wrap;align-items:center;}
    .pill{padding:4px 8px;border-radius:999px;background:#f3f3f3;font-size:12px;}
    table{border-collapse:collapse;width:100%;}
    th,td{border:1px solid #eee;padding:8px;vertical-align:top;}
    th{background:#f7f7f7;text-align:left;}
    .k{color:#666;font-size:12px;}
    .code{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;}
  </style>
</head>
<body>

<div class="card">
  <div class="h">
    <span class="pill"><strong>DIFF</strong></span>
    <span class="pill">target_key: <span class="code"><?php echo esc($targetKey); ?></span></span>
  </div>
  <div style="margin-top:8px" class="k">
    Prev: <?php echo esc($out['prev']['version'] ?? '-'); ?> (<?php echo esc($prevTime ?: '-'); ?>)
    &nbsp;→&nbsp;
    Latest: <?php echo esc($out['latest']['version'] ?? '-'); ?> (<?php echo esc($latestTime ?: '-'); ?>)
  </div>
</div>

<div class="card">
  <h3 style="margin:0 0 8px 0;">Özet</h3>
  <table>
    <tr><th>added</th><td><?php echo esc($summary['added_keys_count'] ?? 0); ?></td></tr>
    <tr><th>removed</th><td><?php echo esc($summary['removed_keys_count'] ?? 0); ?></td></tr>
    <tr><th>changed</th><td><?php echo esc($summary['changed_keys_count'] ?? 0); ?></td></tr>
    <tr><th>changed sample</th><td class="code"><?php echo esc(json_encode($summary['changed_keys_sample'] ?? [], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); ?></td></tr>
  </table>
</div>

<div class="card">
  <h3 style="margin:0 0 8px 0;">Değişenler</h3>
  <?php if (empty($diff['changed_keys'])): ?>
    <div class="k">Değişiklik yok.</div>
  <?php else: ?>
    <table>
      <tr>
        <th style="width:260px;">Key</th>
        <th>Değişiklik</th>
      </tr>
      <?php foreach ($diff['changed_keys'] as $k => $fields): ?>
        <tr>
          <td><span class="code"><strong><?php echo esc($k); ?></strong></span></td>
          <td>
            <?php foreach ($fields as $field => $ft): ?>
              <div class="k"><strong><?php echo esc($field); ?></strong></div>
              <div class="code"><?php echo esc((string)($ft['from'] ?? '')); ?> → <?php echo esc((string)($ft['to'] ?? '')); ?></div>
              <div style="height:8px"></div>
            <?php endforeach; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

</body>
</html>
