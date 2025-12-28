<?php
/**
 * public/snapshot_view.php (FINAL - THEME)
 *
 * - Snapshot JSON değil, HTML kart UI
 * - Prev / Next snapshot navigation
 * - Diff linki (prev varsa)
 *
 * ✅ Theme Layout: header / left / header2 / footer
 */

require_once __DIR__ . '/../core/bootstrap.php';

require_once __DIR__ . '/../core/auth/SessionManager.php';
require_once __DIR__ . '/../core/base/Context.php';
require_once __DIR__ . '/../core/base/ContextException.php';

require_once __DIR__ . '/../core/action/ActionLogger.php';

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

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

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

function fmt_tr_dt($iso): string {
  if (!$iso) return '-';
  $ts = strtotime((string)$iso);
  if ($ts === false) return (string)$iso;
  date_default_timezone_set('Europe/Istanbul');
  return date('d.m.Y H:i:s', $ts);
}

$snapshotId = trim($_GET['snapshot_id'] ?? '');
if ($snapshotId === '') {
  http_response_code(400);
  echo "snapshot_id_required";
  exit;
}

ActionLogger::info('SNAPSHOT.VIEW', [
  'source' => 'public/snapshot_view.php',
  'snapshot_id' => $snapshotId
], $ctx);

// --- load snapshot ---
$snap = MongoManager::collection('SNAP01E')->findOne(
  ['_id' => new MongoDB\BSON\ObjectId($snapshotId)]
);

if (!$snap) {
  http_response_code(404);
  echo "snapshot_not_found";
  exit;
}

$snap = bson_to_array($snap);

$targetKey = (string)($snap['target_key'] ?? '');
$ver = $snap['version'] ?? null;
$prevId = $snap['prev_snapshot_id'] ?? null;

// --- prev snapshot doc (if exists) ---
$prev = null;
if ($prevId) {
  try {
    $prevDoc = MongoManager::collection('SNAP01E')->findOne(['_id' => new MongoDB\BSON\ObjectId((string)$prevId)]);
    if ($prevDoc) $prev = bson_to_array($prevDoc);
  } catch (Throwable $e) {
    $prev = null;
  }
}

// --- next snapshot doc ---
$next = null;
$nextId = null;

// 1) version+1
if ($targetKey !== '' && $ver !== null && is_numeric($ver)) {
  $nv = (int)$ver + 1;
  $nextDoc = MongoManager::collection('SNAP01E')->findOne(
    ['target_key' => $targetKey, 'version' => $nv],
    ['sort' => ['version' => 1]]
  );
  if ($nextDoc) $next = bson_to_array($nextDoc);
}
// 2) fallback by prev_snapshot_id
if (!$next) {
  $nextDoc = MongoManager::collection('SNAP01E')->findOne(
    ['prev_snapshot_id' => (string)($snap['_id'] ?? $snapshotId)],
    ['sort' => ['version' => 1]]
  );
  if ($nextDoc) $next = bson_to_array($nextDoc);
}

if ($next && isset($next['_id'])) $nextId = (string)$next['_id'];

// urls
$currJsonUrl = '/php-mongo-erp/public/api/snapshot_get.php?snapshot_id=' . rawurlencode((string)$snapshotId);
$diffUrl = ($prevId) ? ('/php-mongo-erp/public/snapshot_diff_view.php?snapshot_id=' . rawurlencode((string)$snapshotId)) : null;

$prevUrl = ($prevId) ? ('/php-mongo-erp/public/snapshot_view.php?snapshot_id=' . rawurlencode((string)$prevId)) : null;
$nextUrl = ($nextId) ? ('/php-mongo-erp/public/snapshot_view.php?snapshot_id=' . rawurlencode((string)$nextId)) : null;

$target = (array)($snap['target'] ?? []);
$ctxSnap = (array)($snap['context'] ?? []);

$createdTr = fmt_tr_dt($snap['created_at'] ?? '');
$user = (string)($ctxSnap['username'] ?? '-');

