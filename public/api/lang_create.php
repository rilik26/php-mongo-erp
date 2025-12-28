<?php
/**
 * public/api/lang_create.php
 *
 * POST:
 *  lang=de
 *  name=Deutsch (optional)
 *  active=1|0 (optional; default 1)
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
$name = trim($_POST['name'] ?? '');
$active = trim($_POST['active'] ?? '1');

if ($lang === '') j(['ok'=>false,'error'=>'lang_required'], 400);
if ($active !== '0' && $active !== '1') $active = '1';

$res = LANG01ERepository::createLang($lang, $name !== '' ? $name : null, $active === '1');
j($res, $res['ok'] ? 200 : 400);
