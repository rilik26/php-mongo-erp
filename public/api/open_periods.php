<?php
/**
 * public/api/open_periods.php (FINAL)
 *
 * username -> UDEF01E -> CDEF01_id -> PERIOD01T (tüm dönemler)
 * DÖNÜŞ: period_oid + title + is_open
 */
define('SKIP_I18N_BOOT', true);

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/auth/SessionManager.php';
require_once __DIR__ . '/../../app/modules/period/PERIOD01Repository.php';

SessionManager::start();

header('Content-Type: application/json; charset=utf-8');

$username = trim((string)($_GET['username'] ?? ''));
if ($username === '') {
    echo json_encode(['ok' => false, 'periods' => []]);
    exit;
}

$user = MongoManager::collection('UDEF01E')->findOne([
    'username' => $username,
    'active'   => true
]);

if (!$user) {
    echo json_encode(['ok' => false, 'periods' => []]);
    exit;
}

$companyId = (string)($user['CDEF01_id'] ?? '');
if ($companyId === '' || strlen($companyId) !== 24) {
    echo json_encode(['ok' => false, 'periods' => []]);
    exit;
}

// ✅ artık repository period_oid döndürüyor olmalı
$periods = PERIOD01Repository::listAllPeriods($companyId);

echo json_encode([
    'ok'      => true,
    'periods' => $periods
]);
