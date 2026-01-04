<?php
/**
 * public/stok/toggle_active.php (FINAL)
 * - POST: id + to(1/0)
 * - STOK01Repository::save ile kaydeder (log/event/snapshot akışı repo içinde)
 * - Sonra back URL'e döner
 */

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/auth/SessionManager.php';
require_once __DIR__ . '/../../core/base/Context.php';
require_once __DIR__ . '/../../core/base/ContextException.php';
require_once __DIR__ . '/../../app/modules/stok/STOK01Repository.php';

SessionManager::start();

try { Context::bootFromSession(); }
catch (ContextException $e) { header('Location: /php-mongo-erp/public/login.php'); exit; }

function normalize_ctx(array $ctx): array {
  if (!isset($ctx['PERIOD01T_id']) || $ctx['PERIOD01T_id'] === '' || $ctx['PERIOD01T_id'] === null) {
    if (!empty($ctx['period_id'])) $ctx['PERIOD01T_id'] = (string)$ctx['period_id'];
  }
  if (!isset($ctx['period_id']) || $ctx['period_id'] === '' || $ctx['period_id'] === null) {
    if (!empty($ctx['PERIOD01T_id'])) $ctx['period_id'] = (string)$ctx['PERIOD01T_id'];
  }
  return $ctx;
}

function back_to(string $url): void {
  if ($url === '') $url = '/php-mongo-erp/public/stok/index.php';
  header('Location: ' . $url);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') back_to('/php-mongo-erp/public/stok/index.php');

$id = trim((string)($_POST['id'] ?? ''));
$to = trim((string)($_POST['to'] ?? ''));
$back = trim((string)($_POST['back'] ?? '/php-mongo-erp/public/stok/index.php'));

if ($id === '' || strlen($id) !== 24) back_to($back);

$ctx = Context::get();
if (!is_array($ctx)) $ctx = [];
$ctx = normalize_ctx($ctx);

// mevcut stok yükle
$doc = STOK01Repository::dumpFull($id);
if (empty($doc)) back_to($back . (strpos($back,'?')===false?'?':'&') . 'err=not_found');

$is_active = ($to === '1');

try {
  $stok_kodu = (string)($doc['stok_kodu'] ?? '');
  $stok_adi  = (string)($doc['stok_adi'] ?? '');
  $birim     = (string)($doc['birim'] ?? '');

  if ($stok_kodu === '') back_to($back . (strpos($back,'?')===false?'?':'&') . 'err=stok_kodu_required');

  STOK01Repository::save([
    'stok_kodu' => $stok_kodu,
    'stok_adi'  => $stok_adi,
    'birim'     => $birim,
    'is_active' => $is_active,
  ], $ctx, $id);

  back_to($back . (strpos($back,'?')===false?'?':'&') . 'ok=1');

} catch (Throwable $e) {
  back_to($back . (strpos($back,'?')===false?'?':'&') . 'err=toggle_fail');
}
