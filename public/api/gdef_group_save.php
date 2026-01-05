<?php
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/auth/SessionManager.php';
require_once __DIR__ . '/../../core/base/Context.php';
require_once __DIR__ . '/../../core/base/ContextException.php';
require_once __DIR__ . '/../../core/error/api_response.php';

require_once __DIR__ . '/../../core/action/ActionLogger.php';
require_once __DIR__ . '/../../core/event/EventWriter.php';
require_once __DIR__ . '/../../core/snapshot/SnapshotWriter.php';

require_once __DIR__ . '/../../app/modules/gdef/GDEF01ERepository.php';

SessionManager::start();
try { Context::bootFromSession(); } catch (ContextException $e) { api_err('AUTH_FAILED'); }
$ctx = Context::get();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_err('BAD_REQUEST', ['detail'=>'POST required']);

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) api_err('BAD_REQUEST', ['detail'=>'invalid_json']);

$id = trim((string)($data['GDEF01E_id'] ?? $data['_id'] ?? $data['id'] ?? ''));
if ($id !== '' && strlen($id) !== 24) api_err('BAD_REQUEST', ['detail'=>'invalid_id']);

try {
  $stat = GDEF01ERepository::save($data, $ctx, ($id !== '' ? $id : null));

  $logId = ActionLogger::success('GDEF.GROUP.SAVE', [
    'source' => 'public/api/gdef_group_save.php',
    'GDEF01E_id' => $stat['GDEF01E_id'] ?? null,
    'kod' => $stat['kod'] ?? null,
    'is_active' => $stat['is_active'] ?? null,
    'version' => $stat['version'] ?? null,
  ], $ctx);

  $dump = GDEF01ERepository::dumpFull((string)$stat['GDEF01E_id']);
  $snap = SnapshotWriter::capture(
    [
      'module' => 'gdef',
      'doc_type' => 'GDEF01E',
      'doc_id' => $stat['GDEF01E_id'],
      'doc_no' => $stat['kod'] ?? null,
      'doc_title' => $stat['name'] ?? null,
      'status' => ($stat['is_active'] ?? true) ? 'ACTIVE' : 'PASSIVE',
    ],
    $dump,
    ['reason'=>'save','version'=>$stat['version'] ?? null]
  );

  EventWriter::emit(
    'GDEF.GROUP.SAVE',
    [
      'source' => 'public/api/gdef_group_save.php',
      'summary' => [
        'title' => 'Grup Kaydet',
        'subtitle' => ($stat['kod'] ?? '-') . ' — ' . ($stat['name'] ?? '-'),
        'doc_no' => $stat['kod'] ?? null,
        'status' => ($stat['is_active'] ?? true) ? 'ACTIVE' : 'PASSIVE',
        'version' => $stat['version'] ?? null,
      ],
    ],
    [
      'module' => 'gdef',
      'doc_type' => 'GDEF01E',
      'doc_id' => $stat['GDEF01E_id'] ?? null,
      'doc_no' => $stat['kod'] ?? null,
      'doc_title' => $stat['name'] ?? null,
      'status' => ($stat['is_active'] ?? true) ? 'ACTIVE' : 'PASSIVE',
    ],
    $ctx,
    [
      'log_id' => $logId,
      'snapshot_id' => $snap['snapshot_id'] ?? null,
      'prev_snapshot_id' => $snap['prev_snapshot_id'] ?? null,
    ]
  );

  api_ok(['id'=>$stat['GDEF01E_id'], 'kod'=>$stat['kod']]);

} catch (InvalidArgumentException $e) {
  $m = $e->getMessage();
  if ($m === 'kod_not_unique') {
    api_err('GDEF_VALIDATION', [
      'msg' => 'Bu grup kodu zaten var. Kaydedilmedi.',
      'fields' => ['kod'],
      'detail' => 'kod_not_unique'
    ]);
  }
  api_err('GDEF_VALIDATION', ['detail'=>$m]);
} catch (Throwable $e) {
  ActionLogger::fail('GDEF.GROUP.SAVE.FAIL', ['error'=>$e->getMessage()], $ctx);
  api_err('SERVER_ERROR', ['msg'=>'Kaydetme sırasında hata oluştu. Kaydedilmedi.']);
}
