<?php
/**
 * core/workflow/WorkflowRepository.php
 *
 * WF01E (V1) - Evrak workflow state
 * _id: target_key
 * status: draft|editing|approving|approved|rejected|closed
 */

use MongoDB\BSON\UTCDateTime;

final class WorkflowRepository
{
    public static function get(string $targetKey): ?array
    {
        $doc = MongoManager::collection('WF01E')->findOne(['_id' => $targetKey]);
        if (!$doc) return null;
        if ($doc instanceof MongoDB\Model\BSONDocument) $doc = $doc->getArrayCopy();
        return self::bsonToArray($doc);
    }

    public static function setStatus(string $targetKey, array $target, array $context, string $status, array $extra = []): array
    {
        $nowMs = (int) floor(microtime(true) * 1000);

        $doc = MongoManager::collection('WF01E')->findOneAndUpdate(
            ['_id' => $targetKey],
            [
                '$set' => array_merge([
                    '_id' => $targetKey,
                    'target' => $target,
                    'status' => $status,
                    'updated_at' => new UTCDateTime($nowMs),
                    'updated_by' => [
                        'username' => $context['username'] ?? null,
                        'UDEF01_id' => $context['UDEF01_id'] ?? null,
                        'session_id' => $context['session_id'] ?? null,
                    ],
                ], $extra),
                '$setOnInsert' => [
                    'created_at' => new UTCDateTime($nowMs),
                ]
            ],
            [
                'upsert' => true,
                'returnDocument' => MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER
            ]
        );

        if ($doc instanceof MongoDB\Model\BSONDocument) $doc = $doc->getArrayCopy();
        return self::bsonToArray($doc);
    }

    private static function bsonToArray($v)
    {
        if ($v instanceof MongoDB\Model\BSONDocument || $v instanceof MongoDB\Model\BSONArray) $v = $v->getArrayCopy();
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
