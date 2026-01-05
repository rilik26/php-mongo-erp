<?php
/**
 * public/api/gdef_item_save.php (FINAL)
 *
 * JSON POST:
 * { group, id?, code, name, name2?, is_active }
 *
 * ✅ Save (create/update)
 * ✅ Log + Snapshot + Event (timeline/diff için)
 */

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/auth/SessionManager.php';
require_once __DIR__ . '/../../core/base/Context.php';
require_once __DIR__ . '/../../core/base/ContextException.php';
require_once __DIR__ . '/../../core/error/api_response.php';

require_once __DIR__ . '/../../core/action/ActionLogger.php';
require_once __DIR__ . '/../../core/event/EventWriter.php';
require_once __DIR__ . '/../../core/snapshot/SnapshotWriter.php';

require_once __DIR__ . '/../../app/modules/gdef/GDEF01TRepository.php';

SessionManager::start();

try { Context::bootFromSession(); }
catch (ContextException $e) { api_err('AUTH_FAILED'); }

$ctx = Context::get();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  api_err('BAD_REQUEST', ['detail' => 'POST required']);
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) api_err('BAD_REQUEST', ['detail' => 'invalid_json']);

$group = trim((string)($data['group'] ?? ''));
if ($group === '') api_err('GDEF_VALIDATION', ['fields' => ['group'], 'detail' => 'group_required']);

$id = trim((string)($data['id'] ?? ''));
if ($id !== '' && strlen($id) !== 24) api_err('BAD_REQUEST', ['detail' => 'invalid_id']);

try {
  // normalize
  $data['code'] = trim((string)($data['code'] ?? ''));
  $data['name'] = trim((string)($data['name'] ?? ''));
  if (array_key_exists('name2', $data) && is_string($data['name2'])) {
    $data['name2'] = trim($data['name2']);
  }
  if (array_key_exists('is_active', $data)) $data['is_active'] = (bool)$data['is_active'];

  $stat = GDEF01TRepository::saveItem($group, $data, $ctx, ($id !== '' ? $id : null));

  // LOG
  $logId = ActionLogger::success('GDEF.ITEM.SAVE', [
    'source' => 'public/api/gdef_item_save.php',
    'group'  => $group,
    'item_id'=> $stat['_id'] ?? null,
    'code'   => $stat['code'] ?? null,
    'is_active' => $stat['is_active'] ?? null,
  ], $ctx);

  // SNAPSHOT (item)
  $dump = GDEF01TRepository::dumpFull((string)($stat['_id'] ?? ''));
  $snap = SnapshotWriter::capture(
    [
      'module' => 'gdef',
      'doc_type' => 'GDEF01T',
      'doc_id' => (string)($stat['_id'] ?? ''),
      'doc_no' => (string)($stat['code'] ?? ''),
      'doc_title' => (string)($stat['name'] ?? ''),
      'status' => ((bool)($stat['is_active'] ?? true)) ? 'ACTIVE' : 'PASSIVE',
    ],
    $dump,
    [
      'reason' => 'save',
      'group'  => $group,
    ]
  );

  // EVENT
  EventWriter::emit(
    'GDEF.ITEM.SAVE',
    [
      'source' => 'public/api/gdef_item_save.php',
      'summary' => [
        'title' => 'Grup satırı kaydedildi',
        'subtitle' => $group . ' / ' . ($stat['code'] ?? ''),
        'group' => $group,
        'code' => $stat['code'] ?? null,
        'is_active' => $stat['is_active'] ?? null,
      ],
    ],
    [
      'module' => 'gdef',
      'doc_type' => 'GDEF01T',
      'doc_id' => $stat['_id'] ?? null,
      'doc_no' => $stat['code'] ?? null,
      'doc_title' => $stat['name'] ?? null,
      'status' => ((bool)($stat['is_active'] ?? true)) ? 'ACTIVE' : 'PASSIVE',
    ],
    $ctx,
    [
      'log_id' => $logId,
      'snapshot_id' => $snap['snapshot_id'] ?? null,
      'prev_snapshot_id' => $snap['prev_snapshot_id'] ?? null,
    ]
  );

  api_ok([
    'id' => $stat['_id'],
    'group' => $group,
    'code' => $stat['code'],
    'snapshot_id' => $snap['snapshot_id'] ?? null,
  ]);

} catch (InvalidArgumentException $e) {
  api_err('GDEF_VALIDATION', ['detail' => $e->getMessage()]);
} catch (Throwable $e) {
  ActionLogger::fail('GDEF.ITEM.SAVE.FAIL', [
    'source' => 'public/api/gdef_item_save.php',
    'group' => $group,
    'error' => $e->getMessage(),
  ], $ctx);

  api_err('GDEF_ITEM_SAVE_FAIL', ['detail' => 'server_error']);
}
