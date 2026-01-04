<?php
/**
 * public/timeline.php (FINAL - GROUPED BY DOC)
 *
 * - Theme layout header/left/header2/footer
 * - Tenant scoped listing (CDEF + PERIOD)
 * - Filters + fulltext search (q)
 * - Cursor pagination (after)
 * - Target->doc URL resolver
 * - ✅ NEW: Group by target_key (same doc => single card)
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
if (!is_array($ctx)) $ctx = [];

date_default_timezone_set('Europe/Istanbul');

function esc($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function bson2arr($v) {
  if ($v instanceof MongoDB\Model\BSONDocument || $v instanceof MongoDB\Model\BSONArray) $v = $v->getArrayCopy();
  if (is_array($v)) { $o=[]; foreach($v as $k=>$vv) $o[$k]=bson2arr($vv); return $o; }
  if ($v instanceof MongoDB\BSON\UTCDateTime) return $v->toDateTime()->format('c');
  if ($v instanceof MongoDB\BSON\ObjectId) return (string)$v;
  return $v;
}

function fmt_tr($iso): string {
  if (!$iso) return '-';
  try {
    $dt = new DateTime((string)$iso);
    $dt->setTimezone(new DateTimeZone('Europe/Istanbul'));
    return $dt->format('d.m.Y H:i:s');
  } catch (Throwable $e) { return (string)$iso; }
}

/**
 * Evraka git URL resolver
 */
function doc_url(array $t): ?string {
  $module = strtolower((string)($t['module'] ?? ''));
  $dt = strtoupper((string)($t['doc_type'] ?? ''));
  $di = (string)($t['doc_id'] ?? '');

  if ($dt === 'SORD01E' && $di !== '' && strlen($di) === 24) {
    return '/php-mongo-erp/public/salesorder/edit.php?id=' . rawurlencode($di);
  }
  if ($module === 'salesorder' && $dt === 'SORD01E' && $di !== '' && strlen($di) === 24) {
    return '/php-mongo-erp/public/salesorder/edit.php?id=' . rawurlencode($di);
  }

  // ✅ stok edit resolver
  if ($module === 'stok' && $dt === 'STOK01E' && $di !== '' && strlen($di) === 24) {
    return '/php-mongo-erp/public/stok/edit.php?id=' . rawurlencode($di);
  }

  if ($module === 'i18n' && $dt === 'LANG01T' && $di === 'DICT') {
    return '/php-mongo-erp/public/lang_admin.php';
  }
  if ($module === 'gendoc' && $dt === 'GENDOC01T' && $di !== '') {
    return '/php-mongo-erp/public/gendoc_edit.php?id=' . rawurlencode($di);
  }
  if ($module !== '' && $dt !== '' && $di !== '') {
    return '/php-mongo-erp/public/timeline.php?module=' . rawurlencode($module) . '&doc_type=' . rawurlencode($dt) . '&doc_id=' . rawurlencode($di);
  }
  return null;
}

// ---- Filters ----
$eventCode = trim((string)($_GET['event'] ?? ''));
$username  = trim((string)($_GET['user'] ?? ''));
$module    = trim((string)($_GET['module'] ?? ''));
$docType   = trim((string)($_GET['doc_type'] ?? ''));
$docId     = trim((string)($_GET['doc_id'] ?? ''));
$q         = trim((string)($_GET['q'] ?? ''));

// pagination
$limit = (int)($_GET['limit'] ?? 80);
if ($limit < 10) $limit = 10;
if ($limit > 300) $limit = 300;

// cursor: after = base64("ISO|_id")
$afterRaw = trim((string)($_GET['after'] ?? ''));
$afterIso = '';
$afterId  = '';
if ($afterRaw !== '') {
  $dec = base64_decode($afterRaw, true);
  if ($dec !== false && strpos($dec, '|') !== false) {
    [$afterIso, $afterId] = explode('|', $dec, 2);
    $afterIso = trim((string)$afterIso);
    $afterId  = trim((string)$afterId);
  }
}

// Tenant scope
$cdef   = $ctx['CDEF01_id'] ?? null;
$period = $ctx['PERIOD01T_id'] ?? ($ctx['period_id'] ?? null);

// Filter build
$filter = [];
if ($cdef) $filter['context.CDEF01_id'] = (string)$cdef;

