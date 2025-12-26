<?php
/**
 * core/lock/LockManager.php
 *
 * FIX:
 * - LockRepository require edildi (aksi halde endpoint "exception" döner)
 */

require_once __DIR__ . '/LockRepository.php';

final class LockManager
{
    public static function acquire(array $target, int $ttlSeconds = 900, string $status = 'editing'): array
    {
        $target = self::normalizeTarget($target);

        if (($target['module'] ?? '') === '' || ($target['doc_type'] ?? '') === '' || ($target['doc_id'] ?? '') === '') {
            return [
                'ok' => false,
                'acquired' => false,
                'error' => 'module,doc_type,doc_id_required',
                'reason' => 'bad_request',
                'message' => 'module/doc_type/doc_id zorunlu',
            ];
        }

        $ctx = self::resolveContext();
        $targetKey = self::targetKey($target, $ctx);

        $status = in_array($status, ['editing','viewing','approving'], true) ? $status : 'editing';
        if ($ttlSeconds < 60) $ttlSeconds = 60;
        if ($ttlSeconds > 7200) $ttlSeconds = 7200;

        try {
            $r = LockRepository::acquire($targetKey, $ctx, $target, $ttlSeconds, $status);

            if (($r['acquired'] ?? false) === true) {
                self::safeLog('LOCK.ACQUIRE', 'success', [
                    'target_key' => $targetKey,
                    'status' => $status,
                    'ttl' => $ttlSeconds
                ]);

                return [
                    'ok' => true,
                    'acquired' => true,
                    'target_key' => $targetKey,
                    'lock' => $r['lock'] ?? null,
                ];
            }

            self::safeLog('LOCK.ACQUIRE', 'deny', [
                'target_key' => $targetKey,
                'reason' => $r['reason'] ?? 'locked_by_other'
            ]);

            return [
                'ok' => true,
                'acquired' => false,
                'target_key' => $targetKey,
                'lock' => $r['lock'] ?? null,
                'reason' => $r['reason'] ?? 'locked_by_other',
                'message' => 'Evrak başka bir kullanıcı tarafından kilitli.',
            ];

        } catch (Throwable $e) {
            self::safeLog('LOCK.ACQUIRE', 'fail', [
                'target_key' => $targetKey,
                'exception' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'acquired' => false,
                'target_key' => $targetKey,
                'error' => 'exception',
                'reason' => 'server_error',
                'message' => $e->getMessage(),
            ];
        }
    }

    public static function release(array $target, bool $force = false): array
    {
        $target = self::normalizeTarget($target);

        if (($target['module'] ?? '') === '' || ($target['doc_type'] ?? '') === '' || ($target['doc_id'] ?? '') === '') {
            return [
                'ok' => false,
                'released' => false,
                'error' => 'module,doc_type,doc_id_required',
                'reason' => 'bad_request',
                'message' => 'module/doc_type/doc_id zorunlu',
            ];
        }

        $ctx = self::resolveContext();
        $targetKey = self::targetKey($target, $ctx);

        try {
            $r = LockRepository::release($targetKey, $ctx, $force);

            if (($r['released'] ?? false) === true) {
                self::safeLog('LOCK.RELEASE', 'success', [
                    'target_key' => $targetKey,
                    'force' => $force ? 1 : 0,
                ]);

                return [
                    'ok' => true,
                    'released' => true,
                    'target_key' => $targetKey,
                ];
            }

            self::safeLog('LOCK.RELEASE', $force ? 'info' : 'deny', [
                'target_key' => $targetKey,
                'force' => $force ? 1 : 0,
                'reason' => $r['reason'] ?? null,
            ]);

            return [
                'ok' => true,
                'released' => false,
                'target_key' => $targetKey,
                'reason' => $r['reason'] ?? ($force ? 'not_found' : 'not_owner_or_not_found'),
                'message' => $force ? 'Lock bulunamadı.' : 'Lock size ait değil (force gerekiyorsa admin kullan).',
            ];

        } catch (Throwable $e) {
            self::safeLog('LOCK.RELEASE', 'fail', [
                'target_key' => $targetKey,
                'exception' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'released' => false,
                'target_key' => $targetKey,
                'error' => 'exception',
                'reason' => 'server_error',
                'message' => $e->getMessage(),
            ];
        }
    }

    // ---- helpers ----

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

    private static function normalizeTarget(array $t): array
    {
        return [
            'module'    => isset($t['module']) ? (string)$t['module'] : '',
            'doc_type'  => isset($t['doc_type']) ? (string)$t['doc_type'] : '',
            'doc_id'    => isset($t['doc_id']) ? (string)$t['doc_id'] : '',
            'doc_no'    => isset($t['doc_no']) && $t['doc_no'] !== null ? (string)$t['doc_no'] : null,
            'doc_title' => isset($t['doc_title']) && $t['doc_title'] !== null ? (string)$t['doc_title'] : null,
        ];
    }

    private static function targetKey(array $target, array $ctx): string
    {
        $module   = $target['module'] ?: 'unknown';
        $docType  = $target['doc_type'] ?: 'unknown';
        $docId    = $target['doc_id'] ?: 'unknown';

        $cdef     = $ctx['CDEF01_id'] ?? 'null';
        $period   = $ctx['period_id'] ?? 'null';
        $facility = $ctx['facility_id'] ?? 'null';

        return $module . '|' . $docType . '|' . $docId . '|' . $cdef . '|' . $period . '|' . $facility;
    }

    private static function safeLog(string $action, string $result, array $payload = []): void
    {
        if (!class_exists('ActionLogger')) return;

        try {
            if (method_exists('ActionLogger', $result)) {
                ActionLogger::$result($action, $payload);
            } elseif (method_exists('ActionLogger', 'log')) {
                ActionLogger::log($action, $payload, [], [], $result);
            }
        } catch (Throwable $e) {
            // log bozmasın
        }
    }
}
