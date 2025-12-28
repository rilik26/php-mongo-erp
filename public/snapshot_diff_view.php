<?php
/**
 * public/snapshot_diff_view.php (FINAL - THEME)
 *
 * - Snapshot diff HTML view
 * - BSONDocument -> array fix
 * - Prev/Next diff navigation (target_key + version)
 * - Lang rows özel detay diff
 * - Genel data için nested diff detaylı render (flatten)
 *
 * ✅ Theme Layout: header / left / header2 / footer
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
  try { $oid = new MongoDB\BSON\ObjectId($id); }
  catch(Throwable $e){ return null; }

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

function safe_json($v): string {
  $j = json_encode($v, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  if ($j === false) return '"<json_encode_failed>"';
  return $j;
}

/**
 * Nested diff flatten:
 * changed[field] nested subdiff olabilir.
 * leaf değişiklikleri path listesine açar.
 */
function flatten_diff_assoc(array $diff, string $prefix = ''): array
{
  $items = [];

  foreach (($diff['changed'] ?? []) as $k => $v) {
    $path = ($prefix === '') ? (string)$k : ($prefix . '.' . $k);

    if (is_array($v) && array_key_exists('from', $v) && array_key_exists('to', $v)) {
      $items[] = ['type'=>'changed', 'path'=>$path, 'from'=>$v['from'], 'to'=>$v['to']];
      continue;
    }

    if (is_array($v) && (isset($v['added']) || isset($v['removed']) || isset($v['changed']))) {
      $sub = flatten_diff_assoc($v, $path);
      foreach ($sub as $it) $items[] = $it;
      continue;
    }

    $items[] = ['type'=>'changed', 'path'=>$path, 'from'=>null, 'to'=>$v];
  }

  foreach (($diff['added'] ?? []) as $k => $v) {
    $path = ($prefix === '') ? (string)$k : ($prefix . '.' . $k);
    $items[] = ['type'=>'added', 'path'=>$path, 'from'=>null, 'to'=>$v];
  }
  foreach (($diff['removed'] ?? []) as $k => $v) {
    $path = ($prefix === '') ? (string)$k : ($prefix . '.' . $k);
    $items[] = ['type'=>'removed', 'path'=>$path, 'from'=>$v, 'to'=>null];
  }

  return $items;
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

$prevByVer = ($targetKey && $ver > 1) ? find_snapshot_by_target_version($targetKey, $ver - 1) : null;
$nextByVer = ($targetKey && $ver > 0) ? find_snapshot_by_target_version($targetKey, $ver + 1) : null;

$oldData = $prevSnap['data'] ?? [];
$newData = $snap['data'] ?? [];
$oldData = is_array($oldData) ? $oldData : bson_to_array($oldData);
$newData = is_array($newData) ? $newData : bson_to_array($newData);

$isLang = (isset($oldData['rows']) || isset($newData['rows']));

$langDiff = null;
$genDiff  = null;
$flat = [];

if ($isLang) {
  $oldRows = isset($oldData['rows']) ? (array)bson_to_array($oldData['rows']) : [];
  $newRows = isset($newData['rows']) ? (array)bson_to_array($newData['rows']) : [];
  $langDiff = SnapshotDiff::diffLangRows($oldRows, $newRows);
} else {
  $genDiff = SnapshotDiff::diffAssoc((array)$oldData, (array)$newData);
  $flat = flatten_diff_assoc($genDiff);
}

/** ✅ THEME HEAD */
require_once __DIR__ . '/../app/views/layout/header.php';
?>
<body>
<div class="layout-wrapper layout-content-navbar">
  <div class="layout-container">

    <?php require_once __DIR__ . '/../app/views/layout/left.php'; ?>

    <div class="layout-page">
      <?php require_once __DIR__ . '/../app/views/layout/header2.php'; ?>

      <div class="content-wrapper">
        <div class="container-xxl flex-grow-1 container-p-y">

          <style>
            /* sadece bu sayfaya özel “diff görünümü” */
            .diff-wrap{ max-width:1200px; margin:0 auto; }
            .diff-topbar{ display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:12px; }
            .diff-btn{
              padding:8px 12px; border-radius:10px;
              border:1px solid rgba(0,0,0,.12);
              background:#fff; color:#111; text-decoration:none;
              display:inline-flex; gap:8px; align-items:center;
            }
            .diff-btn:hover{ filter:brightness(0.98); }
            .diff-btn-dim{ opacity:.55; pointer-events:none; }
            .diff-h1{ font-size:20px; font-weight:800; margin:0 0 6px; }
            .diff-muted{ color:rgba(0,0,0,.55); font-size:12px; }
            .diff-card{
              border:1px solid rgba(0,0,0,.10);
              border-radius:14px; padding:12px;
              background:#fff;
              margin-top:12px;
            }
            .diff-grid{ display:grid; grid-template-columns: 1fr 1fr; gap:12px; }
            @media (max-width: 980px){ .diff-grid{ grid-template-columns: 1fr; } }
            .diff-code{
              font-family: ui-monospace, Menlo, Consolas, monospace;
              background:rgba(0,0,0,.05);
              border:1px solid rgba(0,0,0,.10);
              padding:2px 8px; border-radius:999px; font-size:12px;
            }
            .diff-kv{ font-size:13px; line-height:1.6; color:rgba(0,0,0,.72); }
            .diff-kv b{ color:#111; font-weight:700; }
            .diff-table{ border-collapse:collapse; width:100%; }
            .diff-table th, .diff-table td{ border:1px solid rgba(0,0,0,.12); padding:8px; vertical-align:top; }
            .diff-table th{ background:rgba(0,0,0,.04); text-align:left; }
            .diff-small{ font-size:12px; color:rgba(0,0,0,.62); }
            .diff-pre{ margin:0; white-space:pre-wrap; word-break:break-word; }
            details{ margin-top:8px; }
          </style>

          <div class="diff-wrap">

            <div class="diff-topbar">
              <a class="diff-btn" href="/php-mongo-erp/public/timeline.php">← Timeline</a>

              <?php if ($prevByVer && !empty($prevByVer['_id'])): ?>
                <a class="diff-btn" href="/php-mongo-erp/public/snapshot_diff_view.php?snapshot_id=<?php echo esc($prevByVer['_id']); ?>">← Önceki Diff</a>
              <?php else: ?>
                <span class="diff-btn diff-btn-dim">← Önceki Diff</span>
              <?php endif; ?>

              <?php if ($nextByVer && !empty($nextByVer['_id'])): ?>
                <a class="diff-btn" href="/php-mongo-erp/public/snapshot_diff_view.php?snapshot_id=<?php echo esc($nextByVer['_id']); ?>">Sonraki Diff →</a>
              <?php else: ?>
                <span class="diff-btn diff-btn-dim">Sonraki Diff →</span>
              <?php endif; ?>

              <span style="flex:1"></span>

              <a class="diff-btn" target="_blank" href="/php-mongo-erp/public/snapshot_view.php?snapshot_id=<?php echo esc($snapshotId); ?>">Snapshot</a>
              <?php if ($prevId): ?>
                <a class="diff-btn" target="_blank" href="/php-mongo-erp/public/snapshot_view.php?snapshot_id=<?php echo esc($prevId); ?>">Prev Snapshot</a>
              <?php endif; ?>
            </div>

            <div class="diff-h1">
              Diff (v<?php echo (int)($prevSnap['version'] ?? 0); ?> → v<?php echo (int)$ver; ?>)
              <span class="diff-code"><?php echo esc($targetKey); ?></span>
            </div>

            <div class="diff-muted">
              Current: <span class="diff-code"><?php echo esc($snapshotId); ?></span>
              <?php if ($prevId): ?>
                &nbsp;|&nbsp; Prev: <span class="diff-code"><?php echo esc($prevId); ?></span>
              <?php else: ?>
                &nbsp;|&nbsp; Prev: -
              <?php endif; ?>
            </div>

            <div class="diff-grid">
              <div class="diff-card">
                <div class="diff-kv">
                  <div><b>Current version</b>: v<?php echo (int)$ver; ?></div>
                  <div><b>Created</b>: <?php echo esc(fmt_tr($snap['created_at'] ?? '')); ?></div>
                  <div><b>User</b>: <?php echo esc($snap['context']['username'] ?? '-'); ?></div>
                  <div><b>Hash</b>: <span class="diff-code"><?php echo esc($snap['hash'] ?? '-'); ?></span></div>
                </div>
              </div>

              <div class="diff-card">
                <div class="diff-kv">
                  <div><b>Prev version</b>: v<?php echo (int)($prevSnap['version'] ?? 0); ?></div>
                  <div><b>Created</b>: <?php echo esc(fmt_tr($prevSnap['created_at'] ?? '')); ?></div>
                  <div><b>User</b>: <?php echo esc($prevSnap['context']['username'] ?? '-'); ?></div>
                  <div><b>Hash</b>: <span class="diff-code"><?php echo esc($prevSnap['hash'] ?? '-'); ?></span></div>
                </div>
              </div>
            </div>

            <div class="diff-card">
              <h5 style="margin:0 0 10px;">Değişiklikler</h5>

              <?php if (!$prevSnap): ?>
                <div class="diff-small">Bu snapshot’ın prev’i yok. (ilk versiyon olabilir)</div>

              <?php elseif ($isLang): ?>
                <?php
                  $changed = $langDiff['changed_keys'] ?? [];
                  $added = $langDiff['added_keys'] ?? [];
                  $removed = $langDiff['removed_keys'] ?? [];
                ?>

                <div class="diff-small" style="margin-bottom:8px;">
                  Added: <b><?php echo (int)count($added); ?></b>,
                  Removed: <b><?php echo (int)count($removed); ?></b>,
                  Changed: <b><?php echo (int)count($changed); ?></b>
                </div>

                <?php if (empty($added) && empty($removed) && empty($changed)): ?>
                  <div class="diff-small">Değişiklik yok.</div>
                <?php else: ?>

                  <?php if (!empty($changed)): ?>
                    <h6 style="margin:10px 0 8px;">Changed Keys</h6>
                    <table class="diff-table">
                      <tr>
                        <th style="width:320px;">Key</th>
                        <th>TR</th>
                        <th>EN</th>
                      </tr>
                      <?php foreach ($changed as $k => $lcDiff): ?>
                        <tr>
                          <td><span class="diff-code"><?php echo esc($k); ?></span></td>
                          <td class="diff-small">
                            <?php if (!empty($lcDiff['tr'])): ?>
                              <div><b>from</b>: <?php echo esc($lcDiff['tr']['from'] ?? ''); ?></div>
                              <div><b>to</b>: <?php echo esc($lcDiff['tr']['to'] ?? ''); ?></div>
                            <?php else: ?>-<?php endif; ?>
                          </td>
                          <td class="diff-small">
                            <?php if (!empty($lcDiff['en'])): ?>
                              <div><b>from</b>: <?php echo esc($lcDiff['en']['from'] ?? ''); ?></div>
                              <div><b>to</b>: <?php echo esc($lcDiff['en']['to'] ?? ''); ?></div>
                            <?php else: ?>-<?php endif; ?>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </table>
                  <?php endif; ?>

                  <?php if (!empty($added)): ?>
                    <h6 style="margin:14px 0 8px;">Added Keys</h6>
                    <pre class="diff-pre diff-small"><?php echo esc(json_encode($added, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)); ?></pre>
                  <?php endif; ?>

                  <?php if (!empty($removed)): ?>
                    <h6 style="margin:14px 0 8px;">Removed Keys</h6>
                    <pre class="diff-pre diff-small"><?php echo esc(json_encode($removed, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)); ?></pre>
                  <?php endif; ?>

                <?php endif; ?>

              <?php else: ?>
                <?php
                  $leafAdded = 0; $leafRemoved = 0; $leafChanged = 0;
                  foreach ($flat as $it) {
                    if (($it['type'] ?? '') === 'added') $leafAdded++;
                    elseif (($it['type'] ?? '') === 'removed') $leafRemoved++;
                    else $leafChanged++;
                  }
                ?>

                <div class="diff-small" style="margin-bottom:8px;">
                  Added: <b><?php echo (int)$leafAdded; ?></b>,
                  Removed: <b><?php echo (int)$leafRemoved; ?></b>,
                  Changed: <b><?php echo (int)$leafChanged; ?></b>
                </div>

                <?php if (empty($flat)): ?>
                  <div class="diff-small">Değişiklik yok.</div>
                <?php else: ?>
                  <h6 style="margin:10px 0 8px;">Detaylı Değişiklikler</h6>
                  <table class="diff-table">
                    <tr>
                      <th style="width:420px;">Path</th>
                      <th style="width:90px;">Type</th>
                      <th>From</th>
                      <th>To</th>
                    </tr>
                    <?php foreach ($flat as $it): ?>
                      <tr>
                        <td><span class="diff-code"><?php echo esc($it['path']); ?></span></td>
                        <td class="diff-small"><?php echo esc($it['type']); ?></td>
                        <td class="diff-small"><?php echo esc(safe_json($it['from'])); ?></td>
                        <td class="diff-small"><?php echo esc(safe_json($it['to'])); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </table>
                <?php endif; ?>
              <?php endif; ?>
            </div>

            <div class="diff-card">
              <h5 style="margin:0 0 8px;">Detay JSON (opsiyonel)</h5>

              <details>
                <summary class="diff-small">Prev Snapshot Data</summary>
                <pre class="diff-pre diff-small"><?php echo esc(json_pretty($oldData)); ?></pre>
              </details>

              <details>
                <summary class="diff-small">Current Snapshot Data</summary>
                <pre class="diff-pre diff-small"><?php echo esc(json_pretty($newData)); ?></pre>
              </details>
            </div>

          </div>

        </div>

        <div class="content-backdrop fade"></div>
      </div>
    </div>
  </div>

  <div class="layout-overlay layout-menu-toggle"></div>
  <div class="drag-target"></div>
</div>

<?php require_once __DIR__ . '/../app/views/layout/footer.php'; ?>

</body>
</html>
