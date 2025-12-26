<?php
/**
 * public/snapshot_diff_view.php
 *
 * Snapshot Diff HTML View (V1)
 * - snapshot_id verilir
 * - Bu snapshot (latest) ile prev_snapshot arasındaki diff'i okunur şekilde gösterir
 * - Navigasyon:
 *   - Prev Snapshot (V32)
 *   - Next Snapshot (V34 varsa)
 *   - Prev Diff (V31->V32)
 *   - Next Diff (V33->V34)
 */

require_once __DIR__ . '/../core/bootstrap.php';

require_once __DIR__ . '/../core/auth/SessionManager.php';
require_once __DIR__ . '/../core/base/Context.php';
require_once __DIR__ . '/../core/base/ContextException.php';

require_once __DIR__ . '/../core/snapshot/SnapshotDiff.php';

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

function dt_tr($iso): string {
    // ISO veya DateTime gelebilir. Biz TR format üretelim: dd.mm.yyyy HH:ii:ss
    if (!$iso) return '';
    try {
        if ($iso instanceof MongoDB\BSON\UTCDateTime) {
            $d = $iso->toDateTime();
        } else {
            $d = new DateTime((string)$iso);
        }
        return $d->format('d.m.Y H:i:s');
    } catch (Throwable $e) {
        return (string)$iso;
    }
}

$snapshotId = trim($_GET['snapshot_id'] ?? '');
$asJson = (int)($_GET['json'] ?? 0);

if ($snapshotId === '') {
    http_response_code(400);
    echo "snapshot_id required";
    exit;
}

// --- load latest snapshot ---
try {
    $sid = new MongoDB\BSON\ObjectId($snapshotId);
} catch (Throwable $e) {
    http_response_code(400);
    echo "invalid snapshot_id";
    exit;
}

$latest = MongoManager::collection('SNAP01E')->findOne(['_id' => $sid]);
if (!$latest) {
    http_response_code(404);
    echo "snapshot not found";
    exit;
}

$targetKey = (string)($latest['target_key'] ?? '');
$latestVer = (int)($latest['version'] ?? 0);

// prev snapshot
$prevIdStr = (string)($latest['prev_snapshot_id'] ?? '');
$prev = null;

if ($prevIdStr !== '') {
    try {
        $prev = MongoManager::collection('SNAP01E')->findOne(['_id' => new MongoDB\BSON\ObjectId($prevIdStr)]);
    } catch (Throwable $e) {
        $prev = null;
    }
}

// fallback: version-1 ile bul (prev_snapshot_id yoksa)
if (!$prev && $targetKey !== '' && $latestVer > 1) {
    $prev = MongoManager::collection('SNAP01E')->findOne(
        ['target_key' => $targetKey, 'version' => $latestVer - 1]
    );
}

$prevVer = $prev ? (int)($prev['version'] ?? 0) : null;

// next snapshot (varsa)
$next = null;
if ($targetKey !== '' && $latestVer > 0) {
    $next = MongoManager::collection('SNAP01E')->findOne(
        ['target_key' => $targetKey, 'version' => $latestVer + 1],
        ['projection' => ['_id' => 1, 'version' => 1, 'created_at' => 1, 'context.username' => 1]]
    );
}

// --- diff calc ---
$diff = [
    'added_keys'   => [],
    'removed_keys' => [],
    'changed_keys' => []
];

if ($prev) {
    $oldRows = (array)($prev['data']['rows'] ?? []);
    $newRows = (array)($latest['data']['rows'] ?? []);
    // Lang diff helper'ın varsa onu kullan; yoksa generic diff'e düşebilirsin
    if (method_exists('SnapshotDiff', 'diffLangRows')) {
        $diff = SnapshotDiff::diffLangRows($oldRows, $newRows);
    } else {
        // basic fallback (sadece key bazlı değişim)
        $oldKeys = array_keys($oldRows);
        $newKeys = array_keys($newRows);

        $diff['added_keys'] = array_values(array_diff($newKeys, $oldKeys));
        $diff['removed_keys'] = array_values(array_diff($oldKeys, $newKeys));

        $common = array_intersect($oldKeys, $newKeys);
        foreach ($common as $k) {
            if ($oldRows[$k] != $newRows[$k]) {
                $diff['changed_keys'][$k] = ['from' => $oldRows[$k], 'to' => $newRows[$k]];
            }
        }
    }
}

