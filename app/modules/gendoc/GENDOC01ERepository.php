<?php
/**
 * app/modules/gendoc/GENDOC01ERepository.php
 *
 * Header / Liste için.
 */

final class GENDOC01ERepository
{
    public static function upsertHeader(array $ctx, array $target, array $meta = []): void
    {
        $filter = [
            'context.CDEF01_id' => $ctx['CDEF01_id'] ?? null,
            'context.period_id' => $ctx['period_id'] ?? null,
            'context.facility_id' => $ctx['facility_id'] ?? null,
            'target.module' => (string)($target['module'] ?? ''),
            'target.doc_type' => (string)($target['doc_type'] ?? ''),
            'target.doc_id' => (string)($target['doc_id'] ?? ''),
        ];

        $now = new MongoDB\BSON\UTCDateTime();

        $doc = [
            '$set' => [
                'context' => [
                    'CDEF01_id' => $ctx['CDEF01_id'] ?? null,
                    'period_id' => $ctx['period_id'] ?? null,
                    'facility_id' => $ctx['facility_id'] ?? null,
                ],
                'target' => [
                    'module' => (string)($target['module'] ?? ''),
                    'doc_type' => (string)($target['doc_type'] ?? ''),
                    'doc_id' => (string)($target['doc_id'] ?? ''),
                    'doc_no' => $target['doc_no'] ?? null,
                    'doc_title' => $target['doc_title'] ?? null,
                ],
                'status' => (string)($meta['status'] ?? 'draft'),
                'updated_at' => $now,
                'updated_by' => [
                    'username' => $ctx['username'] ?? null,
                    'UDEF01_id' => $ctx['UDEF01_id'] ?? null,
                ],
            ],
            '$setOnInsert' => [
                'created_at' => $now,
                'created_by' => [
                    'username' => $ctx['username'] ?? null,
                    'UDEF01_id' => $ctx['UDEF01_id'] ?? null,
                ],
            ],
        ];

        MongoManager::collection('GENDOC01E')->updateOne($filter, $doc, ['upsert' => true]);
    }

    public static function listLatest(array $ctx, array $filter = [], int $limit = 200): array
    {
        $q = [];

        if (!empty($ctx['CDEF01_id'])) $q['context.CDEF01_id'] = $ctx['CDEF01_id'];
        if (!empty($ctx['period_id'])) $q['context.period_id'] = $ctx['period_id'];
        // facility null olabilir: filtrelemek istemiyorsan koyma
        if (array_key_exists('facility_id', $ctx) && $ctx['facility_id'] !== null) {
            $q['context.facility_id'] = $ctx['facility_id'];
        }

        foreach (['status','target.module','target.doc_type'] as $k) {
            if (isset($filter[$k]) && $filter[$k] !== '') $q[$k] = $filter[$k];
        }

        $cur = MongoManager::collection('GENDOC01E')->find($q, [
            'sort' => ['updated_at' => -1],
            'limit' => max(10, min(1000, $limit))
        ]);

        $out = [];
        foreach ($cur as $d) {
            if ($d instanceof MongoDB\Model\BSONDocument) $d = $d->getArrayCopy();
            $out[] = $d;
        }
        return $out;
    }
}
