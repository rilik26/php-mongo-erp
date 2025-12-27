<?php
/**
 * public/snapshot_diff_view.php (FINAL)
 *
 * - Snapshot diff HTML view
 * - BSONDocument -> array fix (TypeError çözümü)
 * - Prev/Next diff navigation (target_key + version ile)
 * - Lang rows özel detay diff (tr/en from->to)
 * - Genel data için added/removed/changed diff (nested)
 */

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/auth/SessionManager.php';
require_once __DIR__ . '/../core/base/Context.php';
require_once __DIR__ . '/../core/base/ContextException.php';
require_once __DIR__ . '/../core/action/ActionLogger.php';

require_once __DIR__ . '/../core/snapshot/SnapshotDiff.php';

SessionManager::start();

if (!isset($_SESSION['context']) || !is_array($_SESSION['context'])) {
  header('Location: /php-mongo-erp/public/login.php');
  exit;
}

try {
  Context::bootFromSession();
} catch (ContextException $e) {
  header('Location: /php-mongo-erp/public/login.php');
  exit;
}

$ctx = Context::get();

ActionLogger::info('SNAPSHOT.DIFF.VIEW', [
  'source' => 'public/snapshot_diff_view.php'
], $ctx);

function esc($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function bson_to_array($v) {
  if ($v instanceof MongoDB\Model\BSONDocument || $v instanceof MongoDB\Model\BSONArray) {
    $v = $v->getArrayCopy();
  }
  if ($v instanceof MongoDB\BSON\UTCDateTime) return $v->toDateTime()->format('c');
  if ($v instanceof MongoDB\BSON\ObjectId) return (string)$v;
  if (is_array($v)) {
    $out = [];
    foreach ($v as $k => $vv) $out[$k] = bson_to_array($vv);
    return $out;
  }
  return $v;
}

function fmt_tr($iso): string {
  if (!$iso) return '-';
  try {
    $dt = new DateTime((string)$iso);
    $dt->setTimezone(new DateTimeZone('Europe/Istanbul'));
    return $dt->format('d.m.Y H:i:s');
  } catch(Throwable $e) {
    return (string)$iso;
  }
}

function find_snapshot_by_id(string $id): ?array {
  try {
    $oid = new MongoDB\BSON\ObjectId($id);
  } catch(Throwable $e) {
    return null;
  }
  $doc = MongoManager::collection('SNAP01E')->findOne(['_id' => $oid]);
  if (!$doc) return null;
  return bson_to_array($doc);
}

function find_snapshot_by_target_version(string $targetKey, int $version): ?array {
  $doc = MongoManager::collection('SNAP01E')->findOne(
    ['target_key' => $targetKey, 'version' => $version],
    ['sort' => ['version' => 1]]
  );
  if (!$doc) return null;
  return bson_to_array($doc);
}

function json_pretty($v): string {
  return json_encode($v, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
}

$snapshotId = trim($_GET['snapshot_id'] ?? '');
if ($snapshotId === '') {
  http_response_code(400);
  echo "snapshot_id required";
  exit;
}

$snap = find_snapshot_by_id($snapshotId);
if (!$snap) {
  http_response_code(404);
  echo "snapshot not found";
  exit;
}

$prevId = (string)($snap['prev_snapshot_id'] ?? '');
$prevSnap = $prevId ? find_snapshot_by_id($prevId) : null;

$targetKey = (string)($snap['target_key'] ?? '');
$ver = (int)($snap['version'] ?? 0);

// prev/next navigation by version (daha sağlam)
$prevByVer = ($targetKey && $ver > 1) ? find_snapshot_by_target_version($targetKey, $ver - 1) : null;
$nextByVer = ($targetKey && $ver > 0) ? find_snapshot_by_target_version($targetKey, $ver + 1) : null;

// diff hesapla
$oldData = $prevSnap['data'] ?? [];
$newData = $snap['data'] ?? [];
$oldData = is_array($oldData) ? $oldData : bson_to_array($oldData);
$newData = is_array($newData) ? $newData : bson_to_array($newData);

$isLang = false;
if (isset($oldData['rows']) || isset($newData['rows'])) {
  // lang sözlüğü formatı: data.rows[key] = {tr,en,...}
  $isLang = true;
}

$langDiff = null;
$genDiff  = null;

if ($isLang) {
  $oldRows = isset($oldData['rows']) ? (array)bson_to_array($oldData['rows']) : [];
  $newRows = isset($newData['rows']) ? (array)bson_to_array($newData['rows']) : [];
  $langDiff = SnapshotDiff::diffLangRows($oldRows, $newRows);
} else {
  $genDiff = SnapshotDiff::diffAssoc((array)$oldData, (array)$newData);
}

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Snapshot Diff</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <style>
    body{ font-family: Arial, sans-serif; margin:0; background:#0f1220; color:#e8ebf6; }
    .wrap{ max-width:1200px; margin:0 auto; padding:16px; }
    .topbar{ display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:12px; }
    .btn{
      padding:8px 12px; border-radius:10px; border:1px solid rgba(255,255,255,.12);
      background:transparent; color:#e8ebf6; text-decoration:none; cursor:pointer;
      display:inline-flex; gap:8px; align-items:center;
    }
    .btn:hover{ filter:brightness(1.05); }
    .btn-primary{ background:#5865f2; border-color:transparent; color:#fff; }
    .btn-dim{ opacity:.55; pointer-events:none; }
    .h1{ font-size:22px; font-weight:800; margin:0 0 6px; }
    .muted{ color:rgba(232,235,246,.65); font-size:13px; }
    .card{
      background:#171a2c; border:1px solid rgba(255,255,255,.10);
      border-radius:16px; padding:14px; margin-top:12px;
    }
    .grid{ display:grid; grid-template-columns: 1fr 1fr; gap:12px; }
    @media (max-width: 980px){ .grid{ grid-template-columns: 1fr; } }
    .code{
      font-family: ui-monospace, Menlo, Consolas, monospace;
      background:rgba(0,0,0,.25); border:1px solid rgba(255,255,255,.10);
      padding:2px 8px; border-radius:999px; font-size:12px;
    }
    .kv{ font-size:13px; line-height:1.6; color:rgba(232,235,246,.80); }
    .kv b{ color:#fff; font-weight:700; }
    table{ border-collapse:collapse; width:100%; }
    th,td{ border:1px solid rgba(255,255,255,.12); padding:8px; vertical-align:top; }
    th{ background:rgba(255,255,255,.06); text-align:left; }
    .small{ font-size:12px; color:rgba(232,235,246,.70); }
    details{ margin-top:8px; }
    pre{ margin:0; white-space:pre-wrap; word-break:break-word; color:#e8ebf6; }
  </style>
</head>
<body>
<div class="wrap">

  <div class="topbar">
    <a class="btn" href="/php-mongo-erp/public/timeline.php">← Timeline</a>

    <?php if ($prevByVer && !empty($prevByVer['_id'])): ?>
      <a class="btn" href="/php-mongo-erp/public/snapshot_diff_view.php?snapshot_id=<?php echo esc($prevByVer['_id']); ?>">← Önceki Diff</a>
    <?php else: ?>
      <span class="btn btn-dim">← Önceki Diff</span>
    <?php endif; ?>

    <?php if ($nextByVer && !empty($nextByVer['_id'])): ?>
      <a class="btn" href="/php-mongo-erp/public/snapshot_diff_view.php?snapshot_id=<?php echo esc($nextByVer['_id']); ?>">Sonraki Diff →</a>
    <?php else: ?>
      <span class="btn btn-dim">Sonraki Diff →</span>
    <?php endif; ?>

    <span style="flex:1"></span>

    <a class="btn" target="_blank" href="/php-mongo-erp/public/snapshot_view.php?snapshot_id=<?php echo esc($snapshotId); ?>">Snapshot</a>
    <?php if ($prevId): ?>
      <a class="btn" target="_blank" href="/php-mongo-erp/public/snapshot_view.php?snapshot_id=<?php echo esc($prevId); ?>">Prev Snapshot</a>
    <?php endif; ?>
  </div>

  <div class="h1">
    Diff (v<?php echo (int)($prevSnap['version'] ?? 0); ?> → v<?php echo (int)$ver; ?>)
    <span class="code"><?php echo esc($targetKey); ?></span>
  </div>

  <div class="muted">
    Current: <span class="code"><?php echo esc($snapshotId); ?></span>
    <?php if ($prevId): ?>
      &nbsp;|&nbsp; Prev: <span class="code"><?php echo esc($prevId); ?></span>
    <?php else: ?>
      &nbsp;|&nbsp; Prev: -
    <?php endif; ?>
  </div>

  <div class="grid">
    <div class="card">
      <div class="kv">
        <div><b>Current version</b>: v<?php echo (int)$ver; ?></div>
        <div><b>Created</b>: <?php echo esc(fmt_tr($snap['created_at'] ?? '')); ?></div>
        <div><b>User</b>: <?php echo esc($snap['context']['username'] ?? '-'); ?></div>
        <div><b>Hash</b>: <span class="code"><?php echo esc($snap['hash'] ?? '-'); ?></span></div>
      </div>
    </div>

    <div class="card">
      <div class="kv">
        <div><b>Prev version</b>: v<?php echo (int)($prevSnap['version'] ?? 0); ?></div>
        <div><b>Created</b>: <?php echo esc(fmt_tr($prevSnap['created_at'] ?? '')); ?></div>
        <div><b>User</b>: <?php echo esc($prevSnap['context']['username'] ?? '-'); ?></div>
        <div><b>Hash</b>: <span class="code"><?php echo esc($prevSnap['hash'] ?? '-'); ?></span></div>
      </div>
    </div>
  </div>

  <div class="card">
    <h3 style="margin:0 0 10px;">Değişiklikler</h3>

    <?php if (!$prevSnap): ?>
      <div class="small">Bu snapshot’ın prev’i yok. (ilk versiyon olabilir)</div>
    <?php elseif ($isLang): ?>
      <?php
        $changed = $langDiff['changed_keys'] ?? [];
        $added = $langDiff['added_keys'] ?? [];
        $removed = $langDiff['removed_keys'] ?? [];
      ?>

      <div class="small" style="margin-bottom:8px;">
        Added: <b><?php echo (int)count($added); ?></b>,
        Removed: <b><?php echo (int)count($removed); ?></b>,
        Changed: <b><?php echo (int)count($changed); ?></b>
      </div>

      <?php if (empty($added) && empty($removed) && empty($changed)): ?>
        <div class="small">Değişiklik yok.</div>
      <?php else: ?>

        <?php if (!empty($changed)): ?>
          <h4 style="margin:10px 0 8px;">Changed Keys</h4>
          <table>
            <tr>
              <th style="width:320px;">Key</th>
              <th>TR</th>
              <th>EN</th>
            </tr>
            <?php foreach ($changed as $k => $lcDiff): ?>
              <tr>
                <td><span class="code"><?php echo esc($k); ?></span></td>
                <td class="small">
                  <?php if (!empty($lcDiff['tr'])): ?>
                    <div><b>from</b>: <?php echo esc($lcDiff['tr']['from'] ?? ''); ?></div>
                    <div><b>to</b>: <?php echo esc($lcDiff['tr']['to'] ?? ''); ?></div>
                  <?php else: ?>
                    -
                  <?php endif; ?>
                </td>
                <td class="small">
                  <?php if (!empty($lcDiff['en'])): ?>
                    <div><b>from</b>: <?php echo esc($lcDiff['en']['from'] ?? ''); ?></div>
                    <div><b>to</b>: <?php echo esc($lcDiff['en']['to'] ?? ''); ?></div>
                  <?php else: ?>
                    -
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </table>
        <?php endif; ?>

        <?php if (!empty($added)): ?>
          <h4 style="margin:14px 0 8px;">Added Keys</h4>
          <div class="small"><?php echo esc(json_encode($added, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); ?></div>
        <?php endif; ?>

        <?php if (!empty($removed)): ?>
          <h4 style="margin:14px 0 8px;">Removed Keys</h4>
          <div class="small"><?php echo esc(json_encode($removed, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); ?></div>
        <?php endif; ?>

      <?php endif; ?>

    <?php else: ?>
      <?php
        $added = $genDiff['added'] ?? [];
        $removed = $genDiff['removed'] ?? [];
        $changed = $genDiff['changed'] ?? [];
      ?>

      <div class="small" style="margin-bottom:8px;">
        Added: <b><?php echo (int)count($added); ?></b>,
        Removed: <b><?php echo (int)count($removed); ?></b>,
        Changed: <b><?php echo (int)count($changed); ?></b>
      </div>

      <?php if (empty($added) && empty($removed) && empty($changed)): ?>
        <div class="small">Değişiklik yok.</div>
      <?php else: ?>

        <?php if (!empty($changed)): ?>
          <h4 style="margin:10px 0 8px;">Changed</h4>
          <table>
            <tr>
              <th style="width:340px;">Field</th>
              <th>From</th>
              <th>To</th>
            </tr>
            <?php foreach ($changed as $k => $v): ?>
              <tr>
                <td><span class="code"><?php echo esc($k); ?></span></td>
                <td class="small"><?php echo esc(is_array($v) ? json_encode($v['from'] ?? null, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : ''); ?></td>
                <td class="small"><?php echo esc(is_array($v) ? json_encode($v['to'] ?? null, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : ''); ?></td>
              </tr>
            <?php endforeach; ?>
          </table>
        <?php endif; ?>

        <?php if (!empty($added)): ?>
          <h4 style="margin:14px 0 8px;">Added</h4>
          <pre class="small"><?php echo esc(json_pretty($added)); ?></pre>
        <?php endif; ?>

        <?php if (!empty($removed)): ?>
          <h4 style="margin:14px 0 8px;">Removed</h4>
          <pre class="small"><?php echo esc(json_pretty($removed)); ?></pre>
        <?php endif; ?>

      <?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3 style="margin:0 0 8px;">Detay JSON (opsiyonel)</h3>

    <details>
      <summary class="small">Prev Snapshot Data</summary>
      <pre class="small"><?php echo esc(json_pretty($oldData)); ?></pre>
    </details>

    <details>
      <summary class="small">Current Snapshot Data</summary>
      <pre class="small"><?php echo esc(json_pretty($newData)); ?></pre>
    </details>
  </div>

</div>
</body>
</html>