if ($asJson) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'target_key' => $targetKey,
        'prev' => $prev ? [
            'id' => (string)$prev['_id'],
            'version' => $prevVer,
        ] : null,
        'latest' => [
            'id' => (string)$latest['_id'],
            'version' => $latestVer,
        ],
        'next' => $next ? [
            'id' => (string)$next['_id'],
            'version' => (int)($next['version'] ?? 0),
        ] : null,
        'diff' => $diff,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// url helpers
$base = '/php-mongo-erp/public';

$latestId = (string)$latest['_id'];
$prevId = $prev ? (string)$prev['_id'] : '';
$nextId = $next ? (string)$next['_id'] : '';

$prevDiffLink = $prevId ? ($base . '/snapshot_diff_view.php?snapshot_id=' . urlencode($prevId)) : '';
$nextDiffLink = $nextId ? ($base . '/snapshot_diff_view.php?snapshot_id=' . urlencode($nextId)) : '';

$prevSnapLink = $prevId ? ($base . '/snapshot_get_view.php?snapshot_id=' . urlencode($prevId)) : '';
$latestSnapLink = $base . '/snapshot_get_view.php?snapshot_id=' . urlencode($latestId);
$nextSnapLink = $nextId ? ($base . '/snapshot_get_view.php?snapshot_id=' . urlencode($nextId)) : '';