if ($period) {
  $filter['$or'] = [
    ['context.PERIOD01T_id' => (string)$period],
    ['context.period_id' => (string)$period], // legacy
    ['context.period_id' => 'GLOBAL'],
  ];
}

if ($username !== '')  $filter['context.username'] = $username;
if ($eventCode !== '') $filter['event_code'] = $eventCode;

if ($module !== '')  $filter['target.module'] = $module;
if ($docType !== '') $filter['target.doc_type'] = $docType;
if ($docId !== '')   $filter['target.doc_id'] = $docId;

// fulltext-ish search
if ($q !== '') {
  $rx = new MongoDB\BSON\Regex(preg_quote($q), 'i');
  $filter['$and'] = $filter['$and'] ?? [];
  $filter['$and'][] = [
    '$or' => [
      ['event_code' => $rx],
      ['context.username' => $rx],
      ['target.module' => $rx],
      ['target.doc_type' => $rx],
      ['target.doc_id' => $rx],
      ['target.doc_no' => $rx],
      ['target.doc_title' => $rx],
      ['target.doc_status' => $rx],
      ['target.status' => $rx],
    ]
  ];
}

// Cursor pagination (after): created_at DESC, _id DESC
if ($afterIso !== '' && $afterId !== '') {
  try {
    $dt = new DateTime($afterIso);
    $dt->setTimezone(new DateTimeZone('UTC'));
    $afterMs = (int)($dt->format('U')) * 1000;
    $afterUtc = new MongoDB\BSON\UTCDateTime($afterMs);
    $afterOid = new MongoDB\BSON\ObjectId($afterId);

    $filter['$and'] = $filter['$and'] ?? [];
    $filter['$and'][] = [
      '$or' => [
        ['created_at' => ['$lt' => $afterUtc]],
        ['created_at' => $afterUtc, '_id' => ['$lt' => $afterOid]],
      ]
    ];
  } catch (Throwable $e) {
    // ignore invalid cursor
  }
}

// Daha fazla event çekiyoruz, sonra dokümana göre gruplayıp kart sayısını limitliyoruz
$rawLimit = $limit * 6;
if ($rawLimit < 120) $rawLimit = 120;
if ($rawLimit > 2000) $rawLimit = 2000;

$cur = MongoManager::collection('EVENT01E')->find(
  $filter,
  [
    'sort'  => ['created_at' => -1, '_id' => -1],
    'limit' => $rawLimit + 1,
  ]
);

$events = iterator_to_array($cur);
$events = array_map('bson2arr', $events);

// ✅ NEW: group by target_key (fallback: module|type|id)
$groups = []; // key => ['key'=>..., 'head'=>event, 'items'=>[...]]
foreach ($events as $ev) {
  $tkey = (string)($ev['target_key'] ?? '');
  if ($tkey === '') {
    $t = (array)($ev['target'] ?? []);
    $tkey = strtolower((string)($t['module'] ?? '')) . '|' . strtoupper((string)($t['doc_type'] ?? '')) . '|' . (string)($t['doc_id'] ?? '');
  }
  if ($tkey === '||' || trim($tkey) === '') $tkey = (string)($ev['_id'] ?? '');

  if (!isset($groups[$tkey])) {
    $groups[$tkey] = [
      'key' => $tkey,
      'head' => $ev,   // en yeni event head
      'items' => [],
    ];
  }
  $groups[$tkey]['items'][] = $ev;
}

// kart limitine indir (head zaten en yeni olduğu için sıra bozulmasın)
$groupList = array_values($groups);
$hasNext = false;

if (count($groupList) > $limit) {
  $hasNext = true;
  $groupList = array_slice($groupList, 0, $limit);
}

// next cursor: son kartın head event’i üzerinden
$nextCursor = '';
if (!empty($groupList)) {
  $lastHead = $groupList[count($groupList)-1]['head'];
  $lastIso = (string)($lastHead['created_at'] ?? '');
  $lastId  = (string)($lastHead['_id'] ?? '');
  if ($lastIso !== '' && $lastId !== '') {
    $nextCursor = base64_encode($lastIso . '|' . $lastId);
  }
}

