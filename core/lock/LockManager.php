<?php
/**
 * core/lock/LockManager.php
 *
 * TargetKey = module|doc_type|doc_id|CDEF01_id|period_id|facility_id
 * - tenant parçaları context'ten gelir (yoksa "null")
 */

final class LockManager
{
    public static function acquire(array $target, int $ttlSec = 900, string $status = 'editing'): array
    {
        require_once __DIR__ . '/LockRepository.php';

        $ctx = self::resolveContext();

        $targetKey = self::targetKey(
            (string)($target['module'] ?? ''),
            (string)($target['doc_type'] ?? ''),
            (string)($target['doc_id'] ?? ''),
            $ctx
        );

        if ($targetKey === '' || ($target['module'] ?? '') === '' || ($target['doc_type'] ?? '') === '' || ($target['doc_id'] ?? '') === '') {
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

        $targetKey = self::targetKey(
            (string)($target['module'] ?? ''),
            (string)($target['doc_type'] ?? ''),
            (string)($target['doc_id'] ?? ''),
            $ctx
        );

        if ($targetKey === '' || ($target['module'] ?? '') === '' || ($target['doc_type'] ?? '') === '' || ($target['doc_id'] ?? '') === '') {
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
            try {
                $ctx = Context::get();
            } catch (Throwable $e) {
                $ctx = [];
            }
        }

        if (empty($ctx) && isset($_SESSION['context']) && is_array($_SESSION['context'])) {
            $ctx = $_SESSION['context'];
        }

        return [
            'UDEF01_id'   => $ctx['UDEF01_id'] ?? null,
            'username'    => $ctx['username'] ?? null,
            'CDEF01_id'   => $ctx['CDEF01_id'] ?? null,
            'period_id'   => $ctx['period_id'] ?? null,
            'facility_id' => $ctx['facility_id'] ?? null,
            'role'        => $ctx['role'] ?? null,
            'session_id'  => $ctx['session_id'] ?? session_id(),
            'ip'          => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ];
    }

    private static function targetKey(string $module, string $docType, string $docId, array $ctx): string
    {
        $cdef = $ctx['CDEF01_id'] ?? 'null';
        $per  = $ctx['period_id'] ?? 'null';
        $fac  = $ctx['facility_id'] ?? 'null';

        if ($module === '' || $docType === '' || $docId === '') return '';

        return $module . '|' . $docType . '|' . $docId . '|' . ($cdef ?? 'null') . '|' . ($per ?? 'null') . '|' . ($fac ?? 'null');
    }
}
