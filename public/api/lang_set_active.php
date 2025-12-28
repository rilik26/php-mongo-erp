<?php
/**
 * public/api/lang_set_active.php
 *
 * POST:
 *  lang=tr
 *  active=1|0
 */

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/auth/SessionManager.php';
require_once __DIR__ . '/../../core/base/Context.php';
require_once __DIR__ . '/../../core/base/ContextException.php';
require_once __DIR__ . '/../../core/auth/permission_helpers.php';

require_once __DIR__ . '/../../app/modules/lang/LANG01ERepository.php';

header('Content-Type: application/json; charset=utf-8');

function j($a, int $code=200){ http_response_code($code); echo json_encode($a, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }

SessionManager::start();

if (!isset($_SESSION['context']) || !is_array($_SESSION['context'])) j(['ok'=>false,'error'=>'unauthorized'], 401);
try { Context::bootFromSession(); } catch(Throwable $e) { j(['ok'=>false,'error'=>'unauthorized'], 401); }

require_perm('lang.manage');

$lang = strtolower(trim($_POST['lang'] ?? ''));
$active = trim($_POST['active'] ?? '');

if ($lang === '' || ($active !== '0' && $active !== '1')) {
    j(['ok'=>false,'error'=>'lang_active_required'], 400);
}

$res = LANG01ERepository::setActive($lang, $active === '1');
j($res);
