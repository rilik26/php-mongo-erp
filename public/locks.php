<?php
/**
 * public/locks.php
 *
 * Lock List (V1)
 * - Aktif lockları listeler
 * - status: editing|viewing|approving badge
 * - doc_no / doc_title gibi UI-friendly target alanları
 * - Benim lockum -> Release (AJAX + toast)
 * - Admin -> Force Release (AJAX + toast)
 *
 * Guard:
 * - login şart
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
$isAdmin = (($ctx['role'] ?? '') === 'admin');

// view log
ActionLogger::info('LOCKS.VIEW', [
  'source' => 'public/locks.php'
], $ctx);

date_default_timezone_set('Europe/Istanbul');

function esc($s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function fmt_tr_dt($isoOrAnything): string {
  try {
    if ($isoOrAnything instanceof MongoDB\BSON\UTCDateTime) {
      $dt = $isoOrAnything->toDateTime();
      $dt->setTimezone(new DateTimeZone('Europe/Istanbul'));
      return $dt->format('d.m.Y H:i:s');
    }
  } catch (Throwable $e) {}

  $s = (string)$isoOrAnything;
  $ts = strtotime($s);
  if ($ts === false) return $s;
  return date('d.m.Y H:i:s', $ts);
}

function badge_html(string $status): string {
  $status = $status ?: 'editing';

  $map = [
    'editing'   => ['#E3F2FD', '#1565C0', 'EDITING'],
    'viewing'   => ['#F1F8E9', '#2E7D32', 'VIEWING'],
    'approving' => ['#FFF3E0', '#EF6C00', 'APPROVING'],
  ];

  $cfg = $map[$status] ?? $map['editing'];
  [$bg, $fg, $label] = $cfg;

  return '<span style="display:inline-block;padding:3px 8px;border-radius:999px;font-size:12px;background:' . $bg . ';color:' . $fg . ';font-weight:600;">' . $label . '</span>';
}

// --- filters ---
$q = trim($_GET['q'] ?? '');
$onlyMine   = (($_GET['mine'] ?? '0') === '1');
$onlyActive = (($_GET['active'] ?? '1') === '1'); // default 1

$nowMs = (int) floor(microtime(true) * 1000);

// Mongo filter
$filter = [];
if ($onlyActive) {
  $filter['expires_at'] = ['$gt' => new MongoDB\BSON\UTCDateTime($nowMs)];
}
if ($onlyMine) {
  $filter['context.session_id'] = $ctx['session_id'] ?? session_id();
}
if ($q !== '') {
  $regex = new MongoDB\BSON\Regex(preg_quote($q), 'i');
  $filter['$or'] = [
    ['target_key' => $regex],
    ['context.username' => $regex],
    ['target.module' => $regex],
    ['target.doc_type' => $regex],
    ['target.doc_id' => $regex],
    ['target.doc_no' => $regex],
    ['target.doc_title' => $regex],
    ['status' => $regex],
  ];
}

$locksCur = MongoManager::collection('LOCK01E')->find(
  $filter,
  [
    'sort' => ['locked_at' => -1],
    'limit' => 1000,
  ]
);

$locks = [];
foreach ($locksCur as $l) {
  if ($l instanceof MongoDB\Model\BSONDocument) $l = $l->getArrayCopy();
  $locks[] = $l;
}

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Locks</title>
  <style>
    body{ font-family: Arial, sans-serif; }
    table { border-collapse: collapse; width: 100%; }
    th, td { border:1px solid #ddd; padding:8px; vertical-align: top; }
    th { background:#f7f7f7; text-align:left; }
    .bar{ display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin:10px 0; }
    .btn{ padding:6px 10px; border:1px solid #ccc; background:#fff; cursor:pointer; text-decoration:none; color:#000; border-radius:6px; }
    .btn-primary{ border-color:#1e88e5; background:#1e88e5; color:#fff; }
    .btn-danger{ border-color:#e53935; background:#e53935; color:#fff; }
    .btn-warn{ border-color:#f9a825; background:#f9a825; color:#000; }
    .small{ font-size:12px; color:#666; }
    .code{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
    .muted{ color:#888; }
    input[type="text"]{ padding:6px 8px; border:1px solid #ddd; border-radius:6px; min-width:240px; background:#fff; color:#000; }
    label{ font-size:12px; color:#444; }
    .notes{ margin-top:10px; padding:10px; border:1px solid #eee; border-radius:8px; background:#fafafa; }
    .notes ul{ margin:6px 0 0 18px; }
  </style>
</head>
<body>

<?php require_once __DIR__ . '/../app/views/layout/header.php'; ?>

<h3>Locks</h3>
<div class="small">
  Kullanıcı: <strong><?php echo esc($ctx['username'] ?? ''); ?></strong>
  &nbsp;|&nbsp; Role: <strong><?php echo esc($ctx['role'] ?? ''); ?></strong>
  &nbsp;|&nbsp; Firma: <strong><?php echo esc($ctx['CDEF01_id'] ?? ''); ?></strong>
  &nbsp;|&nbsp; Dönem: <strong><?php echo esc($ctx['period_id'] ?? ''); ?></strong>
</div>

<form method="GET" class="bar">
  <label>Search</label>
  <input type="text" name="q" value="<?php echo esc($q); ?>" placeholder="user/module/doc_no/doc_title/target_key">

  <!-- checkbox fix: unchecked iken de 0 gitsin -->
  <input type="hidden" name="mine" value="0">
  <label>
    <input type="checkbox" name="mine" value="1" <?php echo $onlyMine ? 'checked' : ''; ?>>
    Sadece benim
  </label>

  <input type="hidden" name="active" value="0">
  <label>
    <input type="checkbox" name="active" value="1" <?php echo $onlyActive ? 'checked' : ''; ?>>
    Sadece aktif
  </label>

  <button class="btn btn-primary" type="submit">Getir</button>
  <a class="btn" href="/php-mongo-erp/public/locks.php">Sıfırla</a>
</form>

<table>
  <tr>
    <th style="width:120px;">Status</th>
    <th style="width:170px;">Kilit Zamanı</th>
    <th style="width:170px;">Bitiş (TTL)</th>
    <th style="width:170px;">Kullanıcı</th>
    <th style="width:260px;">Target</th>
    <th>Target Key</th>
    <th style="width:220px;">Actions</th>
  </tr>

  <?php if (count($locks) === 0): ?>
    <tr><td colspan="7" class="small">Lock bulunamadı.</td></tr>
  <?php else: ?>
    <?php foreach ($locks as $l):

      $status = (string)($l['status'] ?? 'editing');

      $lockedAt  = $l['locked_at'] ?? null;
      $expiresAt = $l['expires_at'] ?? null;

      $expiresMs = null;
      if ($expiresAt instanceof MongoDB\BSON\UTCDateTime) {
        $expiresMs = ((int)$expiresAt->toDateTime()->format('U')) * 1000;
      } else {
        $tmp = strtotime((string)$expiresAt);
        if ($tmp !== false) $expiresMs = $tmp * 1000;
      }

      $ttlLeft = '';
      $nowMs2 = (int) floor(microtime(true) * 1000);
      if ($expiresMs !== null) {
        $diff = $expiresMs - $nowMs2;
        if ($diff <= 0) {
          $ttlLeft = '<span class="muted">expired</span>';
        } else {
          $sec = (int) floor($diff / 1000);
          $min = (int) floor($sec / 60);
          $rem = $sec % 60;
          $ttlLeft = $min . 'm ' . $rem . 's';
        }
      }

      $c = $l['context'] ?? [];
      if ($c instanceof MongoDB\Model\BSONDocument) $c = $c->getArrayCopy();

      $user = $c['username'] ?? '';
      $sess = $c['session_id'] ?? '';
      $isMine = ($sess !== '' && ($ctx['session_id'] ?? session_id()) === $sess);

      $t = $l['target'] ?? [];
      if ($t instanceof MongoDB\Model\BSONDocument) $t = $t->getArrayCopy();

      $tModule  = $t['module'] ?? '';
      $tDocType = $t['doc_type'] ?? '';
      $tDocId   = $t['doc_id'] ?? '';
      $tDocNo   = $t['doc_no'] ?? '';
      $tTitle   = $t['doc_title'] ?? '';

      $targetKey = (string)($l['target_key'] ?? '');
    ?>
      <tr>
        <td><?php echo badge_html($status); ?></td>
        <td class="small"><?php echo esc(fmt_tr_dt($lockedAt)); ?></td>

        <td class="small">
          <?php echo esc(fmt_tr_dt($expiresAt)); ?>
          <div class="small muted">TTL: <?php echo $ttlLeft; ?></div>
        </td>

        <td>
          <div><strong><?php echo esc($user); ?></strong></div>
          <div class="small muted">session: <span class="code"><?php echo esc($sess); ?></span></div>
        </td>

        <td class="small">
          <div><span class="code"><?php echo esc($tModule); ?></span> / <span class="code"><?php echo esc($tDocType); ?></span></div>
          <div>doc_id: <span class="code"><?php echo esc($tDocId); ?></span></div>
          <?php if ($tDocNo !== ''): ?>
            <div>doc_no: <span class="code"><?php echo esc($tDocNo); ?></span></div>
          <?php endif; ?>
          <?php if ($tTitle !== ''): ?>
            <div>title: <?php echo esc($tTitle); ?></div>
          <?php endif; ?>
        </td>

        <td class="small"><span class="code"><?php echo esc($targetKey); ?></span></td>

        <td>
          <button class="btn btn-warn"
  onclick="acquireLock('<?php echo esc($tModule); ?>','<?php echo esc($tDocType); ?>','<?php echo esc($tDocId); ?>')">Acquire</button>


          <?php if ($isMine): ?>
<button class="btn btn-danger"
  onclick="releaseLock('<?php echo esc($tModule); ?>','<?php echo esc($tDocType); ?>','<?php echo esc($tDocId); ?>', false)">Release</button>

          <?php elseif ($isAdmin): ?>
<button class="btn btn-danger"
  onclick="releaseLock('<?php echo esc($tModule); ?>','<?php echo esc($tDocType); ?>','<?php echo esc($tDocId); ?>', true)">Force Release</button>

          <?php else: ?>
            <span class="small muted">-</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  <?php endif; ?>
</table>

<div class="notes small">
  <strong>Notlar:</strong>
  <ul>
    <li>“Acquire” endpointi aynı session ise TTL yenileyebilir (refresh gibi davranır).</li>
    <li>“Release” normalde sadece lock sahibi için çalışmalı. Admin için force paramı kullanıyoruz.</li>
    <li>Release sonrası sekmeyi kapatmayı deneriz; olmazsa index’e yönlendiririz. (şimdilik uygulamadık)</li>
  </ul>
</div>

<script>
/**
 * Toast: Header'da showToast varsa onu kullanır.
 * Yoksa kendi toast'ını üretir (garantili).
 */
