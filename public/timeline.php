<?php
/**
 * public/timeline.php
 *
 * TIMELINE (V1)
 * - EVENT01E kayıtlarını listeler (en yeni -> eski)
 * - Satırda:
 *   - event_code, zaman, kullanıcı, target, summary
 *   - log link (varsa)
 *   - snapshot diff link (varsa)
 *
 * Guard:
 * - login şart
 * - (istersen sonra permission ekleriz: audit.view gibi)
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

// Timeline görüntüleme log'u (info)
ActionLogger::info('AUDIT.TIMELINE.VIEW', [
    'source' => 'public/timeline.php'
], $ctx);

// --- filtreler ---
$limit = (int)($_GET['limit'] ?? 200);
if ($limit < 20) $limit = 20;
if ($limit > 500) $limit = 500;

$module  = trim($_GET['module'] ?? '');
$docType = trim($_GET['doc_type'] ?? '');
$docId   = trim($_GET['doc_id'] ?? '');
$q       = trim($_GET['q'] ?? ''); // event_code araması vb.

// tenant bazlı filtre (GLOBAL dahil)
$cdef   = $ctx['CDEF01_id'] ?? null;
$period = $ctx['period_id'] ?? null;

// Event query
$filter = [];

// context filtre (multi-tenant)
if ($cdef) $filter['context.CDEF01_id'] = $cdef;

// period: i18n gibi global event’ler için iki yolu da gösterelim:
// - period_id = current
// - period_id = GLOBAL
if ($period) {
    $filter['$or'] = [
        ['context.period_id' => $period],
        ['context.period_id' => 'GLOBAL']
    ];
}

if ($module !== '')  $filter['target.module']   = $module;
if ($docType !== '') $filter['target.doc_type'] = $docType;
if ($docId !== '')   $filter['target.doc_id']   = $docId;

if ($q !== '') {
    // basit arama: event_code içinde
    $filter['event_code'] = ['$regex' => preg_quote($q, '/'), '$options' => 'i'];
}

$cursor = MongoManager::collection('EVENT01E')->find(
    $filter,
    [
        'sort'  => ['created_at' => -1],
        'limit' => $limit,
    ]
);

$events = iterator_to_array($cursor, false);

/**
 * UTCDateTime -> string
 */
function fmtTime($v): string {
    try {
        if ($v instanceof MongoDB\BSON\UTCDateTime) {
            return $v->toDateTime()->format('Y-m-d H:i:s');
        }
    } catch (Throwable $e) {}
    return '';
}

/**
 * Target string
 */
function fmtTarget(?array $t): string {
    if (!$t) return '-';
    $m = $t['module'] ?? '-';
    $d = $t['doc_type'] ?? '-';
    $i = $t['doc_id'] ?? '-';
    $no = $t['doc_no'] ?? null;
    return $m . ' / ' . $d . ' / ' . $i . ($no ? (' (#' . $no . ')') : '');
}

/**
 * Summary (event.data.summary veya event.refs.summary olabilir)
 */