function pretty_json($arr): string {
  return json_encode($arr, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
}

$data = $snap['data'] ?? [];
$summary = $snap['summary'] ?? null;

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
            .sv-wrap{ max-width:1200px; margin:0 auto; }
            .sv-top{ display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; }
            .sv-h1{ font-size:20px; font-weight:800; margin:0; }
            .sv-small{ font-size:12px; color:rgba(0,0,0,.55); }
            .sv-btn{
              padding:8px 12px; border:1px solid rgba(0,0,0,.12);
              background:#fff; color:#111; border-radius:12px;
              text-decoration:none; display:inline-flex; align-items:center; gap:8px;
              white-space:nowrap;
            }
            .sv-btn:hover{ filter:brightness(.98); }
            .sv-btn-primary{ background:#1e88e5; border-color:#1e88e5; color:#fff; }
            .sv-btn-disabled{ opacity:.45; pointer-events:none; }
            .sv-bar{ display:flex; gap:8px; flex-wrap:wrap; margin-top:10px; }

            .sv-card{
              background:#fff;
              border:1px solid rgba(0,0,0,.10);
              border-radius:16px;
              padding:12px;
              margin-top:12px;
            }
            .sv-ttl{ font-weight:800; margin-bottom:8px; }
            .sv-grid2{ display:grid; grid-template-columns: 1fr 1fr; gap:10px; }
            @media (max-width: 1000px){ .sv-grid2{ grid-template-columns:1fr; } }

            .sv-box{
              background:rgba(0,0,0,.03);
              border:1px solid rgba(0,0,0,.08);
              border-radius:14px;
              padding:10px;
            }
            .sv-kv{ font-size:12px; color:rgba(0,0,0,.65); line-height:1.65; }
            .sv-kv b{ color:#111; font-weight:700; }

            .sv-code{
              font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono","Courier New", monospace;
              font-size:12px;
              background:rgba(0,0,0,.05);
              border:1px solid rgba(0,0,0,.10);
              padding:2px 8px;
              border-radius:999px;
            }

            .sv-pre{
              white-space:pre-wrap; word-break:break-word; margin:0;
              background:rgba(0,0,0,.04);
              border:1px solid rgba(0,0,0,.10);
              padding:12px; border-radius:14px;
              color:rgba(0,0,0,.90);
              font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono","Courier New", monospace;
              font-size:12px;
            }
          </style>

          <div class="sv-wrap">

            <div class="sv-top">
              <div>
                <div class="sv-h1">Snapshot View</div>
                <div class="sv-small">
                  version: <b>v<?php echo h((string)($snap['version'] ?? '-')); ?></b>
                  &nbsp;|&nbsp; time: <b><?php echo h($createdTr); ?></b>
                  &nbsp;|&nbsp; user: <b><?php echo h($user); ?></b>
                </div>

                <div class="sv-bar">
                  <?php if ($prevUrl): ?>
                    <a class="sv-btn" href="<?php echo h($prevUrl); ?>">← Prev Snapshot</a>
                  <?php else: ?>
                    <span class="sv-btn sv-btn-disabled">← Prev Snapshot</span>
                  <?php endif; ?>

                  <?php if ($nextUrl): ?>
                    <a class="sv-btn" href="<?php echo h($nextUrl); ?>">Next Snapshot →</a>
                  <?php else: ?>
                    <span class="sv-btn sv-btn-disabled">Next Snapshot →</span>
                  <?php endif; ?>

                  <?php if ($diffUrl): ?>
                    <a class="sv-btn sv-btn-primary" href="<?php echo h($diffUrl); ?>">Diff</a>
                  <?php else: ?>
                    <span class="sv-btn sv-btn-disabled">Diff</span>
                  <?php endif; ?>

                  <a class="sv-btn" href="<?php echo h($currJsonUrl); ?>" target="_blank">JSON</a>
                </div>
              </div>

              <div class="sv-small">
                target_key:<br>
                <span class="sv-code"><?php echo h($targetKey); ?></span>
              </div>
            </div>

            <div class="sv-card">
              <div class="sv-ttl">Target</div>
              <div class="sv-grid2">
                <div class="sv-box">
                  <div class="sv-kv">
                    <div><b>module</b>: <span class="sv-code"><?php echo h($target['module'] ?? '-'); ?></span></div>
                    <div><b>doc_type</b>: <span class="sv-code"><?php echo h($target['doc_type'] ?? '-'); ?></span></div>
                    <div><b>doc_id</b>: <span class="sv-code"><?php echo h($target['doc_id'] ?? '-'); ?></span></div>
                    <div><b>doc_no</b>: <span class="sv-code"><?php echo h($target['doc_no'] ?? '-'); ?></span></div>
                  </div>
                </div>
                <div class="sv-box">
                  <div class="sv-kv">
                    <div><b>hash</b>: <span class="sv-code"><?php echo h($snap['hash'] ?? '-'); ?></span></div>
                    <div><b>prev_hash</b>: <span class="sv-code"><?php echo h($snap['prev_hash'] ?? '-'); ?></span></div>
                    <div><b>prev_snapshot_id</b>: <span class="sv-code"><?php echo h($prevId ?: '-'); ?></span></div>
                  </div>
                </div>
              </div>
            </div>

            <?php if (is_array($summary) && !empty($summary)): ?>
              <div class="sv-card">
                <div class="sv-ttl">Summary</div>
                <pre class="sv-pre"><?php echo h(pretty_json($summary)); ?></pre>
              </div>
            <?php endif; ?>

            <div class="sv-card">
              <div class="sv-ttl">Data</div>
              <pre class="sv-pre"><?php echo h(pretty_json($data)); ?></pre>
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
