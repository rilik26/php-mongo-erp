<?php
/**
 * core/event/EventService.php (FINAL)
 *
 * - Evrak olaylarını standart şekilde yazar
 * - Webhook kuyruğuna ekler
 */

final class EventService
{
    public static function emit(string $eventName, array $payload, array $ctx): string
    {
        $now = new MongoDB\BSON\UTCDateTime((int)floor(microtime(true) * 1000));

        $doc = [
            'event'       => $eventName,
            'CDEF01_id'   => (string)($ctx['CDEF01_id'] ?? ''),
            'PERIOD01T_id'=> (string)($ctx['PERIOD01T_id'] ?? ''),
            'UDEF01_id'   => (string)($ctx['UDEF01_id'] ?? ''),
            'username'    => (string)($ctx['username'] ?? ''),

            // evrak kimliği
            'entity'      => (string)($payload['entity'] ?? ''),
            'entity_id'   => (string)($payload['entity_id'] ?? ''),
            'evrakno'     => (string)($payload['evrakno'] ?? ''),

            // değişiklik içeriği
            'payload'     => $payload,

            // meta
            'created_at'  => $now,
            'ip'          => $ctx['ip'] ?? null,
            'user_agent'  => $ctx['user_agent'] ?? null,
        ];

        $res = MongoManager::collection('EVENT01E')->insertOne($doc);
        $eventId = (string)$res->getInsertedId();

        // webhook kuyruğu
        self::enqueueWebhook($eventName, $doc);

        return $eventId;
    }

    private static function enqueueWebhook(string $eventName, array $eventDoc): void
    {
        $now = new MongoDB\BSON\UTCDateTime((int)floor(microtime(true) * 1000));

        $job = [
            'event'       => $eventName,
            'event_id'    => (string)($eventDoc['_id'] ?? ''),
            'target_url'  => null, // Phase-1: sonra firma bazlı hedef URL koyacağız
            'status'      => 'PENDING', // PENDING | SENT | FAIL
            'retry_count' => 0,
            'last_error'  => null,
            'created_at'  => $now,
        ];

        MongoManager::collection('WEBHOOK01Q')->insertOne($job);
    }
}
