<?php
/**
 * public/snapshot_diff_view.php (FINAL)
 *
 * GET:
 *  ?snapshot_id=...
 *
 * - Hangi versiyon? (current)
 * - Prev / Next / Latest gezinti
 * - LANG ise: changed_keys tablosu
 * - GENEL ise: flatten path bazlı Added/Removed/Changed tablosu
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
ActionLogger::info('SNAPSHOT.DIFF.VIEW', ['source' => 'public/snapshot_diff_view.php'], $ctx);

date_default_timezone_set('Europe/Istanbul');

function esc($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function fmt_tr_dt($iso): string {
  if (!$iso) return '-';
  try { return (new DateTime((string)$iso))->format('d.m.Y H:i:s'); }
  catch (Throwable $e) { return (string)$iso; }
}

function bson_to_array($v) {
  if ($v instanceof MongoDB\Model\BSONDocument || $v instanceof MongoDB\Model\BSONArray) $v = $v->getArrayCopy();
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
  try { $oid = new MongoDB\BSON\ObjectId($id); }
  catch (Throwable $e) { return null; }

  $doc = MongoManager::collection('SNAP01E')->findOne(['_id' => $oid]);
  if (!$doc) return null;
  if ($doc instanceof MongoDB\Model\BSONDocument) $doc = $doc->getArrayCopy();
  return bson_to_array($doc);
}

function find_latest_by_target_key(string $targetKey): ?array {
  $doc = MongoManager::collection('SNAP01E')->findOne(
    ['target_key' => $targetKey],
    ['sort' => ['version' => -1]]
  );
  if (!$doc) return null;
  if ($doc instanceof MongoDB\Model\BSONDocument) $doc = $doc->getArrayCopy();
  return bson_to_array($doc);
}

function find_next_snapshot(string $currentId, string $targetKey): ?array {
  try { $curOid = new MongoDB\BSON\ObjectId($currentId); }
  catch (Throwable $e) { return null; }

  $doc = MongoManager::collection('SNAP01E')->findOne(
    ['target_key' => $targetKey, 'prev_snapshot_id' => $curOid],
    ['sort' => ['version' => 1]]
  );
  if (!$doc) return null;
  if ($doc instanceof MongoDB\Model\BSONDocument) $doc = $doc->getArrayCopy();
  return bson_to_array($doc);
}

function is_lang_snapshot(array $snap): bool {
  return isset($snap['data']['rows']) && is_array($snap['data']['rows']);
}

/**
 * GENEL DIFF flatten:
 * diffAssoc çıktısını (added/removed/changed) path bazlı tabloya çevirir.
 */
function flatten_assoc_added_removed(array $a, string $prefix = ''): array {
  $rows = [];
  foreach ($a as $k => $v) {
    $path = $prefix === '' ? (string)$k : ($prefix . '.' . $k);
    if (is_array($v)) {
      // leaf de olabilir ama array ise derinleşelim
      $sub = flatten_assoc_added_removed($v, $path);
      if (!empty($sub)) $rows = array_merge($rows, $sub);
      else $rows[] = ['path' => $path, 'value' => json_encode($v, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)];
    } else {
      $rows[] = ['path' => $path, 'value' => (string)$v];
    }
  }
  return $rows;
}

