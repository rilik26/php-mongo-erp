<?php
/**
 * public/api/stok_save.php (FINAL)
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
require_once __DIR__ . '/../../app/modules/stok/STOK01Repository.php';

SessionManager::start();

try { Context::bootFromSession(); }
catch (ContextException $e) { api_err('AUTH_FAILED'); }

$ctx = Context::get();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_err('BAD_REQUEST', ['detail' => 'POST required']);

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) api_err('BAD_REQUEST', ['detail' => 'invalid_json']);

$id = trim((string)($data['STOK01_id'] ?? $data['_id'] ?? $data['id'] ?? ''));
if ($id !== '' && strlen($id) !== 24) api_err('BAD_REQUEST', ['detail' => 'invalid_id']);

try {
  $code = trim((string)($data['code'] ?? ''));
  if ($code === '') api_err('STOK_VALIDATION', ['fields' => ['code'], 'detail' => 'code_required']);

  $unitCode = trim((string)($data['GDEF01_unit_code'] ?? ''));
  if ($unitCode === '') api_err('STOK_VALIDATION', ['fields' => ['GDEF01_unit_code'], 'detail' => 'unit_required']);

  // global unit doğrulama
  $unitRow = GDEF01TRepository::findItem('unit', $unitCode);
  if (!$unitRow || empty($unitRow['is_active'])) {
    api_err('STOK_VALIDATION', ['fields' => ['GDEF01_unit_code'], 'detail' => 'unit_invalid']);
  }

  // unit text'i dokümana yaz (code - name)
  $unitText = trim((string)($unitRow['name'] ?? ''));
  $data['unit'] = ($unitText !== '') ? ($unitCode . ' - ' . $unitText) : $unitCode;

  if (array_key_exists('is_active', $data)) $data['is_active'] = (bool)$data['is_active'];

  $stat = STOK01Repository::save($data, $ctx, ($id !== '' ? $id : null));

  $logId = ActionLogger::success('STOK.SAVE', [
    'source' => 'public/api/stok_save.php',
    'STOK01_id' => $stat['STOK01_id'] ?? null,
    'code' => $stat['code'] ?? null,
    'is_active' => $stat['is_active'] ?? null,
    'version' => $stat['version'] ?? null,
  ], $ctx);

  $dump = STOK01Repository::dumpFull((string)$stat['STOK01_id']);

  $snap = SnapshotWriter::capture(
    [
      'module' => 'stok',
      'doc_type' => 'STOK01E',
      'doc_id' => $stat['STOK01_id'],
      'doc_no' => $stat['code'] ?? null,
      'doc_title' => $stat['name'] ?? null,
      'doc_status' => ($stat['is_active'] ?? true) ? 'ACTIVE' : 'PASSIVE',
    ],
    $dump,
    [
      'reason' => 'save',
      'version' => $stat['version'] ?? null,
    ],
    $ctx
  );

  EventWriter::emit(
    'STOK.SAVE',
    [
      'source' => 'public/api/stok_save.php',
      'summary' => [
        'doc_no' => $stat['code'] ?? null,
        'title' => $stat['name'] ?? null,
        'status' => ($stat['is_active'] ?? true) ? 'ACTIVE' : 'PASSIVE',
        'version' => $stat['version'] ?? null,
      ],
    ],
    [
      'module' => 'stok',
      'doc_type' => 'STOK01E',
      'doc_id' => $stat['STOK01_id'] ?? null,
      'doc_no' => $stat['code'] ?? null,
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

  api_ok([
    'stok_id' => $stat['STOK01_id'],
    'code' => $stat['code'],
    'snapshot_id' => $snap['snapshot_id'] ?? null,
  ]);

} catch (InvalidArgumentException $e) {
  $msg = $e->getMessage();

  // ✅ kullanıcıya okunur hata
  if ($msg === 'code_not_unique') {
    api_err('STOK_VALIDATION', [
      'fields' => ['code'],
      'detail' => 'code_not_unique',
      'msg' => 'Bu stok kodu zaten var. Kaydedilmedi.',
    ]);
  }

  api_err('STOK_VALIDATION', [
    'detail' => $msg,
    'msg' => 'Eksik/Geçersiz bilgi. Kaydedilmedi.',
  ]);

} catch (Throwable $e) {
  ActionLogger::fail('STOK.SAVE.FAIL', [
    'source' => 'public/api/stok_save.php',
    'error' => $e->getMessage(),
  ], $ctx);

  api_err('STOK_SAVE_FAIL', [
    'detail' => 'server_error',
    'msg' => 'Kaydetme sırasında hata oluştu. Kaydedilmedi.',
  ]);
}
