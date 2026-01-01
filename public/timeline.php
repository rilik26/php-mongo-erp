<?php
/**
 * public/timeline.php (FINAL - EVENT01E)
 * - EVENT01E okur (LOG/SNAPSHOT/DIFF için refs var)
 * - Sayfa refresh’inde yeni kayıt üretmez (TIMELINE.VIEW loglama yok)
 */

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/auth/SessionManager.php';
require_once __DIR__ . '/../core/base/Context.php';
require_once __DIR__ . '/../core/base/ContextException.php';

SessionManager::start();

if (!isset($_SESSION['context']) || !is_array($_SESSION['context'])) {
  header('Location: /php-mongo-erp/public/login.php'); exit;
}

try { Context::bootFromSession(); }
catch (ContextException $e) {
  header('Location: /php-mongo-erp/public/login.php'); exit;
}

$ctx = Context::get();

// ---- Filters ----
$eventCode = trim($_GET['event'] ?? '');   // SORD.SAVE gibi
$username  = trim($_GET['user'] ?? '');
$module    = trim($_GET['module'] ?? ''); // salesorder
$docType   = trim($_GET['doc_type'] ?? ''); // SORD01E
$docId     = trim($_GET['doc_id'] ?? '');

$limit = (int)($_GET['limit'] ?? 80);
if ($limit < 10) $limit = 10;
if ($limit > 300) $limit = 300;

// Tenant scope
$cdef   = $ctx['CDEF01_id'] ?? null;
$period = $ctx['PERIOD01T_id'] ?? ($ctx['period_id'] ?? null);

// Filter build
$filter = [];
if ($cdef) $filter['context.CDEF01_id'] = (string)$cdef;

// period: current + GLOBAL
if ($period) {
  $filter['$or'] = [
    ['context.PERIOD01T_id' => (string)$period],
    ['context.period_id' => (string)$period], // eski uyumluluk
    ['context.period_id' => 'GLOBAL'],
  ];
}

if ($username !== '') $filter['context.username'] = $username;
if ($eventCode !== '') $filter['event_code'] = $eventCode;

if ($module !== '') $filter['target.module'] = $module;
if ($docType !== '') $filter['target.doc_type'] = $docType;
if ($docId !== '') $filter['target.doc_id'] = $docId;

$cur = MongoManager::collection('EVENT01E')->find(
  $filter,
  [
    'sort'  => ['created_at' => -1],
    'limit' => $limit,
  ]
);

$events = iterator_to_array($cur);

