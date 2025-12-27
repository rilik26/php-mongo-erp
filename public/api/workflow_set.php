<?php
/**
 * public/api/workflow_set.php
 * POST/GET:
 *  module, doc_type, doc_id, status
 *  (optional) doc_no, doc_title
 */

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/auth/SessionManager.php';
require_once __DIR__ . '/../../core/workflow/WorkflowRepository.php';
require_once __DIR__ . '/../../core/workflow/WorkflowManager.php';

header('Content-Type: application/json; charset=utf-8');

function j($a, int $code = 200): void { http_response_code($code); echo json_encode($a, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }

SessionManager::start();

$module  = trim($_REQUEST['module'] ?? '');
$docType = trim($_REQUEST['doc_type'] ?? '');
$docId   = trim($_REQUEST['doc_id'] ?? '');
$status  = trim($_REQUEST['status'] ?? '');

$docNo    = trim($_REQUEST['doc_no'] ?? '');
$docTitle = trim($_REQUEST['doc_title'] ?? '');

if ($module==='' || $docType==='' || $docId==='' || $status==='') {
    j(['ok'=>false,'error'=>'module,doc_type,doc_id,status_required'], 400);
}

$res = WorkflowManager::set([
    'module'=>$module,'doc_type'=>$docType,'doc_id'=>$docId,
    'doc_no'=>$docNo ?: null,'doc_title'=>$docTitle ?: null,
], $status);

j($res, $res['ok'] ? 200 : 400);
