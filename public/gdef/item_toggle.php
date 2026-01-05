<?php
/**
 * public/gdef/item_toggle.php (FINAL)
 *
 * - items.php içindeki Pasif/Aktif formlarını işler
 * - api/gdef_item_save.php yerine doğrudan repo ile save yapar (daha basit)
 * - Snapshot + Timeline + Lock uyumu: Event/Snapshot/Action burada da üretilmeli
 */

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/auth/SessionManager.php';
require_once __DIR__ . '/../../core/base/Context.php';
require_once __DIR__ . '/../../core/base/ContextException.php';

require_once __DIR__ . '/../../core/action/ActionLogger.php';
require_once __DIR__ . '/../../core/event/EventWriter.php';
require_once __DIR__ . '/../../core/snapshot/SnapshotWriter.php';

require_once __DIR__ . '/../../app/modules/gdef/GDEF01TRepository.php';

SessionManager::start();

if (!isset($_SESSION['context']) || !is_array($_SESSION['context'])) {
  header('Location: /php-mongo-erp/public/login.php'); exit;
}
try { Context::bootFromSession(); }
catch (ContextException $e) { header('Location: /php-mongo-erp/public/login.php'); exit; }

$ctx = Context::get();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: /php-mongo-erp/public/gdef/index.php'); exit;
}

$group = trim((string)($_POST['group'] ?? ''));
$id    = trim((string)($_POST['id'] ?? ''));
$act   = (string)($_POST['is_active'] ?? '');

if ($group === '' || $id === '' || strlen($id) !== 24) {
  header('Location: /php-mongo-erp/public/gdef/index.php'); exit;
}

$isActive = ($act === '1');

try {
  // minimum update (sadece durum)
  $stat = GDEF01TRepository::save([
    '_id' => $id,
    'GDEF01E_code' => $group,
    'is_active' => $isActive,
  ], $ctx, $id);

  $logId = ActionLogger::success('GDEF.ITEM.TOGGLE', [
    'group' => $group,
    'id' => $id,
    'is_active' => $isActive,
  ], $ctx);

  $dump = GDEF01TRepository::dumpFull($id);

  $snap = SnapshotWriter::capture(
    [
      'module' => 'gdef',
      'doc_type' => 'GDEF01T',
      'doc_id' => $id,
      'doc_no' => ($dump['code'] ?? null),
    ],
    $dump,
    ['reason' => 'toggle']
  );

  EventWriter::emit(
    'GDEF.ITEM.TOGGLE',
    [
      'source' => 'public/gdef/item_toggle.php',
      'summary' => [
        'title' => 'GDEF satır durum değişti',
        'subtitle' => ($dump['code'] ?? '') . ' → ' . ($isActive ? 'ACTIVE' : 'PASSIVE'),
      ],
    ],
    [
      'module' => 'gdef',
      'doc_type' => 'GDEF01T',
      'doc_id' => $id,
      'doc_no' => ($dump['code'] ?? null),
      'doc_title' => ($dump['name'] ?? null),
      'status' => $isActive ? 'ACTIVE' : 'PASSIVE',
    ],
    $ctx,
    [
      'log_id' => $logId,
      'snapshot_id' => $snap['snapshot_id'] ?? null,
      'prev_snapshot_id' => $snap['prev_snapshot_id'] ?? null,
    ]
  );

} catch (Throwable $e) {
  // sessiz redirect
}

header('Location: /php-mongo-erp/public/gdef/items.php?group=' . rawurlencode($group));
exit;