function esc($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function bson2arr($v) {
  if ($v instanceof MongoDB\Model\BSONDocument || $v instanceof MongoDB\Model\BSONArray) $v = $v->getArrayCopy();
  if (is_array($v)) { $o=[]; foreach($v as $k=>$vv) $o[$k]=bson2arr($vv); return $o; }
  if ($v instanceof MongoDB\BSON\UTCDateTime) return $v->toDateTime()->format('c');
  if ($v instanceof MongoDB\BSON\ObjectId) return (string)$v;
  return $v;
}
$events = array_map('bson2arr', $events);

function fmt_tr($iso): string {
  if (!$iso) return '-';
  try {
    $dt = new DateTime((string)$iso);
    $dt->setTimezone(new DateTimeZone('Europe/Istanbul'));
    return $dt->format('d.m.Y H:i:s');
  } catch (Throwable $e) { return (string)$iso; }
}

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
            .tl-wrap{ max-width:1200px; margin:0 auto; }
            .tl-title{ font-size:22px; font-weight:800; margin:0 0 8px; }
            .tl-sub{ font-size:12px; color:rgba(0,0,0,.55); margin-bottom:10px; }
            .tl-form{ display:grid; grid-template-columns:1fr 1fr 1fr 1fr 1fr auto auto; gap:10px; align-items:center; margin:10px 0 14px; }
            @media (max-width:980px){ .tl-form{ grid-template-columns:1fr 1fr; } }
            .tl-in{ width:100%; height:42px; border:1px solid rgba(0,0,0,.12); border-radius:12px; padding:0 12px; }
            .tl-btn{ height:42px; padding:0 14px; border-radius:12px; border:1px solid rgba(0,0,0,.12); background:#fff; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; gap:8px; }
            .tl-btn-primary{ background:#1e88e5; border-color:#1e88e5; color:#fff; }
            .tl-cards{ display:flex; flex-direction:column; gap:12px; margin-top:12px; }
            .tl-card{ background:#fff; border:1px solid rgba(0,0,0,.10); border-radius:16px; padding:14px; }
            .tl-row1{ display:flex; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:8px; }
            .tl-code{ font-family: ui-monospace, Menlo, Monaco, Consolas, monospace; background:rgba(0,0,0,.05); padding:3px 8px; border-radius:999px; border:1px solid rgba(0,0,0,.10); font-size:12px; }
            .tl-meta{ color:rgba(0,0,0,.55); font-size:12px; display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
            .tl-chips{ display:flex; gap:8px; flex-wrap:wrap; margin-top:8px; }
            .tl-chip{ background:rgba(0,0,0,.03); border:1px solid rgba(0,0,0,.10); padding:5px 10px; border-radius:999px; font-size:12px; }
            .tl-actions{ display:flex; gap:8px; flex-wrap:wrap; margin-top:10px; }
            .tl-dim{ opacity:.45; pointer-events:none; }
          </style>

          <div class="tl-wrap">
            <div class="tl-title">Timeline</div>

            <div class="tl-sub">
              Firma: <b><?php echo esc($ctx['CDEF01_id'] ?? ''); ?></b>
              &nbsp;|&nbsp; Dönem: <b><?php echo esc($ctx['PERIOD01T_id'] ?? ($ctx['period_id'] ?? '')); ?></b>
              &nbsp;|&nbsp; Kullanıcı: <b><?php echo esc($ctx['username'] ?? ''); ?></b>
            </div>

            <form method="GET" class="tl-form">
              <input class="tl-in" name="event"    value="<?php echo esc($eventCode); ?>" placeholder="event_code (örn: SORD.SAVE)">
              <input class="tl-in" name="user"     value="<?php echo esc($username); ?>"  placeholder="username (örn: admin)">
              <input class="tl-in" name="module"   value="<?php echo esc($module); ?>"    placeholder="module (örn: salesorder)">
              <input class="tl-in" name="doc_type" value="<?php echo esc($docType); ?>"   placeholder="doc_type (örn: SORD01E)">
              <input class="tl-in" name="doc_id"   value="<?php echo esc($docId); ?>"     placeholder="doc_id (24 char)">
              <button class="tl-btn tl-btn-primary" type="submit">Getir</button>
              <a class="tl-btn" href="/php-mongo-erp/public/timeline.php">Sıfırla</a>
              <input type="hidden" name="limit" value="<?php echo (int)$limit; ?>">
            </form>

            <div class="tl-cards">
              <?php if (empty($events)): ?>
                <div class="tl-card"><div class="tl-meta">Kayıt bulunamadı.</div></div>
              <?php endif; ?>

              <?php foreach ($events as $ev):
                $code = (string)($ev['event_code'] ?? '');
                $tTr  = fmt_tr((string)($ev['created_at'] ?? ''));
                $user = (string)($ev['context']['username'] ?? '');

                $target = (array)($ev['target'] ?? []);
                $refs   = (array)($ev['refs'] ?? []);
                $data   = (array)($ev['data'] ?? []);
                $sum    = is_array(($data['summary'] ?? null)) ? $data['summary'] : [];

                $logId  = $refs['log_id'] ?? null;
                $snapId = $refs['snapshot_id'] ?? null;
                $prevId = $refs['prev_snapshot_id'] ?? null;

                $tMod = (string)($target['module'] ?? '-');
                $tDt  = (string)($target['doc_type'] ?? '-');
                $tDi  = (string)($target['doc_id'] ?? '-');

                $docNo = (string)($target['doc_no'] ?? '');
                $title = (string)($target['doc_title'] ?? '');
                $status= (string)($target['status'] ?? '');
              ?>
              <div class="tl-card">
                <div class="tl-row1">
                  <div>
                    <div style="font-weight:800;">
                      <?php echo esc($sum['title'] ?? $code); ?>
                      <span class="tl-code"><?php echo esc($code); ?></span>
                    </div>
                    <div class="tl-meta">
                      <?php echo esc($tTr); ?> — <b><?php echo esc($user ?: '-'); ?></b>
                    </div>
                  </div>
                </div>

                <div class="tl-chips">
                  <span class="tl-chip">module: <b><?php echo esc($tMod); ?></b></span>
                  <span class="tl-chip">doc_type: <b><?php echo esc($tDt); ?></b></span>
                  <span class="tl-chip">doc_id: <b><?php echo esc($tDi); ?></b></span>
                  <?php if ($docNo !== ''): ?><span class="tl-chip">doc_no: <b><?php echo esc($docNo); ?></b></span><?php endif; ?>
                  <?php if ($title !== ''): ?><span class="tl-chip">title: <b><?php echo esc($title); ?></b></span><?php endif; ?>
                  <?php if ($status !== ''): ?><span class="tl-chip">status: <b><?php echo esc($status); ?></b></span><?php endif; ?>
                  <?php if (!empty($sum['version'])): ?><span class="tl-chip">version: <b><?php echo esc($sum['version']); ?></b></span><?php endif; ?>
                </div>

                <div class="tl-actions">
                  <?php if ($logId): ?>
                    <a class="tl-btn" target="_blank" href="/php-mongo-erp/public/log_view.php?log_id=<?php echo urlencode($logId); ?>">LOG</a>
                  <?php else: ?>
                    <span class="tl-btn tl-dim">LOG</span>
                  <?php endif; ?>

                  <?php if ($snapId): ?>
                    <a class="tl-btn" target="_blank" href="/php-mongo-erp/public/snapshot_view.php?snapshot_id=<?php echo urlencode($snapId); ?>">SNAPSHOT</a>
                  <?php else: ?>
                    <span class="tl-btn tl-dim">SNAPSHOT</span>
                  <?php endif; ?>

                  <?php if ($snapId && $prevId): ?>
                    <a class="tl-btn" target="_blank" href="/php-mongo-erp/public/snapshot_diff_view.php?snapshot_id=<?php echo urlencode($snapId); ?>">DIFF</a>
                  <?php else: ?>
                    <span class="tl-btn tl-dim">DIFF</span>
                  <?php endif; ?>
                </div>
              </div>
              <?php endforeach; ?>
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