function flatten_assoc_changed(array $changed, string $prefix = ''): array {
  $rows = [];
  foreach ($changed as $k => $v) {
    $path = $prefix === '' ? (string)$k : ($prefix . '.' . $k);

    // leaf: ['from'=>..,'to'=>..]
    if (is_array($v) && array_key_exists('from', $v) && array_key_exists('to', $v)) {
      $from = is_array($v['from']) ? json_encode($v['from'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : (string)$v['from'];
      $to   = is_array($v['to'])   ? json_encode($v['to'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : (string)$v['to'];
      $rows[] = ['path' => $path, 'from' => $from, 'to' => $to];
      continue;
    }

    // nested diff: ['added'=>..,'removed'=>..,'changed'=>..] olabilir
    if (is_array($v) && (isset($v['added']) || isset($v['removed']) || isset($v['changed']))) {
      $subChanged = isset($v['changed']) && is_array($v['changed']) ? $v['changed'] : [];
      $sub = flatten_assoc_changed($subChanged, $path);
      if (!empty($sub)) $rows = array_merge($rows, $sub);
      continue;
    }

    // fallback: unknown structure
    $rows[] = [
      'path' => $path,
      'from' => '(?)',
      'to'   => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : (string)$v
    ];
  }
  return $rows;
}

// ---- Input ----
$snapshotId = trim($_GET['snapshot_id'] ?? '');
if ($snapshotId === '') {
  http_response_code(400);
  echo "snapshot_id required";
  exit;
}

$current = find_snapshot_by_id($snapshotId);
if (!$current) {
  http_response_code(404);
  echo "snapshot not found";
  exit;
}

$targetKey = (string)($current['target_key'] ?? '');
$prevId = isset($current['prev_snapshot_id']) ? (string)$current['prev_snapshot_id'] : '';
$prev = $prevId ? find_snapshot_by_id($prevId) : null;

$next = ($targetKey && $snapshotId) ? find_next_snapshot($snapshotId, $targetKey) : null;
$latestSnap = $targetKey ? find_latest_by_target_key($targetKey) : null;

$mode = (is_lang_snapshot($current) && $prev && is_lang_snapshot($prev)) ? 'lang' : 'generic';

$diff = null;
$summary = null;
$note = null;

if (!$prev) {
  $note = 'no_prev_snapshot';
} else {
  if ($mode === 'lang') {
    $oldRows = (array)($prev['data']['rows'] ?? []);
    $newRows = (array)($current['data']['rows'] ?? []);
    $diff = SnapshotDiff::diffLangRows($oldRows, $newRows);
    $summary = SnapshotDiff::summarizeLangDiff($diff, 12);
    $summary['mode'] = 'lang';
  } else {
    $oldData = (array)($prev['data'] ?? []);
    $newData = (array)($current['data'] ?? []);
    $diff = SnapshotDiff::diffAssoc($oldData, $newData);
    $summary = [
      'mode' => 'generic',
      'added_count'   => isset($diff['added']) ? count($diff['added']) : 0,
      'removed_count' => isset($diff['removed']) ? count($diff['removed']) : 0,
      'changed_count' => isset($diff['changed']) ? count($diff['changed']) : 0,
    ];
  }
}

$currentVersion = (int)($current['version'] ?? 0);
$prevVersion = $prev ? (int)($prev['version'] ?? 0) : null;
$latestVersion = $latestSnap ? (int)($latestSnap['version'] ?? 0) : null;

$target = (array)($current['target'] ?? []);
$docModule = (string)($target['module'] ?? '-');
$docType   = (string)($target['doc_type'] ?? '-');
$docId     = (string)($target['doc_id'] ?? '-');
$docNo     = (string)($target['doc_no'] ?? '');
$docTitle  = (string)($target['doc_title'] ?? '');

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Snapshot Diff</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <style>
    body{ font-family: Arial, sans-serif; background:#0f1222; color:#e7eaf3; margin:0; }
    .wrap{ max-width: 1200px; margin: 0 auto; padding: 16px; }
    .card{ background:#1b2040; border:1px solid rgba(255,255,255,.10); border-radius:14px; padding:14px; margin:10px 0; }
    .top{ display:flex; justify-content:space-between; gap:12px; align-items:flex-start; flex-wrap:wrap; }
    .h{ font-size:20px; font-weight:800; margin:0 0 6px; }
    .sub{ color: rgba(231,234,243,.70); font-size:13px; }
    .code{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono","Courier New", monospace; }
    .pill{ display:inline-flex; gap:8px; align-items:center; padding:6px 10px; border-radius:999px; background:rgba(255,255,255,.07); border:1px solid rgba(255,255,255,.10); font-size:12px; }
    .bar{ display:flex; gap:10px; flex-wrap:wrap; margin-top:10px; }
    .btn{
      display:inline-flex; align-items:center; justify-content:center;
      padding:9px 12px; border-radius:10px;
      border:1px solid rgba(255,255,255,.14);
      color:#e7eaf3; text-decoration:none;
      background: rgba(255,255,255,.04);
      cursor:pointer;
      white-space:nowrap;
    }
    .btn:hover{ filter:brightness(1.05); }
    .btn-primary{ background:#5865f2; border-color: transparent; color:white; }
    .grid{ display:grid; grid-template-columns: 1fr 1fr; gap:12px; }
    @media(max-width: 980px){ .grid{ grid-template-columns: 1fr; } }
    table{ width:100%; border-collapse:collapse; }
    th, td{ border:1px solid rgba(255,255,255,.12); padding:8px; vertical-align:top; }
    th{ background: rgba(255,255,255,.06); text-align:left; font-size:12px; color: rgba(231,234,243,.9); }
    td{ font-size:13px; color: rgba(231,234,243,.92); }
    .muted{ color: rgba(231,234,243,.65); }
    pre{ margin:0; white-space:pre-wrap; word-break:break-word; background: rgba(0,0,0,.22); padding:10px; border-radius:12px; border:1px solid rgba(255,255,255,.10); }
    .k{ font-weight:700; }
    .section-title{ margin:0 0 8px; font-size:15px; font-weight:800; }
  </style>
</head>
<body>

<div class="wrap">

  <?php require_once __DIR__ . '/../app/views/layout/header.php'; ?>

  <div class="card">
    <div class="top">
      <div>
        <div class="h">Snapshot Diff</div>
        <div class="sub">
          Target:
          <span class="code"><?php echo esc($docModule); ?></span> /
          <span class="code"><?php echo esc($docType); ?></span> /
          <span class="code"><?php echo esc($docId); ?></span>
          <?php if ($docNo !== ''): ?> &nbsp; | doc_no: <span class="code"><?php echo esc($docNo); ?></span><?php endif; ?>
          <?php if ($docTitle !== ''): ?> &nbsp; | title: <b><?php echo esc($docTitle); ?></b><?php endif; ?>
        </div>

        <div class="sub" style="margin-top:6px;">
          <span class="pill">Mode: <b><?php echo esc($mode); ?></b></span>
          <span class="pill">Current: <b>v<?php echo (int)$currentVersion; ?></b></span>
          <span class="pill">Prev: <b><?php echo $prevVersion !== null ? ('v'.(int)$prevVersion) : '-'; ?></b></span>
          <span class="pill">Latest: <b><?php echo $latestVersion !== null ? ('v'.(int)$latestVersion) : '-'; ?></b></span>
        </div>

        <div class="sub" style="margin-top:6px;">
          Current Snapshot: <span class="code"><?php echo esc($snapshotId); ?></span>
          <?php if ($prevId): ?> &nbsp; | Prev Snapshot: <span class="code"><?php echo esc($prevId); ?></span><?php endif; ?>
        </div>
      </div>

      <div class="bar">
        <?php if ($prev): ?>
          <a class="btn" href="/php-mongo-erp/public/snapshot_diff_view.php?snapshot_id=<?php echo urlencode((string)($prev['_id'] ?? '')); ?>">⬅ Önceki</a>
        <?php else: ?>
          <span class="btn" style="opacity:.45; cursor:default;">⬅ Önceki</span>
        <?php endif; ?>

        <?php if ($next): ?>
          <a class="btn" href="/php-mongo-erp/public/snapshot_diff_view.php?snapshot_id=<?php echo urlencode((string)($next['_id'] ?? '')); ?>">Sonraki ➡</a>
        <?php else: ?>
          <span class="btn" style="opacity:.45; cursor:default;">Sonraki ➡</span>
        <?php endif; ?>

        <?php if ($latestSnap && (string)($latestSnap['_id'] ?? '') !== $snapshotId): ?>
          <a class="btn btn-primary" href="/php-mongo-erp/public/snapshot_diff_view.php?snapshot_id=<?php echo urlencode((string)($latestSnap['_id'] ?? '')); ?>">Latest</a>
        <?php else: ?>
          <span class="btn btn-primary" style="opacity:.55; cursor:default;">Latest</span>
        <?php endif; ?>

        <a class="btn" target="_blank" href="/php-mongo-erp/public/api/snapshot_get.php?snapshot_id=<?php echo urlencode($snapshotId); ?>">Snapshot JSON</a>
      </div>
    </div>
  </div>

  <?php if ($note === 'no_prev_snapshot'): ?>
    <div class="card">
      <div class="section-title">Diff yok</div>
      <div class="sub">Bu snapshot için <b>prev_snapshot_id</b> yok. Muhtemelen ilk versiyon.</div>
    </div>
  <?php else: ?>

    <div class="grid">
      <div class="card">
        <div class="section-title">Zaman</div>
        <div class="sub">Prev: <b><?php echo esc(fmt_tr_dt((string)($prev['created_at'] ?? ''))); ?></b></div>
        <div class="sub" style="margin-top:6px;">Current: <b><?php echo esc(fmt_tr_dt((string)($current['created_at'] ?? ''))); ?></b></div>
        <div class="sub" style="margin-top:6px;">Bu ekran: <b>v<?php echo (int)$prevVersion; ?> → v<?php echo (int)$currentVersion; ?></b></div>
      </div>

      <div class="card">
        <div class="section-title">Özet</div>
        <pre><?php echo esc(json_encode($summary, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)); ?></pre>
      </div>
    </div>

    <?php if ($mode === 'lang'): ?>
      <?php
        $added = $diff['added_keys'] ?? [];
        $removed = $diff['removed_keys'] ?? [];
        $changed = $diff['changed_keys'] ?? [];
      ?>

      <div class="card">
        <div class="section-title">LANG Değişiklikleri</div>

        <div class="grid" style="margin-top:10px;">
          <div>
            <div class="sub"><b>Added Keys</b> (<?php echo count($added); ?>)</div>
            <pre><?php echo esc(json_encode(array_values($added), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)); ?></pre>
          </div>
          <div>
            <div class="sub"><b>Removed Keys</b> (<?php echo count($removed); ?>)</div>
            <pre><?php echo esc(json_encode(array_values($removed), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)); ?></pre>
          </div>
        </div>

        <div style="margin-top:12px;">
          <div class="sub"><b>Changed Keys</b> (<?php echo count($changed); ?>)</div>

          <?php if (empty($changed)): ?>
            <div class="sub muted">Değişiklik yok.</div>
          <?php else: ?>
            <table style="margin-top:8px;">
              <tr>
                <th style="width:280px;">Key</th>
                <th>TR</th>
                <th>EN</th>
              </tr>
              <?php foreach ($changed as $k => $langs):
                $tr = $langs['tr'] ?? null;
                $en = $langs['en'] ?? null;
              ?>
                <tr>
                  <td class="code"><b><?php echo esc($k); ?></b></td>
                  <td>
                    <?php if ($tr): ?>
                      <div class="muted"><span class="k">from:</span> <?php echo esc($tr['from'] ?? ''); ?></div>
                      <div><span class="k">to:</span> <?php echo esc($tr['to'] ?? ''); ?></div>
                    <?php else: ?><span class="muted">-</span><?php endif; ?>
                  </td>
                  <td>
                    <?php if ($en): ?>
                      <div class="muted"><span class="k">from:</span> <?php echo esc($en['from'] ?? ''); ?></div>
                      <div><span class="k">to:</span> <?php echo esc($en['to'] ?? ''); ?></div>
                    <?php else: ?><span class="muted">-</span><?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </table>
          <?php endif; ?>
        </div>
      </div>

    <?php else: ?>
      <?php
        $addedRows = flatten_assoc_added_removed($diff['added'] ?? []);
        $removedRows = flatten_assoc_added_removed($diff['removed'] ?? []);
        $changedRows = flatten_assoc_changed($diff['changed'] ?? []);
      ?>

      <div class="card">
        <div class="section-title">GENEL Değişiklikler (Path Bazlı)</div>

        <div style="margin-top:10px;">
          <div class="sub"><b>Changed</b> (<?php echo count($changedRows); ?>)</div>
          <?php if (empty($changedRows)): ?>
            <div class="sub muted">Değişiklik yok.</div>
          <?php else: ?>
            <table style="margin-top:8px;">
              <tr>
                <th style="width:360px;">Path</th>
                <th>From</th>
                <th>To</th>
              </tr>
              <?php foreach ($changedRows as $r): ?>
                <tr>
                  <td class="code"><?php echo esc($r['path']); ?></td>
                  <td class="muted"><?php echo esc($r['from']); ?></td>
                  <td><?php echo esc($r['to']); ?></td>
                </tr>
              <?php endforeach; ?>
            </table>
          <?php endif; ?>
        </div>

        <div class="grid" style="margin-top:12px;">
          <div>
            <div class="sub"><b>Added</b> (<?php echo count($addedRows); ?>)</div>
            <?php if (empty($addedRows)): ?>
              <div class="sub muted">-</div>
            <?php else: ?>
              <table style="margin-top:8px;">
                <tr><th style="width:360px;">Path</th><th>Value</th></tr>
                <?php foreach ($addedRows as $r): ?>
                  <tr>
                    <td class="code"><?php echo esc($r['path']); ?></td>
                    <td><?php echo esc($r['value']); ?></td>
                  </tr>
                <?php endforeach; ?>
              </table>
            <?php endif; ?>
          </div>

          <div>
            <div class="sub"><b>Removed</b> (<?php echo count($removedRows); ?>)</div>
            <?php if (empty($removedRows)): ?>
              <div class="sub muted">-</div>
            <?php else: ?>
              <table style="margin-top:8px;">
                <tr><th style="width:360px;">Path</th><th>Value</th></tr>
                <?php foreach ($removedRows as $r): ?>
                  <tr>
                    <td class="code"><?php echo esc($r['path']); ?></td>
                    <td class="muted"><?php echo esc($r['value']); ?></td>
                  </tr>
                <?php endforeach; ?>
              </table>
            <?php endif; ?>
          </div>
        </div>
      </div>

    <?php endif; ?>

  <?php endif; ?>

</div>
</body>
</html>