$jsonLink = $base . '/snapshot_diff_view.php?snapshot_id=' . urlencode($latestId) . '&json=1';

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Snapshot Diff</title>
  <style>
    body{ font-family: Arial, sans-serif; background:#0f172a; color:#e5e7eb; margin:0; }
    a{ color:inherit; }
    .wrap{ max-width:1200px; margin:18px auto; padding:0 16px; }
    .card{ background:#111827; border:1px solid rgba(255,255,255,.08); border-radius:14px; padding:14px 16px; margin-bottom:14px; }
    .title{ display:flex; align-items:center; gap:10px; justify-content:space-between; }
    h2{ margin:0; font-size:22px; }
    .muted{ color:#94a3b8; font-size:12px; }
    .bar{ display:flex; gap:10px; flex-wrap:wrap; margin-top:10px; }
    .btn{ display:inline-flex; align-items:center; gap:8px; padding:8px 12px; border-radius:10px; border:1px solid rgba(255,255,255,.12); text-decoration:none; background:#0b1220; }
    .btn:hover{ background:#0a0f1a; }
    .btn-primary{ background:#2563eb; border-color:#2563eb; color:white; }
    .btn-primary:hover{ background:#1d4ed8; }
    .pill{ display:inline-block; padding:2px 8px; border-radius:999px; background:rgba(255,255,255,.08); font-size:12px; }
    table{ width:100%; border-collapse: collapse; margin-top:8px; }
    th, td{ border-bottom:1px solid rgba(255,255,255,.08); padding:10px 8px; vertical-align:top; }
    th{ text-align:left; color:#cbd5e1; font-size:12px; }
    .code{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
    .grid{ display:grid; grid-template-columns: 1fr 1fr; gap:12px; }
    @media (max-width: 980px){ .grid{ grid-template-columns: 1fr; } }
  </style>
</head>
<body>
<div class="wrap">

  <div class="card">
    <div class="title">
      <div>
        <h2>Snapshot Diff <span class="muted">snapshot_id: <span class="code"><?=esc($latestId)?></span></span></h2>
        <div class="muted">Bu ekran, <strong>V<?=esc($prevVer ?? '-')?></strong> → <strong>V<?=esc($latestVer)?></strong> farkını gösterir.</div>
      </div>
      <a class="btn" href="<?=esc($jsonLink)?>" target="_blank">JSON</a>
    </div>

    <div class="bar">
      <span class="pill">target_key: <span class="code"><?=esc($targetKey)?></span></span>
    </div>

    <div class="grid" style="margin-top:12px;">
      <div class="card" style="margin:0;">
        <div><strong>Latest Snapshot</strong> <span class="pill">V<?=esc($latestVer)?></span></div>
        <div class="muted">Zaman: <?=esc(dt_tr($latest['created_at'] ?? ''))?> &nbsp;|&nbsp;
          Kullanıcı: <?=esc($latest['context']['username'] ?? '-')?>
        </div>
        <div class="muted">Hash: <span class="code"><?=esc($latest['hash'] ?? '')?></span></div>
        <div class="bar">
          <a class="btn btn-primary" href="<?=esc($latestSnapLink)?>" target="_blank">Snapshot Gör</a>
          <?php if ($nextSnapLink): ?>
            <a class="btn" href="<?=esc($nextSnapLink)?>" target="_blank">Sonraki Snapshot (V<?=esc((int)($next['version'] ?? 0))?>)</a>
          <?php endif; ?>
        </div>
      </div>

      <div class="card" style="margin:0;">
        <div><strong>Prev Snapshot</strong> <span class="pill">V<?=esc($prevVer ?? '-')?></span></div>
        <div class="muted">
          <?php if ($prev): ?>
            Zaman: <?=esc(dt_tr($prev['created_at'] ?? ''))?> &nbsp;|&nbsp;
            Kullanıcı: <?=esc($prev['context']['username'] ?? '-')?>
          <?php else: ?>
            Önceki snapshot bulunamadı.
          <?php endif; ?>
        </div>
        <div class="muted">
          <?php if ($prev): ?>
            Hash: <span class="code"><?=esc($prev['hash'] ?? '')?></span>
          <?php endif; ?>
        </div>
        <div class="bar">
          <?php if ($prevSnapLink): ?>
            <a class="btn btn-primary" href="<?=esc($prevSnapLink)?>" target="_blank">Önceki Snapshot’ı Aç</a>
            <a class="btn" href="<?=esc($prevDiffLink)?>">Önceki Diff (V<?=esc(($prevVer ?? 0)-1)?>→V<?=esc($prevVer)?>)</a>
          <?php endif; ?>

          <?php if ($nextDiffLink): ?>
            <a class="btn" href="<?=esc($nextDiffLink)?>">Sonraki Diff (V<?=esc($latestVer)?>→V<?=esc($latestVer+1)?>)</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <h3 style="margin:0 0 8px 0;">Özet</h3>
    <div class="muted">
      added: <?=esc(is_array($diff['added_keys'] ?? null) ? count($diff['added_keys']) : 0)?> |
      removed: <?=esc(is_array($diff['removed_keys'] ?? null) ? count($diff['removed_keys']) : 0)?> |
      changed: <?=esc(is_array($diff['changed_keys'] ?? null) ? count($diff['changed_keys']) : 0)?>
    </div>

    <h3 style="margin:14px 0 8px 0;">Changed Keys</h3>
    <?php
      $changed = $diff['changed_keys'] ?? [];
      if (!is_array($changed)) $changed = [];
    ?>
    <table>
      <tr>
        <th style="width:320px;">Key</th>
        <th>TR</th>
        <th>EN</th>
      </tr>

      <?php if (empty($changed)): ?>
        <tr><td colspan="3" class="muted">Değişiklik yok.</td></tr>
      <?php else: ?>
        <?php foreach ($changed as $k => $v): ?>
          <?php
            // lang diff beklenen format:
            // changed_keys[key]['tr']['from'/'to'] , ['en']['from'/'to']
            $trFrom = $v['tr']['from'] ?? '';
            $trTo   = $v['tr']['to'] ?? '';
            $enFrom = $v['en']['from'] ?? '';
            $enTo   = $v['en']['to'] ?? '';
          ?>
          <tr>
            <td class="code"><strong><?=esc($k)?></strong></td>
            <td><?=esc($trFrom)?> <span class="muted">→</span> <strong><?=esc($trTo)?></strong></td>
            <td><?=esc($enFrom)?> <span class="muted">→</span> <strong><?=esc($enTo)?></strong></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </table>
  </div>

</div>
</body>
</html>
