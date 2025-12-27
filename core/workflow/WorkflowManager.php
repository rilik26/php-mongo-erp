<?php
/**
 * core/workflow/WorkflowManager.php
 */

final class WorkflowManager
{
    public static function allowedStatuses(): array
    {
        return ['draft','editing','approving','approved','rejected','closed'];
    }

    public static function targetKey(array $target, array $ctx): string
    {
        // Snapshot targetKey formatıyla aynı
        $module  = $target['module'] ?? 'unknown';
        $docType = $target['doc_type'] ?? 'unknown';
        $docId   = $target['doc_id'] ?? 'unknown';

        $cdef     = $ctx['CDEF01_id'] ?? 'null';
        $period   = $ctx['period_id'] ?? 'null';
        $facility = $ctx['facility_id'] ?? 'null';

        return $module.'|'.$docType.'|'.$docId.'|'.$cdef.'|'.$period.'|'.$facility;
    }

    public static function set(array $target, string $status, array $extra = []): array
    {
        SessionManager::start();

        $ctx = $_SESSION['context'] ?? [];
        $status = strtolower(trim($status));
        if (!in_array($status, self::allowedStatuses(), true)) {
            return ['ok'=>false,'error'=>'invalid_status'];
        }

        $target = [
            'module'   => (string)($target['module'] ?? ''),
            'doc_type' => (string)($target['doc_type'] ?? ''),
            'doc_id'   => (string)($target['doc_id'] ?? ''),
            'doc_no'   => $target['doc_no'] ?? null,
            'doc_title'=> $target['doc_title'] ?? null,
        ];
        if ($target['module']==='' || $target['doc_type']==='' || $target['doc_id']==='') {
            return ['ok'=>false,'error'=>'module,doc_type,doc_id_required'];
        }

        $tk = self::targetKey($target, $ctx);

        $context = [
            'username'   => $ctx['username'] ?? null,
            'UDEF01_id'  => $ctx['UDEF01_id'] ?? null,
            'session_id' => $ctx['session_id'] ?? session_id(),
            'CDEF01_id'  => $ctx['CDEF01_id'] ?? null,
            'period_id'  => $ctx['period_id'] ?? null,
            'role'       => $ctx['role'] ?? null,
        ];

        $wf = WorkflowRepository::setStatus($tk, $target, $context, $status, $extra);

        // Event yaz (timeline’da görünsün)
        if (class_exists('EventWriter')) {
            EventWriter::emit(
                'WF.STATUS.CHANGED',
                [
                    'summary' => [
                        'from' => $wf['prev_status'] ?? null,
                        'to' => $status,
                    ]
                ],
                $target,
                $ctx,
                []
            );
        }

        return ['ok'=>true,'target_key'=>$tk,'workflow'=>$wf];
    }

    public static function get(array $target): array
    {
        SessionManager::start();
        $ctx = $_SESSION['context'] ?? [];

        $target = [
            'module'   => (string)($target['module'] ?? ''),
            'doc_type' => (string)($target['doc_type'] ?? ''),
            'doc_id'   => (string)($target['doc_id'] ?? ''),
        ];
        if ($target['module']==='' || $target['doc_type']==='' || $target['doc_id']==='') {
            return ['ok'=>false,'error'=>'module,doc_type,doc_id_required'];
        }

        $tk = self::targetKey($target, $ctx);
        $wf = WorkflowRepository::get($tk);

        return ['ok'=>true,'target_key'=>$tk,'workflow'=>$wf];
    }
}
