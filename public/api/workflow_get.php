<?php
/**
 * public/api/workflow_get.php
 * GET:
 *  module, doc_type, doc_id
 */

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/auth/SessionManager.php';
require_once __DIR__ . '/../../core/workflow/WorkflowRepository.php';
require_once __DIR__ . '/../../core/workflow/WorkflowManager.php';

header('Content-Type: application/json; charset=utf-8');

function j($a, int $code = 200): void { http_response_code($code); echo json_encode($a, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }

SessionManager::start();

$module  = trim($_GET['module'] ?? '');
$docType = trim($_GET['doc_type'] ?? '');
$docId   = trim($_GET['doc_id'] ?? '');

if ($module==='' || $docType==='' || $docId==='') {
    j(['ok'=>false,'error'=>'module,doc_type,doc_id_required'], 400);
}

$res = WorkflowManager::get(['module'=>$module,'doc_type'=>$docType,'doc_id'=>$docId]);
j($res);
