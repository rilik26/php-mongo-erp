<?php
/**
 * public/gendoc_admin.php
 *
 * GENEL EVRAK DEMO (V1)
 * - GENDOC01E header
 * - GENDOC01T body version
 * - Snapshot + Event + Lock ile entegre
 */

require_once __DIR__ . '/../core/bootstrap.php';

require_once __DIR__ . '/../core/auth/SessionManager.php';
require_once __DIR__ . '/../core/base/Context.php';
require_once __DIR__ . '/../core/base/ContextException.php';

require_once __DIR__ . '/../core/action/ActionLogger.php';
require_once __DIR__ . '/../core/event/EventWriter.php';
require_once __DIR__ . '/../core/snapshot/SnapshotWriter.php';

require_once __DIR__ . '/../app/modules/gendoc/GENDOC01ERepository.php';
require_once __DIR__ . '/../app/modules/gendoc/GENDOC01TRepository.php';

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

$module  = trim($_GET['module'] ?? 'gen');
$docType = trim($_GET['doc_type'] ?? 'GENDOC01T');
$docId   = trim($_GET['doc_id'] ?? 'DEMO-1');

$docNo = trim($_GET['doc_no'] ?? $docId);
$title = trim($_GET['doc_title'] ?? 'Genel Evrak Demo');

$msg = null; $err = null;

$target = [
  'module' => $module,
  'doc_type' => $docType,
  'doc_id' => $docId,
  'doc_no' => $docNo,
  'doc_title' => $title,
];

// view log + event
$viewLogId = ActionLogger::info('GENDOC.VIEW', ['source'=>'public/gendoc_admin.php'], $ctx);
EventWriter::emit('GENDOC.VIEW', ['source'=>'public/gendoc_admin.php'], $target, $ctx, ['log_id'=>$viewLogId]);

$latest = GENDOC01TRepository::findLatestByTarget($ctx, $target);
$initialJson = $latest['data'] ?? ['hello'=>'world'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $raw = trim($_POST['json'] ?? '');
  $status = trim($_POST['status'] ?? 'draft');

  $payload = json_decode($raw, true);
  if (!is_array($payload)) {
    $err = 'JSON geçersiz.';
  } else {
    // header upsert
    GENDOC01ERepository::upsertHeader($ctx, $target, ['status'=>$status]);

    // body version insert
    $ins = GENDOC01TRepository::insertVersion($ctx, $target, $payload);

    // snapshot: final state olarak payload
    $snap = SnapshotWriter::capture(
      $target,
      ['data' => $payload],
      ['reason'=>'gendoc_save','changed_fields'=>['data']]
    );

    // save log + event
    $saveLogId = ActionLogger::success('GENDOC.SAVE', ['source'=>'public/gendoc_admin.php'], $ctx);

    EventWriter::emit(
      'GENDOC.SAVE',
      [
        'source'=>'public/gendoc_admin.php',
        'status'=>$status,
        'summary'=>[
          'mode'=>'gendoc',
          'version'=>$ins['version'] ?? null,
        ],
      ],
      $target,
      $ctx,
      [
        'log_id'=>$saveLogId,
        'snapshot_id'=>$snap['snapshot_id'] ?? null,
        'prev_snapshot_id'=>$snap['prev_snapshot_id'] ?? null,
      ]
    );

    $msg = 'Kaydedildi. Version: ' . ($ins['version'] ?? '?');
    $initialJson = $payload;
  }
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Genel Evrak</title>
  <style>
    body{ font-family: Arial, sans-serif; padding:14px; }
    .row{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin:10px 0; }
    input, select, textarea{ padding:8px; border:1px solid #ddd; border-radius:8px; }
    textarea{ width:100%; height:320px; font-family: ui-monospace, monospace; }
    .btn{ padding:8px 12px; border:1px solid #ccc; border-radius:8px; cursor:pointer; background:#fff; }
    .btn-primary{ background:#1e88e5; color:#fff; border-color:#1e88e5; }
    .small{ color:#666; font-size:12px; }
  </style>
</head>
<body>

<?php require_once __DIR__ . '/../app/views/layout/header.php'; ?>

<h3>GENEL EVRAK (GENDOC)</h3>
<div class="small">
  target: <b><?php echo h($module.' / '.$docType.' / '.$docId); ?></b>
</div>

<?php if ($msg): ?><p style="color:green;"><?php echo h($msg); ?></p><?php endif; ?>
<?php if ($err): ?><p style="color:red;"><?php echo h($err); ?></p><?php endif; ?>

<form method="POST">
  <div class="row">
    <label class="small">status</label>
    <select name="status">
      <?php foreach (['draft','saved','approving','approved','cancelled'] as $s): ?>
        <option value="<?php echo h($s); ?>"><?php echo h($s); ?></option>
      <?php endforeach; ?>
    </select>

    <button class="btn btn-primary" type="submit">Save</button>

    <a class="btn" target="_blank" href="/php-mongo-erp/public/audit_view.php?module=<?php echo urlencode($module); ?>&doc_type=<?php echo urlencode($docType); ?>&doc_id=<?php echo urlencode($docId); ?>">Audit View</a>

    <a class="btn" target="_blank" href="/php-mongo-erp/public/timeline.php?module=<?php echo urlencode($module); ?>&doc_type=<?php echo urlencode($docType); ?>&doc_id=<?php echo urlencode($docId); ?>">Timeline</a>
  </div>

  <textarea name="json"><?php echo h(json_encode($initialJson, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); ?></textarea>
</form>

</body>
</html>
