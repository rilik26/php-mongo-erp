<?php
/**
 * open_periods.php (API)
 *
 * AMAÇ:
 * - Login ekranında period select'i tek adımda doldurmak
 * - username -> UDEF01E -> CDEF01_id -> PERIOD01T(open) zinciriyle dönemleri döndürmek
 *
 * GÜVENLİK NOTU:
 * - Bu endpoint password doğrulamaz (UI kolaylığı).
 * - İstersen ileride rate-limit / captcha / veya password ile doğrulama ekleriz.
 */define('SKIP_I18N_BOOT', true);
require_once __DIR__ . '/../../core/bootstrap.php';

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/auth/SessionManager.php';
require_once __DIR__ . '/../../app/modules/period/PERIOD01Repository.php';

SessionManager::start();

header('Content-Type: application/json; charset=utf-8');

$username = $_GET['username'] ?? '';
$username = trim($username);

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

$companyId = $user['CDEF01_id'] ?? null;

if (!$companyId || strlen((string)$companyId) !== 24) {
    echo json_encode(['ok' => false, 'periods' => []]);
    exit;
}

$periods = PERIOD01Repository::listAllPeriods((string)$companyId);

echo json_encode([
    'ok'      => true,
    'periods' => $periods
]);