(function ensureToast(){
  if (typeof window.showToast === 'function') return;

  // mini toast container
  const box = document.createElement('div');
  box.id = 'toastBox';
  box.style.position = 'fixed';
  box.style.right = '16px';
  box.style.top = '16px';
  box.style.zIndex = '99999';
  box.style.display = 'flex';
  box.style.flexDirection = 'column';
  box.style.gap = '8px';
  document.body.appendChild(box);

  window.showToast = function(msg, type){
    const t = document.createElement('div');
    t.textContent = String(msg || '');
    t.style.padding = '10px 12px';
    t.style.borderRadius = '10px';
    t.style.border = '1px solid rgba(0,0,0,.08)';
    t.style.boxShadow = '0 10px 20px rgba(0,0,0,.08)';
    t.style.background = '#fff';
    t.style.fontSize = '13px';
    t.style.maxWidth = '360px';

    // basit type vurgusu
    if (type === 'success') t.style.borderLeft = '6px solid #2e7d32';
    else if (type === 'error') t.style.borderLeft = '6px solid #e53935';
    else if (type === 'warning') t.style.borderLeft = '6px solid #f9a825';
    else t.style.borderLeft = '6px solid #1565c0';

    box.appendChild(t);

    setTimeout(() => {
      t.style.opacity = '0';
      t.style.transition = 'opacity .2s ease';
      setTimeout(() => t.remove(), 250);
    }, 2500);
  };
})();