function pickSummary($ev): array {
    $sum = [];
    if (isset($ev['data']['summary']) && is_array($ev['data']['summary'])) {
        $sum = $ev['data']['summary'];
    } elseif (isset($ev['refs']['summary']) && is_array($ev['refs']['summary'])) {
        $sum = $ev['refs']['summary'];
    }
    return $sum;
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Timeline</title>
  <style>
    body{ font-family: Arial, sans-serif; }
    table { border-collapse: collapse; width: 100%; }
    th, td { border:1px solid #ddd; padding:8px; vertical-align: top; }
    th { background:#f7f7f7; text-align:left; }
    .small{ font-size:12px; color:#666; }
    .bar{ display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin:10px 0; }
    .btn{ padding:6px 10px; border:1px solid #ccc; background:#fff; cursor:pointer; text-decoration:none; color:#000; }
    .btn-primary{ border-color:#1e88e5; background:#1e88e5; color:#fff; }
    .pill{ display:inline-block; padding:2px 6px; border-radius:10px; font-size:12px; background:#eee; }
    .code{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
  </style>
</head>
<body>

<?php require_once __DIR__ . '/../app/views/layout/header.php'; ?>

<h3>Timeline (V1)</h3>
<div class="small">
  Firma: <strong><?php echo htmlspecialchars($ctx['CDEF01_id'] ?? ''); ?></strong>
  &nbsp;|&nbsp; Dönem: <strong><?php echo htmlspecialchars($ctx['period_id'] ?? ''); ?></strong>
  &nbsp;|&nbsp; Kullanıcı: <strong><?php echo htmlspecialchars($ctx['username'] ?? ''); ?></strong>
</div>

<form method="GET" class="bar">
  <label class="small">module</label>
  <input type="text" name="module" value="<?php echo htmlspecialchars($module, ENT_QUOTES, 'UTF-8'); ?>" placeholder="i18n">

  <label class="small">doc_type</label>
  <input type="text" name="doc_type" value="<?php echo htmlspecialchars($docType, ENT_QUOTES, 'UTF-8'); ?>" placeholder="LANG01T">

  <label class="small">doc_id</label>
  <input type="text" name="doc_id" value="<?php echo htmlspecialchars($docId, ENT_QUOTES, 'UTF-8'); ?>" placeholder="DICT">

  <label class="small">event_code</label>
  <input type="text" name="q" value="<?php echo htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?>" placeholder="I18N.ADMIN">

  <label class="small">limit</label>
  <input type="number" name="limit" value="<?php echo (int)$limit; ?>" style="width:90px">

  <button class="btn btn-primary" type="submit">Filtrele</button>
  <a class="btn" href="/php-mongo-erp/public/timeline.php">Sıfırla</a>
</form>

<table>
  <tr>
    <th style="width:170px;">Zaman</th>
    <th style="width:210px;">Event</th>
    <th style="width:140px;">Kullanıcı</th>
    <th>Target</th>
    <th>Summary</th>
    <th style="width:220px;">Links</th>
  </tr>

  <?php foreach ($events as $ev):
    $eventId = (string)($ev->_id ?? '');
    $eventCode = (string)($ev['event_code'] ?? '');
    $createdAt = fmtTime($ev['created_at'] ?? null);

    $u = $ev['context']['username'] ?? '';
    $target = $ev['target'] ?? null;

    $refs = $ev['refs'] ?? [];
    $logId = $refs['log_id'] ?? null;
    $snapId = $refs['snapshot_id'] ?? null;

    $sum = pickSummary($ev);
  ?>
    <tr>
      <td class="small"><?php echo htmlspecialchars($createdAt); ?></td>

      <td>
        <div class="code"><strong><?php echo htmlspecialchars($eventCode); ?></strong></div>
        <div class="small">event_id: <span class="code"><?php echo htmlspecialchars($eventId); ?></span></div>
      </td>

      <td><?php echo htmlspecialchars((string)$u); ?></td>

      <td><?php echo htmlspecialchars(fmtTarget(is_array($target) ? $target : null)); ?></td>

      <td class="small">
        <?php if (!empty($sum)): ?>
          <?php foreach ($sum as $k => $v): ?>
            <div><span class="pill"><?php echo htmlspecialchars((string)$k); ?></span>
              <?php
                if (is_array($v)) {
                    echo htmlspecialchars(json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                } else {
                    echo htmlspecialchars((string)$v);
                }
              ?>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          -
        <?php endif; ?>
      </td>

      <td class="small">
        <?php if ($snapId): ?>
          <div>
            <a class="btn" target="_blank"
               href="/php-mongo-erp/public/api/snapshot_diff.php?snapshot_id=<?php echo urlencode((string)$snapId); ?>">
              Diff
            </a>
            <a class="btn" target="_blank"
               href="/php-mongo-erp/public/api/snapshot_get.php?snapshot_id=<?php echo urlencode((string)$snapId); ?>">
              Snapshot
            </a>
          </div>
        <?php endif; ?>

        <?php if ($logId): ?>
          <div style="margin-top:6px;">
            <a class="btn" target="_blank"
               href="/php-mongo-erp/public/api/log_get.php?log_id=<?php echo urlencode((string)$logId); ?>">
              Log
            </a>
          </div>
        <?php endif; ?>

        <?php if (!$snapId && !$logId): ?>
          -
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>

</table>

</body>
</html>