// build base query for pagination links (preserve filters)
function build_qs(array $extra = []): string {
  $keep = ['event','user','module','doc_type','doc_id','q','limit'];
  $p = [];
  foreach ($keep as $k) {
    if (isset($_GET[$k]) && $_GET[$k] !== '') $p[$k] = (string)$_GET[$k];
  }
  foreach ($extra as $k => $v) {
    if ($v === null || $v === '') unset($p[$k]);
    else $p[$k] = (string)$v;
  }
  return http_build_query($p);
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

            .tl-form{ display:grid; grid-template-columns:1.2fr 1fr 1fr 1fr 1fr 1fr auto auto; gap:10px; align-items:center; margin:10px 0 14px; }
            @media (max-width:980px){ .tl-form{ grid-template-columns:1fr 1fr; } }

            .tl-in{ width:100%; height:42px; border:1px solid rgba(0,0,0,.12); border-radius:12px; padding:0 12px; }
            .tl-btn{ height:42px; padding:0 14px; border-radius:12px; border:1px solid rgba(0,0,0,.12); background:#fff; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; gap:8px; color:#111; }
            .tl-btn-primary{ background:#1e88e5; border-color:#1e88e5; color:#fff; }
            .tl-dim{ opacity:.45; pointer-events:none; }

            .tl-cards{ display:flex; flex-direction:column; gap:12px; margin-top:12px; }
            .tl-card{ background:#fff; border:1px solid rgba(0,0,0,.10); border-radius:16px; padding:14px; }

            .tl-row1{ display:flex; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:8px; }
            .tl-code{ font-family: ui-monospace, Menlo, Monaco, Consolas, monospace; background:rgba(0,0,0,.05); padding:3px 8px; border-radius:999px; border:1px solid rgba(0,0,0,.10); font-size:12px; }
            .tl-meta{ color:rgba(0,0,0,.55); font-size:12px; display:flex; gap:10px; flex-wrap:wrap; align-items:center; }

            .tl-chips{ display:flex; gap:8px; flex-wrap:wrap; margin-top:8px; }
            .tl-chip{ background:rgba(0,0,0,.03); border:1px solid rgba(0,0,0,.10); padding:5px 10px; border-radius:999px; font-size:12px; }

            .tl-actions{ display:flex; gap:8px; flex-wrap:wrap; margin-top:10px; }

            /* ✅ NEW: action rows inside card */
            .tl-lines{ margin-top:12px; border-top:1px dashed rgba(0,0,0,.12); padding-top:10px; display:flex; flex-direction:column; gap:8px; }
            .tl-line{ display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; }
            .tl-line-left{ display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
            .tl-pill{ font-size:12px; border:1px solid rgba(0,0,0,.12); background:rgba(0,0,0,.03); padding:3px 10px; border-radius:999px; }
            .tl-time{ font-size:12px; color:rgba(0,0,0,.55); }

            .tl-pager{ display:flex; gap:10px; align-items:center; justify-content:flex-end; margin-top:14px; }
            .tl-badge{ font-size:12px; color:rgba(0,0,0,.55); }
          </style>

          <div class="tl-wrap">
            <div class="tl-title">Timeline</div>

            <div class="tl-sub">
              Firma: <b><?php echo esc($ctx['CDEF01_id'] ?? ''); ?></b>
              &nbsp;|&nbsp; Dönem: <b><?php echo esc($ctx['PERIOD01T_id'] ?? ($ctx['period_id'] ?? '')); ?></b>
              &nbsp;|&nbsp; Kullanıcı: <b><?php echo esc($ctx['username'] ?? ''); ?></b>
            </div>

            <form method="GET" class="tl-form">
              <input class="tl-in" name="q"        value="<?php echo esc($q); ?>"         placeholder="Search (doc_no/title/user/event...)">
              <input class="tl-in" name="event"    value="<?php echo esc($eventCode); ?>" placeholder="event_code (örn: LOCK)">
              <input class="tl-in" name="user"     value="<?php echo esc($username); ?>"  placeholder="username (örn: admin)">
              <input class="tl-in" name="module"   value="<?php echo esc($module); ?>"    placeholder="module (örn: stok)">
              <input class="tl-in" name="doc_type" value="<?php echo esc($docType); ?>"   placeholder="doc_type (örn: STOK01E)">
              <input class="tl-in" name="doc_id"   value="<?php echo esc($docId); ?>"     placeholder="doc_id (24 char)">
              <button class="tl-btn tl-btn-primary" type="submit">Getir</button>
              <a class="tl-btn" href="/php-mongo-erp/public/timeline.php">Sıfırla</a>
              <input type="hidden" name="limit" value="<?php echo (int)$limit; ?>">
            </form>

            <div class="tl-cards">
              <?php if (empty($groupList)): ?>
                <div class="tl-card"><div class="tl-meta">Kayıt bulunamadı.</div></div>
              <?php endif; ?>

              <?php foreach ($groupList as $g):
                $head = (array)$g['head'];
                $items = (array)($g['items'] ?? []);

                $target = (array)($head['target'] ?? []);
                $data   = (array)($head['data'] ?? []);
                $sum    = is_array(($data['summary'] ?? null)) ? $data['summary'] : [];

                $tMod = (string)($target['module'] ?? '-');
                $tDt  = (string)($target['doc_type'] ?? '-');
                $tDi  = (string)($target['doc_id'] ?? '-');

                $docNo = (string)($target['doc_no'] ?? '');
                $title = (string)($target['doc_title'] ?? '');
                $docStatus = (string)($target['doc_status'] ?? '');
                if ($docStatus === '') $docStatus = (string)($target['status'] ?? '');

                $docUrl = doc_url($target);

                // head refs
                $refs = (array)($head['refs'] ?? []);
                $logId  = $refs['log_id'] ?? ($head['log_id'] ?? null);
                $snapId = $refs['snapshot_id'] ?? ($head['snapshot_id'] ?? null);
                $prevId = '';
                if (!empty($refs['prev_snapshot_id'])) $prevId = (string)$refs['prev_snapshot_id'];
                elseif (!empty($head['prev_snapshot_id'])) $prevId = (string)$head['prev_snapshot_id'];

                // kart title
                $cardTitle = (string)($sum['title'] ?? '');
                if ($cardTitle === '') {
                  if ($title !== '') $cardTitle = $title;
                  elseif ($docNo !== '') $cardTitle = $docNo;
                  else $cardTitle = strtoupper($tMod) . ' ' . $tDt;
                }

                // kart subtitle / meta
                $headTime = fmt_tr((string)($head['created_at'] ?? ''));
                $headUser = (string)($head['context']['username'] ?? '');
              ?>
              <div class="tl-card">
                <div class="tl-row1">
                  <div>
                    <div style="font-weight:800;">
                      <?php echo esc($cardTitle); ?>
                    </div>
                    <div class="tl-meta">
                      Son işlem: <?php echo esc($headTime); ?> — <b><?php echo esc($headUser ?: '-'); ?></b>
                      <?php if ($docStatus !== ''): ?>
                        — durum: <b><?php echo esc($docStatus); ?></b>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>

                <div class="tl-chips">
                  <span class="tl-chip">module: <b><?php echo esc($tMod); ?></b></span>
                  <span class="tl-chip">doc_type: <b><?php echo esc($tDt); ?></b></span>
                  <span class="tl-chip">doc_id: <b><?php echo esc($tDi); ?></b></span>
                  <?php if ($docNo !== ''): ?><span class="tl-chip">doc_no: <b><?php echo esc($docNo); ?></b></span><?php endif; ?>
                  <?php if ($title !== ''): ?><span class="tl-chip">title: <b><?php echo esc($title); ?></b></span><?php endif; ?>
                </div>

                <div class="tl-actions">
                  <?php if ($docUrl): ?>
                    <a class="tl-btn" target="_blank" href="<?php echo esc($docUrl); ?>">EVRAK</a>
                  <?php else: ?>
                    <span class="tl-btn tl-dim">EVRAK</span>
                  <?php endif; ?>

                  <?php if ($logId): ?>
                    <a class="tl-btn" target="_blank" href="/php-mongo-erp/public/log_view.php?log_id=<?php echo urlencode((string)$logId); ?>">LOG</a>
                  <?php else: ?>
                    <span class="tl-btn tl-dim">LOG</span>
                  <?php endif; ?>

                  <?php if ($snapId): ?>
                    <a class="tl-btn" target="_blank" href="/php-mongo-erp/public/snapshot_view.php?snapshot_id=<?php echo urlencode((string)$snapId); ?>">SNAPSHOT</a>
                  <?php else: ?>
                    <span class="tl-btn tl-dim">SNAPSHOT</span>
                  <?php endif; ?>

                  <?php if ($snapId && $prevId): ?>
                    <a class="tl-btn" target="_blank" href="/php-mongo-erp/public/snapshot_diff_view.php?snapshot_id=<?php echo urlencode((string)$snapId); ?>">DIFF</a>
                  <?php else: ?>
                    <span class="tl-btn tl-dim">DIFF</span>
                  <?php endif; ?>
                </div>

                <!-- ✅ NEW: aynı evrak altındaki tüm eventleri tek kart içinde göster -->
                <div class="tl-lines">
                  <?php
                    $maxLines = 12;
                    $i = 0;
                    foreach ($items as $ev):
                      if ($i >= $maxLines) break;
                      $i++;

                      $code = (string)($ev['event_code'] ?? 'EVENT');
                      $tTr  = fmt_tr((string)($ev['created_at'] ?? ''));
                      $usr  = (string)($ev['context']['username'] ?? '');

                      $d = (array)($ev['data'] ?? []);
                      $s = is_array(($d['summary'] ?? null)) ? $d['summary'] : [];
                      $lineTitle = (string)($s['title'] ?? '');
                      if ($lineTitle === '') $lineTitle = $code;
                      $lineSub = (string)($s['subtitle'] ?? '');

                      $r = (array)($ev['refs'] ?? []);
                      $lineSnap = $r['snapshot_id'] ?? ($ev['snapshot_id'] ?? null);
                      $linePrev = $r['prev_snapshot_id'] ?? ($ev['prev_snapshot_id'] ?? null);
                      $lineLog  = $r['log_id'] ?? ($ev['log_id'] ?? null);

                      $mini = [];
                      if ($lineLog)  $mini[] = '<a class="tl-btn" style="height:32px;padding:0 10px;border-radius:10px;" target="_blank" href="/php-mongo-erp/public/log_view.php?log_id=' . urlencode((string)$lineLog) . '">LOG</a>';
                      if ($lineSnap) $mini[] = '<a class="tl-btn" style="height:32px;padding:0 10px;border-radius:10px;" target="_blank" href="/php-mongo-erp/public/snapshot_view.php?snapshot_id=' . urlencode((string)$lineSnap) . '">SNAP</a>';
                      if ($lineSnap && $linePrev) $mini[] = '<a class="tl-btn" style="height:32px;padding:0 10px;border-radius:10px;" target="_blank" href="/php-mongo-erp/public/snapshot_diff_view.php?snapshot_id=' . urlencode((string)$lineSnap) . '">DIFF</a>';
                  ?>
                    <div class="tl-line">
                      <div class="tl-line-left">
                        <span class="tl-pill"><?php echo esc($code); ?></span>
                        <span class="tl-time"><?php echo esc($tTr); ?></span>
                        <span class="tl-time">— <?php echo esc($usr ?: '-'); ?></span>
                        <?php if ($lineSub !== ''): ?>
                          <span class="tl-time">— <?php echo esc($lineSub); ?></span>
                        <?php endif; ?>
                      </div>
                      <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <?php echo implode('', $mini); ?>
                      </div>
                    </div>
                  <?php endforeach; ?>

                  <?php if (count($items) > $maxLines): ?>
                    <div class="tl-time">+ <?php echo (int)(count($items) - $maxLines); ?> daha fazla event (filtre ile daraltabilirsin)</div>
                  <?php endif; ?>
                </div>

              </div>
              <?php endforeach; ?>
            </div>

            <div class="tl-pager">
              <span class="tl-badge">limit: <b><?php echo (int)$limit; ?></b> — kart: <b><?php echo (int)count($groupList); ?></b></span>
              <?php if ($hasNext && $nextCursor !== ''): ?>
                <a class="tl-btn tl-btn-primary" href="/php-mongo-erp/public/timeline.php?<?php echo esc(build_qs(['after' => $nextCursor])); ?>">Daha Fazla →</a>
              <?php else: ?>
                <span class="tl-btn tl-dim">Daha Fazla →</span>
              <?php endif; ?>
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
