<?php
/**
 * public/gendoc_list.php (FINAL)
 *
 * GENDOC list UI
 */

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/auth/SessionManager.php';
require_once __DIR__ . '/../core/base/Context.php';
require_once __DIR__ . '/../core/base/ContextException.php';
require_once __DIR__ . '/../core/action/ActionLogger.php';

require_once __DIR__ . '/../app/modules/gendoc/GENDOC01ERepository.php';

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

ActionLogger::info('GENDOC.LIST.VIEW', ['source'=>'public/gendoc_list.php'], $ctx);

function esc($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmt_tr($iso): string {
  if (!$iso) return '-';
  try {
    $dt = new DateTime((string)$iso);
    $dt->setTimezone(new DateTimeZone('Europe/Istanbul'));
    return $dt->format('d.m.Y H:i:s');
  } catch(Throwable $e) { return (string)$iso; }
}

$q = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$module = trim($_GET['module'] ?? '');
$docType = trim($_GET['doc_type'] ?? '');
$limit = (int)($_GET['limit'] ?? 200);

$rows = GENDOC01ERepository::list($ctx, [
  'q' => $q,
  'status' => $status,
  'module' => $module,
  'doc_type' => $docType,
], $limit);

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>GenDoc List</title>
  <style>
    body{ font-family: Arial, sans-serif; }
    table { border-collapse: collapse; width: 100%; }
    th, td { border:1px solid #ddd; padding:8px; vertical-align: top; }
    th { background:#f7f7f7; text-align:left; }
    .bar{ display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin:10px 0; }
    .btn{ padding:6px 10px; border:1px solid #ccc; background:#fff; cursor:pointer; text-decoration:none; color:#000; border-radius:6px; }
    .btn-primary{ border-color:#1e88e5; background:#1e88e5; color:#fff; }
    .small{ font-size:12px; color:#666; }
    input[type="text"], select{ padding:6px 8px; border:1px solid #ddd; border-radius:6px; }
    .code{ font-family: ui-monospace, Menlo, Consolas, monospace; }
    .badge{ padding:3px 8px; border-radius:999px; font-size:12px; border:1px solid #ddd; background:#fafafa; }
  </style>
</head>
<body>

<?php require_once __DIR__ . '/../app/views/layout/header.php'; ?>

<h3>GenDoc List</h3>
<div class="small">
  Firma: <b><?php echo esc($ctx['CDEF01_id'] ?? ''); ?></b>
  &nbsp;|&nbsp; Dönem: <b><?php echo esc($ctx['period_id'] ?? ''); ?></b>
  &nbsp;|&nbsp; Kullanıcı: <b><?php echo esc($ctx['username'] ?? ''); ?></b>
</div>

<form method="GET" class="bar">
  <input type="text" name="q" value="<?php echo esc($q); ?>" placeholder="doc_no/title/doc_id/status">
  <input type="text" name="module" value="<?php echo esc($module); ?>" placeholder="module (örn: gen)">
  <input type="text" name="doc_type" value="<?php echo esc($docType); ?>" placeholder="doc_type (örn: GENDOC01T)">
  <input type="text" name="status" value="<?php echo esc($status); ?>" placeholder="status (draft/saved/...)">
  <button class="btn btn-primary" type="submit">Getir</button>
  <a class="btn" href="/php-mongo-erp/public/gendoc_list.php">Sıfırla</a>
</form>

<table>
  <tr>
    <th style="width:170px;">Updated</th>
    <th style="width:120px;">Status</th>
    <th style="width:260px;">Doc</th>
    <th>Title</th>
    <th style="width:120px;">Ver</th>
    <th style="width:220px;">Actions</th>
  </tr>

  <?php if (empty($rows)): ?>
    <tr><td colspan="6" class="small">Kayıt bulunamadı.</td></tr>
  <?php else: ?>
    <?php foreach ($rows as $r):
      $target = $r['target'] ?? [];
      $docId = (string)($target['doc_id'] ?? '');
      $docNo = (string)($target['doc_no'] ?? '');
      $title = (string)($target['doc_title'] ?? '');
      $st = (string)($r['status'] ?? 'draft');
      $ver = (int)($r['current_version'] ?? 0);

      $updatedAt = $r['updated_at'] ?? ($r['created_at'] ?? null);
      $updatedIso = '';
      if ($updatedAt instanceof MongoDB\BSON\UTCDateTime) $updatedIso = $updatedAt->toDateTime()->format('c');
      else $updatedIso = (string)$updatedAt;

      $tk = (string)($r['target_key'] ?? '');

      $adminUrl = '/php-mongo-erp/public/gendoc_admin.php?target_key=' . rawurlencode($tk);
      $auditUrl = '/php-mongo-erp/public/audit_view.php?target_key=' . rawurlencode($tk);
    ?>
      <tr>
        <td class="small"><?php echo esc(fmt_tr($updatedIso)); ?></td>
        <td><span class="badge"><?php echo esc($st); ?></span></td>
        <td>
          <div class="code"><b><?php echo esc($docNo ?: $docId); ?></b></div>
          <div class="small code"><?php echo esc($tk); ?></div>
        </td>
        <td><?php echo esc($title ?: '-'); ?></td>
        <td><span class="code">v<?php echo (int)$ver; ?></span></td>
        <td>
          <a class="btn" href="<?php echo esc($adminUrl); ?>">Open</a>
          <a class="btn" href="<?php echo esc($auditUrl); ?>">Audit</a>
        </td>
      </tr>
    <?php endforeach; ?>
  <?php endif; ?>
</table>

</body>
</html>
