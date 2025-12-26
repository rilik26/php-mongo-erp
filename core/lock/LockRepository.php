<?php
/**
 * core/lock/LockRepository.php
 *
 * LOCK01E (V1)
 * - target_key unique
 * - atomic acquire (findOneAndUpdate + upsert)
 * - race-safe: E11000 duplicate key yakalanır -> mevcut lock read -> acquired:false döner
 */

use MongoDB\BSON\UTCDateTime;
use MongoDB\BSON\Regex;
use MongoDB\Operation\FindOneAndUpdate;

final class LockRepository
{
    /**
     * Acquire lock (atomic)
     *
     * Kural:
     * - lock yoksa -> acquire
     * - lock expired ise -> acquire
     * - lock aynı session ise -> refresh/renew (acquired true)
     * - lock başka session ve aktif ise -> acquired false
     *
     * @return array ['acquired'=>bool,'lock'=>array|null,'reason'=>string|null]
     */
    public static function acquire(string $targetKey, array $context, array $target, int $ttlSeconds, string $status = 'editing'): array
    {
        $nowMs = (int) floor(microtime(true) * 1000);
        $nowUtc = new UTCDateTime($nowMs);
        $expUtc = new UTCDateTime($nowMs + ($ttlSeconds * 1000));

        $sessionId = (string)($context['session_id'] ?? '');

        // Bu filter şunu sağlar:
        // - Eğer doküman varsa ancak expired ise match olur
        // - Eğer doküman varsa ve aynı session ise match olur (refresh)
        // - Eğer doküman varsa ve başka session + aktif ise match olmaz -> upsert insert dener -> E11000 -> yakalayıp return acquired:false
        $filter = [
            'target_key' => $targetKey,
            '$or' => [
                ['expires_at' => ['$lte' => $nowUtc]],
                ['context.session_id' => $sessionId],
            ],
        ];

        $update = [
            '$set' => [
                'context'     => $context,
                'target'      => $target,
                'status'      => $status,
                'locked_at'   => $nowUtc,
                'expires_at'  => $expUtc,
                'updated_at'  => $nowUtc,
            ],
            '$setOnInsert' => [
                'target_key'  => $targetKey,
                'created_at'  => $nowUtc,
            ],
        ];

        $opts = [
            'upsert' => true,
            'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
        ];

        try {
            $doc = MongoManager::collection('LOCK01E')->findOneAndUpdate($filter, $update, $opts);

            $lockArr = self::bsonToArray($doc);
            return [
                'acquired' => true,
                'lock'     => $lockArr,
                'reason'   => null,
            ];

        } catch (MongoDB\Driver\Exception\CommandException $e) {
            // E11000 duplicate key -> başka session aktif lock tutuyor (upsert insert çakıştı)
            if (strpos($e->getMessage(), 'E11000') !== false) {
                $existing = MongoManager::collection('LOCK01E')->findOne(['target_key' => $targetKey]);
                return [
                    'acquired' => false,
                    'lock'     => self::bsonToArray($existing),
                    'reason'   => 'locked_by_other',
                ];
            }
            throw $e;

        } catch (MongoDB\Driver\Exception\BulkWriteException $e) {
            // bazı driver sürümlerinde duplicate burada düşebilir
            if (strpos($e->getMessage(), 'E11000') !== false) {
                $existing = MongoManager::collection('LOCK01E')->findOne(['target_key' => $targetKey]);
                return [
                    'acquired' => false,
                    'lock'     => self::bsonToArray($existing),
                    'reason'   => 'locked_by_other',
                ];
            }
            throw $e;
        }
    }

    /**
     * Release lock
     * - force=false => sadece aynı session silebilir
     * - force=true  => target_key bazlı siler
     *
     * @return array ['released'=>bool,'reason'=>string|null]
     */
    public static function release(string $targetKey, array $context, bool $force = false): array
    {
        $sessionId = (string)($context['session_id'] ?? '');

        $filter = ['target_key' => $targetKey];

        if (!$force) {
            $filter['context.session_id'] = $sessionId;
        }

        $res = MongoManager::collection('LOCK01E')->deleteOne($filter);

        if (($res->getDeletedCount() ?? 0) > 0) {
            return ['released' => true, 'reason' => null];
        }

        return [
            'released' => false,
            'reason' => $force ? 'not_found' : 'not_owner_or_not_found',
        ];
    }

    /**
     * Find one lock by target_key
     */
    public static function findByTargetKey(string $targetKey): ?array
    {
        $doc = MongoManager::collection('LOCK01E')->findOne(['target_key' => $targetKey]);
        $a = self::bsonToArray($doc);
        return $a ?: null;
    }

    /**
     * List locks (simple)
     */
    public static function list(array $filter = [], array $options = []): array
    {
        $cur = MongoManager::collection('LOCK01E')->find($filter, $options);
        $out = [];
        foreach ($cur as $d) $out[] = self::bsonToArray($d);
        return $out;
    }

    /**
     * helper: BSON -> array (minimal)
     */
    private static function bsonToArray($v)
    {
        if ($v === null) return null;

        if ($v instanceof MongoDB\Model\BSONDocument || $v instanceof MongoDB\Model\BSONArray) {
            $v = $v->getArrayCopy();
        }

        if (is_array($v)) {
            $out = [];
            foreach ($v as $k => $vv) $out[$k] = self::bsonToArray($vv);
            return $out;
        }

        if ($v instanceof MongoDB\BSON\UTCDateTime) return $v->toDateTime()->format('c');
        if ($v instanceof MongoDB\BSON\ObjectId) return (string)$v;

        return $v;
    }
}
