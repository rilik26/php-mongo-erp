<?php
/**
 * public/api/gdef_item_toggle.php (FINAL)
 *
 * FORM POST:
 * - group
 * - id
 * - to (1=active, 0=passive)
 *
 * ✅ çalışır (redirect geri items.php)
 * ✅ Log + Snapshot + Event (timeline/diff için)
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
catch (ContextException $e) {
  header('Location: /php-mongo-erp/public/login.php'); exit;
}

$ctx = Context::get();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: /php-mongo-erp/public/gdef/index.php'); exit;
}

$group = trim((string)($_POST['group'] ?? ''));
$id    = trim((string)($_POST['id'] ?? ''));
$to    = trim((string)($_POST['to'] ?? ''));

if ($group === '' || $id === '' || strlen($id) !== 24) {
  header('Location: /php-mongo-erp/public/gdef/index.php'); exit;
}

$isActive = ($to === '1');

try {
  $stat = GDEF01TRepository::setActive($id, $isActive, $ctx);

  // LOG
  $logId = ActionLogger::success('GDEF.ITEM.TOGGLE', [
    'source' => 'public/api/gdef_item_toggle.php',
    'group' => $group,
    'item_id' => $id,
    'to' => $isActive ? 'ACTIVE' : 'PASSIVE',
  ], $ctx);

  // SNAPSHOT
  $dump = GDEF01TRepository::dumpFull($id);
  $snap = SnapshotWriter::capture(
    [
      'module' => 'gdef',
      'doc_type' => 'GDEF01T',
      'doc_id' => $id,
      'doc_no' => (string)($stat['code'] ?? ''),
      'doc_title' => (string)($stat['name'] ?? ''),
      'status' => $isActive ? 'ACTIVE' : 'PASSIVE',
    ],
    $dump,
    [
      'reason' => 'toggle',
      'group' => $group,
    ]
  );

  // EVENT
  EventWriter::emit(
    'GDEF.ITEM.TOGGLE',
    [
      'source' => 'public/api/gdef_item_toggle.php',
      'summary' => [
        'title' => 'Grup satırı durum değişti',
        'subtitle' => $group . ' / ' . ((string)($stat['code'] ?? '')),
        'group' => $group,
        'code' => $stat['code'] ?? null,
        'is_active' => $isActive,
      ],
    ],
    [
      'module' => 'gdef',
      'doc_type' => 'GDEF01T',
      'doc_id' => $id,
      'doc_no' => $stat['code'] ?? null,
      'doc_title' => $stat['name'] ?? null,
      'status' => $isActive ? 'ACTIVE' : 'PASSIVE',
    ],
    $ctx,
    [
      'log_id' => $logId,
      'snapshot_id' => $snap['snapshot_id'] ?? null,
      'prev_snapshot_id' => $snap['prev_snapshot_id'] ?? null,
    ]
  );

  header('Location: /php-mongo-erp/public/gdef/items.php?group=' . rawurlencode($group) . '&msg=' . rawurlencode('Güncellendi'));
  exit;

} catch (Throwable $e) {
  // hata olursa da geri dön
  header('Location: /php-mongo-erp/public/gdef/items.php?group=' . rawurlencode($group) . '&msg=' . rawurlencode('Hata: ' . $e->getMessage()));
  exit;
}
