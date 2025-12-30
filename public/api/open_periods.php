<?php
/**
 * open_periods.php (API) (FINAL)
 *
 * - username -> UDEF01E -> CDEF01_id -> PERIOD01T(listAllPeriods)
 */

define('SKIP_I18N_BOOT', true);
require_once __DIR__ . '/../../core/bootstrap.php';

require_once __DIR__ . '/../../core/auth/SessionManager.php';
require_once __DIR__ . '/../../app/modules/period/PERIOD01Repository.php';

SessionManager::start();

header('Content-Type: application/json; charset=utf-8');

$username = trim((string)($_GET['username'] ?? ''));
if ($username === '') {
    echo json_encode(['ok' => false, 'periods' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = MongoManager::collection('UDEF01E')->findOne([
    'username' => $username,
    'active'   => true
]);

if (!$user) {
    echo json_encode(['ok' => false, 'periods' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$companyId = (string)($user['CDEF01_id'] ?? '');
if ($companyId === '' || strlen($companyId) !== 24) {
    echo json_encode(['ok' => false, 'periods' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

// ✅ repo artık string/ObjectId uyumlu
$periods = PERIOD01Repository::listAllPeriods($companyId);

echo json_encode([
    'ok'      => true,
    'periods' => $periods
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
