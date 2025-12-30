<?php
/**
 * core/webhook/WebhookRepository.php (FINAL)
 *
 * WEBHOOK01E örnek şema:
 * {
 *   _id, active:true,
 *   event_key:"SORD.SAVE" | "SORD.SNAPSHOT" | "SORD.STATE" ...
 *   url:"https://example.com/hook",
 *   secret:"optional",
 *   created_at, updated_at
 * }
 */
final class WebhookRepository
{
    public static function listActiveByEvent(string $eventKey): array
    {
        $cur = MongoManager::collection('WEBHOOK01E')->find(
            ['active' => true, 'event_key' => $eventKey],
            ['sort' => ['created_at' => -1]]
        );

        $out = [];
        foreach ($cur as $d) {
            if ($d instanceof MongoDB\Model\BSONDocument) $d = $d->getArrayCopy();
            if (!is_array($d)) continue;
            $url = trim((string)($d['url'] ?? ''));
            if ($url === '') continue;
            $out[] = $d;
        }
        return $out;
    }
}
