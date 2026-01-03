<?php
/**
 * core/lock/LockManager.php (FINAL)
 *
 * TargetKey = module|doc_type|doc_id|CDEF01_id|period_id|facility_id
 * - tenant parçaları context'ten gelir (yoksa "null")
 * - FIX: period_id için PERIOD01T_id fallback
 * - FIX: facility_id için FACILITY01_id fallback
 */

final class LockManager
{
    public static function acquire(array $target, int $ttlSec = 900, string $status = 'editing'): array
    {
        require_once __DIR__ . '/LockRepository.php';

        $ctx = self::resolveContext();

        $module  = (string)($target['module'] ?? '');
        $docType = (string)($target['doc_type'] ?? '');
        $docId   = (string)($target['doc_id'] ?? '');

        $targetKey = self::targetKey($module, $docType, $docId, $ctx);

        if ($targetKey === '' || $module === '' || $docType === '' || $docId === '') {
            return ['ok'=>false, 'error'=>'module,doc_type,doc_id_required'];
        }

        $lockRes = LockRepository::acquire($targetKey, $target, $ctx, $ttlSec, $status);

        return [
            'ok' => true,
            'acquired' => (bool)($lockRes['acquired'] ?? false),
            'target_key' => $targetKey,
            'lock' => $lockRes['lock'] ?? null,
        ];
    }

    public static function release(array $target, bool $force = false): array
    {
        require_once __DIR__ . '/LockRepository.php';

        $ctx = self::resolveContext();

        $module  = (string)($target['module'] ?? '');
        $docType = (string)($target['doc_type'] ?? '');
        $docId   = (string)($target['doc_id'] ?? '');

        $targetKey = self::targetKey($module, $docType, $docId, $ctx);

        if ($targetKey === '' || $module === '' || $docType === '' || $docId === '') {
            return ['ok'=>false, 'error'=>'module,doc_type,doc_id_required'];
        }

        $res = LockRepository::release($targetKey, $ctx, $force);

        return [
            'ok' => true,
            'released' => (bool)($res['released'] ?? false),
            'reason' => $res['reason'] ?? null,
            'target_key' => $targetKey,
        ];
    }

    private static function resolveContext(): array
    {
        $ctx = [];

        if (class_exists('Context')) {
            try { $ctx = Context::get(); }
            catch (Throwable $e) { $ctx = []; }
        }

        if (empty($ctx) && isset($_SESSION['context']) && is_array($_SESSION['context'])) {
            $ctx = $_SESSION['context'];
        }

        $cdef = $ctx['CDEF01_id'] ?? null;

        // FIX: period_id fallback (PERIOD01T_id)
        $period = $ctx['period_id'] ?? null;
        if ($period === null || $period === '') {
            $period = $ctx['PERIOD01T_id'] ?? null;
        }

        // FIX: facility_id fallback (FACILITY01_id)
        $facility = $ctx['facility_id'] ?? null;
        if ($facility === null || $facility === '') {
            $facility = $ctx['FACILITY01_id'] ?? null;
        }

        return [
            'UDEF01_id'   => $ctx['UDEF01_id'] ?? null,
            'username'    => $ctx['username'] ?? null,
            'CDEF01_id'   => $cdef,
            'period_id'   => $period,
            'facility_id' => $facility,
            'role'        => $ctx['role'] ?? null,
            'session_id'  => $ctx['session_id'] ?? session_id(),
            'ip'          => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ];
    }

    private static function targetKey(string $module, string $docType, string $docId, array $ctx): string
    {
        if ($module === '' || $docType === '' || $docId === '') return '';

        $cdef = $ctx['CDEF01_id'] ?? 'null';
        $per  = $ctx['period_id'] ?? 'null';
        $fac  = $ctx['facility_id'] ?? 'null';

        $cdef = ($cdef === null || $cdef === '') ? 'null' : (string)$cdef;
        $per  = ($per === null || $per === '') ? 'null' : (string)$per;
        $fac  = ($fac === null || $fac === '') ? 'null' : (string)$fac;

        return $module . '|' . $docType . '|' . $docId . '|' . $cdef . '|' . $per . '|' . $fac;
    }
}