function toast(msg, type){
  window.showToast(String(msg || ''), type || 'info');
}

function acquireLock(module, docType, docId){
  const url = '/php-mongo-erp/public/api/lock_acquire.php?module='
    + encodeURIComponent(module)
    + '&doc_type=' + encodeURIComponent(docType)
    + '&doc_id=' + encodeURIComponent(docId)
    + '&status=editing';

  fetch(url, { credentials:'same-origin' })
    .then(r => r.json())
    .then(d => {
      if (!d || !d.ok) {
        toast((d && d.error) ? d.error : 'Acquire failed', 'error');
        return;
      }

      if (d.acquired) {
        toast('Lock alındı', 'success');
        location.reload();
        return;
      }

      // acquired=false
      const reason = d.reason || d.message || 'Lock alınamadı';
      toast(reason, 'warning');
    })
    .catch(() => toast('Acquire failed', 'error'));
}

/**
 * ✅ Release endpoint target_key değil module/doc_type/doc_id istiyor.
 */
function releaseLock(module, docType, docId, force){
  let url = '/php-mongo-erp/public/api/lock_release.php?module='
    + encodeURIComponent(module)
    + '&doc_type=' + encodeURIComponent(docType)
    + '&doc_id=' + encodeURIComponent(docId);

  if (force) url += '&force=1';

  fetch(url, { credentials:'same-origin' })
    .then(r => r.json())
    .then(d => {
      if (!d || !d.ok) {
        toast((d && d.error) ? d.error : 'Release failed', 'error');
        return;
      }
      toast('Lock bırakıldı', 'success');
      location.reload();
    })
    .catch(() => toast('Release failed', 'error'));
}
</script>


</body>
</html>
