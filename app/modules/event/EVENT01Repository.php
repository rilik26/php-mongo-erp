<?php
/**
 * app/modules/event/EVENT01Repository.php
 *
 * AMAÇ:
 * - EVENT01E timeline listesi için query API
 * - Filtre: event_code, module, doc_type, doc_id, username
 * - Sayfalama: page, limit
 */

final class EVENT01Repository
{
    /**
     * @return array{items:array,total:int,page:int,limit:int}
     */
    public static function list(array $filters = [], int $page = 1, int $limit = 50): array
    {
        $page  = max(1, (int)$page);
        $limit = max(1, min(200, (int)$limit));
        $skip  = ($page - 1) * $limit;

        $q = [];

        // --- context filters ---
        if (!empty($filters['CDEF01_id'])) {
            $q['context.CDEF01_id'] = (string)$filters['CDEF01_id'];
        }
        if (!empty($filters['period_id'])) {
            $q['context.period_id'] = (string)$filters['period_id'];
        }
        if (!empty($filters['username'])) {
            $q['context.username'] = (string)$filters['username'];
        }

        // --- event filter ---
        if (!empty($filters['event_code'])) {
            $q['event_code'] = (string)$filters['event_code'];
        }

        // --- target filters ---
        if (!empty($filters['module'])) {
            $q['target.module'] = (string)$filters['module'];
        }
        if (!empty($filters['doc_type'])) {
            $q['target.doc_type'] = (string)$filters['doc_type'];
        }
        if (!empty($filters['doc_id'])) {
            $q['target.doc_id'] = (string)$filters['doc_id'];
        }

        $coll = MongoManager::collection('EVENT01E');

        $total = $coll->countDocuments($q);

        $cursor = $coll->find($q, [
            'sort'  => ['created_at' => -1],
            'skip'  => $skip,
            'limit' => $limit,
        ]);

        $items = [];
        foreach ($cursor as $doc) {
            $items[] = self::normalize($doc);
        }

        return [
            'items' => $items,
            'total' => (int)$total,
            'page'  => $page,
            'limit' => $limit,
        ];
    }

    private static function normalize($doc): array
    {
        // MongoDB BSONDocument -> array normalize
        $arr = (array)$doc;

        $arr['_id'] = isset($arr['_id']) ? (string)$arr['_id'] : null;

        // created_at (UTCDateTime -> ISO string)
        if (isset($arr['created_at']) && $arr['created_at'] instanceof MongoDB\BSON\UTCDateTime) {
            $arr['created_at'] = $arr['created_at']->toDateTime()->format('c');
        }

        return $arr;
    }
}
