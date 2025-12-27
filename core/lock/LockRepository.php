<?php
/**
 * core/lock/LockRepository.php
 *
 * LOCK01E
 * - target_key unique
 * - active: expires_at > now
 *
 * Acquire mantığı (E11000 FIX):
 * 1) target_key ile mevcut lock'u oku
 * 2) aktif ve başkasında -> acquired=false dön
 * 3) aktif ve bende -> TTL refresh/update -> acquired=true
 * 4) yok veya expired -> expired ise delete -> insert new -> acquired=true
 */

use MongoDB\BSON\UTCDateTime;
use MongoDB\BSON\ObjectId;

final class LockRepository
{
    public static function findByTargetKey(string $targetKey): ?array
    {
        $doc = MongoManager::collection('LOCK01E')->findOne(['target_key' => $targetKey]);
        if (!$doc) return null;
        if ($doc instanceof MongoDB\Model\BSONDocument) $doc = $doc->getArrayCopy();
        return self::bsonToArray($doc);
    }

    public static function acquire(string $targetKey, array $target, array $context, int $ttlSec = 900, string $status = 'editing'): array
    {
        $nowMs = (int) floor(microtime(true) * 1000);
        $nowUtc = new UTCDateTime($nowMs);

        if ($ttlSec < 60) $ttlSec = 60;
        if ($ttlSec > 7200) $ttlSec = 7200;

        $expiresMs = $nowMs + ($ttlSec * 1000);
        $expiresUtc = new UTCDateTime($expiresMs);

        // 1) mevcut lock
        $existing = MongoManager::collection('LOCK01E')->findOne(['target_key' => $targetKey]);

        if ($existing instanceof MongoDB\Model\BSONDocument) $existing = $existing->getArrayCopy();
        $existingArr = $existing ? self::bsonToArray($existing) : null;

        // aktif mi?
        $existingActive = false;
        $existingExpMs = null;

        if ($existing && isset($existing['expires_at'])) {
            if ($existing['expires_at'] instanceof UTCDateTime) {
                $existingExpMs = (int)$existing['expires_at']->toDateTime()->format('U') * 1000;
            } else {
                $ts = strtotime((string)$existing['expires_at']);
                if ($ts !== false) $existingExpMs = $ts * 1000;
            }
            if ($existingExpMs !== null && $existingExpMs > $nowMs) {
                $existingActive = true;
            }
        }

        $mySession = (string)($context['session_id'] ?? '');
        $lockSession = (string)($existingArr['context']['session_id'] ?? '');

        // 2) aktif ve başkasında
        if ($existingActive && $lockSession !== '' && $mySession !== '' && $lockSession !== $mySession) {
            return [
                'acquired' => false,
                'lock' => $existingArr,
            ];
        }

        // 3) aktif ve bende -> refresh
        if ($existingActive && $lockSession !== '' && $mySession !== '' && $lockSession === $mySession) {
            $id = $existing['_id'] ?? null;

            MongoManager::collection('LOCK01E')->updateOne(
                ['_id' => $id],
                [
                    '$set' => [
                        'updated_at' => $nowUtc,
                        'expires_at' => $expiresUtc,
                        'status'     => $status,
                        'target'     => self::cleanNulls($target),
                        // context'i refresh etmiyoruz; istersen edebilirsin
                    ]
                ]
            );

            $fresh = self::findByTargetKey($targetKey);

            return [
                'acquired' => true,
                'lock' => $fresh,
            ];
        }

        // 4) expired ise sil
        if ($existing && !$existingActive) {
            MongoManager::collection('LOCK01E')->deleteOne(['_id' => $existing['_id']]);
        }

        // 5) insert new (unique key çakışmaz, çünkü yok/expired sildik)
        $doc = [
            'target_key' => $targetKey,
            'target'     => self::cleanNulls($target),
            'context'    => self::cleanNulls($context),
            'status'     => $status,

            'created_at' => $nowUtc,
            'updated_at' => $nowUtc,
            'locked_at'  => $nowUtc,
            'expires_at' => $expiresUtc,
        ];

        $res = MongoManager::collection('LOCK01E')->insertOne($doc);
        $newId = (string)$res->getInsertedId();

        $fresh = self::findByTargetKey($targetKey);

        return [
            'acquired' => true,
            'lock' => $fresh ?: array_merge(['_id' => $newId], self::bsonToArray($doc)),
        ];
    }

    public static function release(string $targetKey, array $context, bool $force = false): array
    {
        $existing = MongoManager::collection('LOCK01E')->findOne(['target_key' => $targetKey]);
        if (!$existing) {
            return ['released' => false, 'reason' => 'not_found'];
        }

        if ($existing instanceof MongoDB\Model\BSONDocument) $existing = $existing->getArrayCopy();
        $existingArr = self::bsonToArray($existing);

        $mySession = (string)($context['session_id'] ?? '');
        $lockSession = (string)($existingArr['context']['session_id'] ?? '');

        if (!$force && $mySession !== '' && $lockSession !== '' && $mySession !== $lockSession) {
            return ['released' => false, 'reason' => 'not_owner'];
        }

        MongoManager::collection('LOCK01E')->deleteOne(['_id' => $existing['_id']]);

        return ['released' => true];
    }

    private static function cleanNulls(array $a): array
    {
        foreach ($a as $k => $v) {
            if ($v === null) unset($a[$k]);
            if (is_array($v)) $a[$k] = self::cleanNulls($v);
        }
        return $a;
    }

    private static function bsonToArray($v)
    {
        if ($v instanceof MongoDB\Model\BSONDocument || $v instanceof MongoDB\Model\BSONArray) {
            $v = $v->getArrayCopy();
        }
        if (is_array($v)) {
            $out = [];
            foreach ($v as $k => $vv) $out[$k] = self::bsonToArray($vv);
            return $out;
        }
        if ($v instanceof UTCDateTime) return $v->toDateTime()->format('c');
        if ($v instanceof ObjectId) return (string)$v;
        return $v;
    }
}
