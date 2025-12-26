<?php
/**
 * public/snapshot_get_view.php
 *
 * Snapshot Get HTML View (V1)
 * - snapshot_id ile snapshot detayını okunur şekilde gösterir
 * - Navigasyon:
 *   - Prev Snapshot (V32)
 *   - Next Snapshot (V34 varsa)
 *   - Diff linki (bu snapshot’ın prev’i ile farkı)
 *   - JSON linki
 */

require_once __DIR__ . '/../core/bootstrap.php';

require_once __DIR__ . '/../core/auth/SessionManager.php';
require_once __DIR__ . '/../core/base/Context.php';
require_once __DIR__ . '/../core/base/ContextException.php';

SessionManager::start();

// login guard
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

function esc($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function dt_tr($v): string {
    if (!$v) return '';
    try {
        if ($v instanceof MongoDB\BSON\UTCDateTime) {
            $d = $v->toDateTime();
        } else {
            $d = new DateTime((string)$v);
        }
        return $d->format('d.m.Y H:i:s');
    } catch (Throwable $e) {
        return (string)$v;
    }
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

$snapshotId = trim($_GET['snapshot_id'] ?? '');
$asJson = (int)($_GET['json'] ?? 0);

if ($snapshotId === '') {
    http_response_code(400);
    echo "snapshot_id required";
    exit;
}

try {
    $sid = new MongoDB\BSON\ObjectId($snapshotId);
} catch (Throwable $e) {
    http_response_code(400);
    echo "invalid snapshot_id";
    exit;
}

$snap = MongoManager::collection('SNAP01E')->findOne(['_id' => $sid]);
if (!$snap) {
    http_response_code(404);
    echo "snapshot not found";
    exit;
}

// normalize to arrays (kolay render için)
$snapArr = bson_to_array($snap);

if ($asJson) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'snapshot' => $snapArr], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$targetKey = (string)($snap['target_key'] ?? '');
$ver = (int)($snap['version'] ?? 0);
$prevIdStr = (string)($snap['prev_snapshot_id'] ?? '');

// prev
$prev = null;
if ($prevIdStr !== '') {
    try {
        $prev = MongoManager::collection('SNAP01E')->findOne(['_id' => new MongoDB\BSON\ObjectId($prevIdStr)]);
    } catch (Throwable $e) { $prev = null; }
}
if (!$prev && $targetKey !== '' && $ver > 1) {
    $prev = MongoManager::collection('SNAP01E')->findOne(['target_key'=>$targetKey,'version'=>$ver-1], ['projection'=>['_id'=>1,'version'=>1]]);
}

// next
$next = null;
if ($targetKey !== '' && $ver > 0) {
    $next = MongoManager::collection('SNAP01E')->findOne(['target_key'=>$targetKey,'version'=>$ver+1], ['projection'=>['_id'=>1,'version'=>1]]);
}

$base = '/php-mongo-erp/public';

$prevLink = $prev ? ($base . '/snapshot_get_view.php?snapshot_id=' . urlencode((string)$prev['_id'])) : '';
$nextLink = $next ? ($base . '/snapshot_get_view.php?snapshot_id=' . urlencode((string)$next['_id'])) : '';

$diffLink = ($prev ? ($base . '/snapshot_diff_view.php?snapshot_id=' . urlencode((string)$snap['_id'])) : '');
$jsonLink = $base . '/snapshot_get_view.php?snapshot_id=' . urlencode((string)$snap['_id']) . '&json=1';

$createdAt = dt_tr($snap['created_at'] ?? null);
$username  = (string)($snap['context']['username'] ?? '-');

$hash = (string)($snap['hash'] ?? '');
$prevHash = (string)($snap['prev_hash'] ?? '');

$data = bson_to_array($snap['data'] ?? []);
$summary = bson_to_array($snap['summary'] ?? []);

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Snapshot</title>
  <style>
    body{ font-family: Arial, sans-serif; background:#0f172a; color:#e5e7eb; margin:0; }
    a{ color:inherit; }
    .wrap{ max-width:1200px; margin:18px auto; padding:0 16px; }
    .card{ background:#111827; border:1px solid rgba(255,255,255,.08); border-radius:14px; padding:14px 16px; margin-bottom:14px; }
    h2{ margin:0; font-size:22px; }
    .muted{ color:#94a3b8; font-size:12px; }
    .bar{ display:flex; gap:10px; flex-wrap:wrap; margin-top:10px; }
    .btn{ display:inline-flex; align-items:center; gap:8px; padding:8px 12px; border-radius:10px; border:1px solid rgba(255,255,255,.12); text-decoration:none; background:#0b1220; }
    .btn:hover{ background:#0a0f1a; }
    .btn-primary{ background:#2563eb; border-color:#2563eb; color:white; }
    .btn-primary:hover{ background:#1d4ed8; }
    .pill{ display:inline-block; padding:2px 8px; border-radius:999px; background:rgba(255,255,255,.08); font-size:12px; }
    .code{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
    table{ width:100%; border-collapse: collapse; margin-top:10px; }
    th, td{ border-bottom:1px solid rgba(255,255,255,.08); padding:10px 8px; vertical-align:top; }
    th{ text-align:left; color:#cbd5e1; font-size:12px; }
    pre{ margin:0; white-space:pre-wrap; word-break:break-word; }
    .grid{ display:grid; grid-template-columns: 1fr 1fr; gap:12px; }
    @media (max-width: 980px){ .grid{ grid-template-columns: 1fr; } }
  </style>
</head>
<body>
<div class="wrap">

  <div class="card">
    <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:12px;">
      <div>
        <h2>Snapshot <span class="pill">V<?=esc($ver)?></span> <span class="muted">id: <span class="code"><?=esc((string)$snap['_id'])?></span></span></h2>
        <div class="muted">
          Zaman: <strong><?=esc($createdAt)?></strong> &nbsp;|&nbsp;
          Kullanıcı: <strong><?=esc($username)?></strong>
        </div>
        <div class="muted">target_key: <span class="code"><?=esc($targetKey)?></span></div>
      </div>

      <a class="btn" href="<?=esc($jsonLink)?>" target="_blank">JSON</a>
    </div>

    <div class="bar">
      <?php if ($prevLink): ?>
        <a class="btn" href="<?=esc($prevLink)?>">← Önceki Snapshot (V<?=esc($ver-1)?>)</a>
      <?php endif; ?>
      <?php if ($nextLink): ?>
        <a class="btn" href="<?=esc($nextLink)?>">Sonraki Snapshot (V<?=esc($ver+1)?>) →</a>
      <?php endif; ?>
      <?php if ($diffLink): ?>
        <a class="btn btn-primary" href="<?=esc($diffLink)?>">Diff (V<?=esc($ver-1)?>→V<?=esc($ver)?>)</a>
      <?php else: ?>
        <span class="muted">Bu snapshot için prev yok → diff yok.</span>
      <?php endif; ?>
    </div>
  </div>

  <div class="grid">
    <div class="card">
      <h3 style="margin:0 0 10px 0;">Hash Bilgisi</h3>
      <table>
        <tr><th style="width:140px;">Hash</th><td class="code"><?=esc($hash)?></td></tr>
        <tr><th>Prev Hash</th><td class="code"><?=esc($prevHash)?></td></tr>
        <tr><th>Prev Snapshot</th><td class="code"><?=esc($prevIdStr)?></td></tr>
      </table>
    </div>

    <div class="card">
      <h3 style="margin:0 0 10px 0;">Summary</h3>
      <?php if (empty($summary)): ?>
        <div class="muted">summary yok</div>
      <?php else: ?>
        <pre><?=esc(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))?></pre>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <h3 style="margin:0 0 10px 0;">Data</h3>
    <div class="muted">Bu kısım snapshot’ın kaydedilmiş tam halidir (data).</div>
    <pre><?=esc(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))?></pre>
  </div>

</div>
</body>
</html>
