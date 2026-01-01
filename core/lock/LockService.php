<?php
/**
 * core/lock/LockService.php (FINAL)
 *
 * Koleksiyon: LOCK01E
 * - acquireOrRefresh: aynı session ise TTL yeniler, başka session ise lock bilgisini döner
 * - release: benim lockumu bırakır
 * - forceRelease: admin için
 */

final class LockService
{
    private static function nowMs(): int {
        return (int) floor(microtime(true) * 1000);
    }

    private static function ctx_min(array $ctx): array {
        return [
            'UDEF01_id'   => (string)($ctx['UDEF01_id'] ?? ''),
            'username'    => (string)($ctx['username'] ?? ''),
            'CDEF01_id'   => (string)($ctx['CDEF01_id'] ?? ''),
            'PERIOD01T_id'=> (string)($ctx['PERIOD01T_id'] ?? ($ctx['period_id'] ?? '')),
            'role'        => (string)($ctx['role'] ?? ''),
            'session_id'  => (string)($ctx['session_id'] ?? session_id()),
        ];
    }

    private static function target_key(array $target, array $ctx): string {
        // tenant + target => unique
        $c = (string)($ctx['CDEF01_id'] ?? '');
        $p = (string)($ctx['PERIOD01T_id'] ?? ($ctx['period_id'] ?? ''));
        return strtoupper(trim((string)$target['module'])) . '|' .
               strtoupper(trim((string)$target['doc_type'])) . '|' .
               trim((string)$target['doc_id']) . '|' .
               $c . '|' . $p;
    }

    public static function acquireOrRefresh(
        string $module,
        string $docType,
        string $docId,
        ?string $docNo,
        ?string $docTitle,
        array $ctx,
        int $ttlSeconds = 300,
        string $status = 'editing'
    ): array {
        $module  = strtolower(trim($module));
        $docType = strtoupper(trim($docType));
        $docId   = trim($docId);

        if ($module === '' || $docType === '' || $docId === '') {
            return ['ok'=>false,'error'=>'module/doc_type/doc_id_required'];
        }

        if (!in_array($status, ['editing','viewing','approving'], true)) $status = 'editing';
        if ($ttlSeconds < 30) $ttlSeconds = 30;
        if ($ttlSeconds > 7200) $ttlSeconds = 7200;

        $ctxMin = self::ctx_min($ctx);

        $target = [
            'module'    => $module,
            'doc_type'  => $docType,
            'doc_id'    => $docId,
            'doc_no'    => $docNo ?: null,
            'doc_title' => $docTitle ?: null,
        ];

        $key = self::target_key($target, $ctxMin);

        $nowMs = self::nowMs();
        $expiresMs = $nowMs + ($ttlSeconds * 1000);

        $col = MongoManager::collection('LOCK01E');

        // mevcut lock var mı?
        $existing = $col->findOne(['target_key' => $key]);
        if ($existing instanceof MongoDB\Model\BSONDocument) $existing = $existing->getArrayCopy();
        $existingArr = is_array($existing) ? $existing : null;

        // aktif mi?
        $isActive = false;
        if ($existingArr && isset($existingArr['expires_at']) && $existingArr['expires_at'] instanceof MongoDB\BSON\UTCDateTime) {
            $ex = (int) $existingArr['expires_at']->toDateTime()->format('U') * 1000;
            $isActive = ($ex > $nowMs);
        }

        // lock başka kullanıcıda ve aktifse -> acquired=false dön
        if ($existingArr && $isActive) {
            $exCtx = $existingArr['context'] ?? [];
            if ($exCtx instanceof MongoDB\Model\BSONDocument) $exCtx = $exCtx->getArrayCopy();
            $exSess = (string)($exCtx['session_id'] ?? '');

            if ($exSess !== '' && $exSess !== (string)$ctxMin['session_id']) {
                return [
                    'ok' => true,
                    'acquired' => false,
                    'lock' => $existingArr,
                ];
            }
        }

        // ya lock yok, ya expired, ya benim session -> upsert + ttl refresh
        $update = [
            '$set' => [
                'status'     => $status,
                'target'     => $target,
                'context'    => $ctxMin,
                'locked_at'  => new MongoDB\BSON\UTCDateTime($nowMs),
                'expires_at' => new MongoDB\BSON\UTCDateTime($expiresMs),
            ],
            '$setOnInsert' => [
                'target_key' => $key,
                'created_at' => new MongoDB\BSON\UTCDateTime($nowMs),
            ],
        ];

        $doc = $col->findOneAndUpdate(
            ['target_key' => $key],
            $update,
            ['upsert' => true, 'returnDocument' => MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER]
        );
        if ($doc instanceof MongoDB\Model\BSONDocument) $doc = $doc->getArrayCopy();

        return [
            'ok' => true,
            'acquired' => true,
            'lock' => $doc,
        ];
    }

    public static function release(string $module, string $docType, string $docId, array $ctx): array
    {
        $ctxMin = self::ctx_min($ctx);
        $target = [
            'module' => strtolower(trim($module)),
            'doc_type' => strtoupper(trim($docType)),
            'doc_id' => trim($docId),
        ];
        $key = self::target_key($target, $ctxMin);

        $col = MongoManager::collection('LOCK01E');

        // sadece benim session bırakabilsin
        $res = $col->deleteOne([
            'target_key' => $key,
            'context.session_id' => (string)$ctxMin['session_id'],
        ]);

        return ['ok'=>true,'released'=>((int)$res->getDeletedCount() > 0)];
    }

    public static function forceRelease(string $module, string $docType, string $docId, array $ctx): array
    {
        $ctxMin = self::ctx_min($ctx);
        if (($ctxMin['role'] ?? '') !== 'admin') {
            return ['ok'=>false,'error'=>'admin_required'];
        }

        $target = [
            'module' => strtolower(trim($module)),
            'doc_type' => strtoupper(trim($docType)),
            'doc_id' => trim($docId),
        ];
        $key = self::target_key($target, $ctxMin);

        $col = MongoManager::collection('LOCK01E');
        $res = $col->deleteOne(['target_key' => $key]);

        return ['ok'=>true,'released'=>((int)$res->getDeletedCount() > 0)];
    }
}
