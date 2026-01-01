<?php
/**
 * app/modules/salesorder/SORDRepository.php (FINAL)
 *
 * Koleksiyonlar:
 * - SORD01E   : header + lines (embedded)
 * - SEQ01E    : doc bazlı counter (SORD01E için evrakno)
 * - SNAP01E   : snapshot store (target_key + version unique)
 * - EVENT01E  : event store (LOG/SNAPSHOT/DIFF refs)
 */

require_once __DIR__ . '/../../../core/action/ActionLogger.php';

final class SORDRepository
{
    private static function nowUtc(): MongoDB\BSON\UTCDateTime {
        return new MongoDB\BSON\UTCDateTime((int)floor(microtime(true) * 1000));
    }

    private static function oid(string $id): MongoDB\BSON\ObjectId {
        return new MongoDB\BSON\ObjectId($id);
    }

    /**
     * SNAP target_key (tenant scoped) — senin sistemdeki format ile birebir:
     * module|DOC_TYPE|DOC_ID|CDEF01_id|period_id|facility_id
     * facility yoksa literal "null"
     */
    private static function snapTargetKey(string $module, string $docType, string $docId, array $ctx): string
    {
        $module  = strtolower(trim($module));
        $docType = strtoupper(trim($docType));
        $docId   = trim($docId);

        $cdef = (string)($ctx['CDEF01_id'] ?? '');

        // Örnekte context.period_id var (GLOBAL gibi). Salesorder tarafında period_id yoksa PERIOD01T_id kullan.
        $periodId = (string)($ctx['period_id'] ?? ($ctx['PERIOD01T_id'] ?? ($ctx['period_id'] ?? '')));

        // Örnekte facility_id null => target_key'de "null"
        $facility = $ctx['facility_id'] ?? ($ctx['FACILITY01_id'] ?? null);
        $facilityStr = ($facility === null || $facility === '') ? 'null' : (string)$facility;

        return $module . '|' . $docType . '|' . $docId . '|' . $cdef . '|' . $periodId . '|' . $facilityStr;
    }

    /** Evrakno sadece SORD01E için: 1,2,3... (tenant scoped) */
    public static function nextEvrakNo(array $ctx): int
    {
        $cdef   = (string)($ctx['CDEF01_id'] ?? '');
        $period = (string)($ctx['PERIOD01T_id'] ?? ($ctx['period_id'] ?? ''));

        $key = [
            'doc_type'     => 'SORD01E',
            'CDEF01_id'    => $cdef,
            'PERIOD01T_id' => $period,
        ];

        $res = MongoManager::collection('SEQ01E')->findOneAndUpdate(
            $key,
            [
                '$inc' => ['seq' => 1],
                '$setOnInsert' => ['created_at' => self::nowUtc()],
                '$set' => ['updated_at' => self::nowUtc()],
            ],
            [
                'upsert' => true,
                'returnDocument' => MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
            ]
        );

        if ($res instanceof MongoDB\Model\BSONDocument) $res = $res->getArrayCopy();
        return (int)($res['seq'] ?? 1);
    }

    /** Edit ekranı için full model */
    public static function dumpFull(string $id): array
    {
        $doc = MongoManager::collection('SORD01E')->findOne(['_id' => self::oid($id)]);
        if (!$doc) return [];
        if ($doc instanceof MongoDB\Model\BSONDocument) $doc = $doc->getArrayCopy();

        return [
            'header' => (array)($doc['header'] ?? []),
            'lines'  => (array)($doc['lines'] ?? []),
            'version'=> (int)($doc['version'] ?? 1),
        ];
    }

    public static function listByContext(array $ctx, int $limit = 200): array
    {
        $cdef   = (string)($ctx['CDEF01_id'] ?? '');
        $period = (string)($ctx['PERIOD01T_id'] ?? ($ctx['period_id'] ?? ''));

        $cur = MongoManager::collection('SORD01E')->find(
            ['CDEF01_id' => $cdef, 'PERIOD01T_id' => $period],
            ['sort' => ['updated_at' => -1], 'limit' => $limit]
        );

        $out = [];
        foreach ($cur as $r) {
            if ($r instanceof MongoDB\Model\BSONDocument) $r = $r->getArrayCopy();
            $out[] = [
                '_id'      => (string)$r['_id'],
                'evrakno'  => (string)($r['header']['evrakno'] ?? ''),
                'customer' => (string)($r['header']['customer'] ?? ''),
                'status'   => (string)($r['header']['status'] ?? ''),
                'version'  => (int)($r['version'] ?? 1),
            ];
        }
        return $out;
    }

    /**
     * SAVE
     * - new => evrakno üretir (refresh’te değil, sadece POST’ta)
     * - version++ (her save)
     * - snapshot (target_key + version unique) + prev_snapshot_id
     * - EVENT01E: SORD.SAVE
     */
    public static function save(array $header, array $lines, array $ctx, ?string $id = null): array
    {
        $now = self::nowUtc();

        $cdef   = (string)($ctx['CDEF01_id'] ?? '');
        $period = (string)($ctx['PERIOD01T_id'] ?? ($ctx['period_id'] ?? ''));
        $user   = (string)($ctx['username'] ?? '');

        $isUpdate = ($id && strlen($id) === 24);

        $existing = null;
        if ($isUpdate) {
            $existing = MongoManager::collection('SORD01E')->findOne(['_id' => self::oid($id)]);
            if ($existing instanceof MongoDB\Model\BSONDocument) $existing = $existing->getArrayCopy();
            if (!$existing) $isUpdate = false;
        }

        // evrakno: update ise koru; yeni ise üret
        $evrakno = trim((string)($header['evrakno'] ?? ''));
        if ($isUpdate) {
            $evrakno = (string)($existing['header']['evrakno'] ?? $evrakno);
        } else {
            if ($evrakno === '') $evrakno = (string)self::nextEvrakNo($ctx);
        }

        $status   = trim((string)($header['status'] ?? 'DRAFT'));
        $customer = trim((string)($header['customer'] ?? ''));

        // version
        $prevVersion = (int)($existing['version'] ?? 0);
        $newVersion  = $isUpdate ? ($prevVersion + 1) : 1;

        $doc = [
            'CDEF01_id'    => $cdef,
            'PERIOD01T_id' => $period,
            'header' => [
                'evrakno'  => $evrakno,
                'customer' => $customer,
                'status'   => $status,
            ],
            'lines'  => array_values($lines),
            'version'    => $newVersion,
            'updated_at' => $now,
            'updated_by' => $user,
        ];

        if (!$isUpdate) {
            $doc['created_at'] = $now;
            $doc['created_by'] = $user;

            $ins = MongoManager::collection('SORD01E')->insertOne($doc);
            $id = (string)$ins->getInsertedId();
        } else {
            MongoManager::collection('SORD01E')->updateOne(
                ['_id' => self::oid($id)],
                ['$set' => $doc]
            );
        }

        // ===== SNAPSHOT =====
        $targetKey = self::snapTargetKey('salesorder', 'SORD01E', $id, $ctx);

        $prevSnap = MongoManager::collection('SNAP01E')->findOne(
            ['target_key' => $targetKey],
            ['sort' => ['version' => -1], 'projection' => ['_id' => 1, 'version' => 1]]
        );

        $prevSnapshotId = null;
        if ($prevSnap) {
            if ($prevSnap instanceof MongoDB\Model\BSONDocument) $prevSnap = $prevSnap->getArrayCopy();
            $prevSnapshotId = (string)($prevSnap['_id'] ?? '');
        }

        $snapDoc = [
            'created_at' => $now,
            'version'    => (int)$newVersion,
            'target_key' => $targetKey,

            'target' => [
                'module'    => 'salesorder',
                'doc_type'  => 'SORD01E',
                'doc_id'    => $id,
                'doc_no'    => $evrakno,
                'doc_title' => $customer,
                'status'    => $status,
            ],
            'data' => [
                'header' => $doc['header'],
                'lines'  => $doc['lines'],
            ],

            // ✅ snapshot_diff_view.php root'tan okuyor -> bu şart
            'prev_snapshot_id' => ($prevSnapshotId !== '' ? $prevSnapshotId : null),

            // refs de kalsın (geri uyumluluk / debug)
            'refs' => [
                'prev_snapshot_id' => ($prevSnapshotId !== '' ? $prevSnapshotId : null),
                'request_id' => ($_SERVER['HTTP_X_REQUEST_ID'] ?? null),
            ],

            // örnekteki gibi context şeması (period_id/facility_id/role vs)
            'context' => [
                'UDEF01_id'   => (string)($ctx['UDEF01_id'] ?? ''),
                'username'    => (string)($ctx['username'] ?? ''),
                'CDEF01_id'   => (string)($ctx['CDEF01_id'] ?? ''),
                'period_id'   => (string)($ctx['period_id'] ?? ($ctx['PERIOD01T_id'] ?? '')),
                'facility_id' => ($ctx['facility_id'] ?? ($ctx['FACILITY01_id'] ?? null)),
                'role'        => (string)($ctx['role'] ?? ''),
                'session_id'  => (string)($ctx['session_id'] ?? session_id()),
            ],
        ];

        // ✅ duplicate artık bitmeli: target_key asla null değil
        $snapIns = MongoManager::collection('SNAP01E')->insertOne($snapDoc);
        $snapshotId = (string)$snapIns->getInsertedId();

        // ===== LOG (UACT01E) =====
        $logId = ActionLogger::success('SORD.SAVE', [
            'source' => 'SORDRepository::save',
            'version' => $newVersion,
        ], $ctx, [
            'module' => 'salesorder',
            'doc_type' => 'SORD01E',
            'doc_id' => $id,
            'doc_no' => $evrakno,
        ]);

        // ===== EVENT01E =====
        MongoManager::collection('EVENT01E')->insertOne([
            'event_code' => 'SORD.SAVE',
            'created_at' => $now,
            'context' => [
                'CDEF01_id' => $cdef,
                'PERIOD01T_id' => $period,
                'username' => $user,
                'session_id' => (string)($ctx['session_id'] ?? session_id()),
                'UDEF01_id' => (string)($ctx['UDEF01_id'] ?? ''),
            ],
            'target' => [
                'module'    => 'salesorder',
                'doc_type'  => 'SORD01E',
                'doc_id'    => $id,
                'doc_no'    => $evrakno,
                'doc_title' => $customer,
                'status'    => $status,
            ],
            'refs' => [
                'log_id' => $logId,
                'snapshot_id' => $snapshotId,
                'prev_snapshot_id' => ($prevSnapshotId !== '' ? $prevSnapshotId : null),
                'request_id' => ($_SERVER['HTTP_X_REQUEST_ID'] ?? null),
            ],
            'data' => [
                'summary' => [
                    'title'   => 'Satış Siparişi: Kaydet',
                    'version' => $newVersion,
                    'doc_no'  => $evrakno,
                    'status'  => $status,
                ]
            ],
        ]);

        return [
            'SORD01_id' => $id,
            'evrakno'   => $evrakno,
            'version'   => $newVersion,
        ];
    }

    public static function deleteHard(string $id, array $ctx): void
    {
        if ($id === '' || strlen($id) !== 24) throw new RuntimeException('delete_requires_id');

        $doc = MongoManager::collection('SORD01E')->findOne(['_id'=>self::oid($id)]);
        if ($doc instanceof MongoDB\Model\BSONDocument) $doc = $doc->getArrayCopy();

        $evrakno = (string)($doc['header']['evrakno'] ?? $id);
        $customer= (string)($doc['header']['customer'] ?? '');
        $status  = (string)($doc['header']['status'] ?? '');

        MongoManager::collection('SORD01E')->deleteOne(['_id'=>self::oid($id)]);

        $logId = ActionLogger::success('SORD.DELETE', ['source'=>'SORDRepository::deleteHard'], $ctx, [
            'module'=>'salesorder','doc_type'=>'SORD01E','doc_id'=>$id,'doc_no'=>$evrakno
        ]);

        MongoManager::collection('EVENT01E')->insertOne([
            'event_code' => 'SORD.DELETE',
            'created_at' => self::nowUtc(),
            'context' => [
                'CDEF01_id' => (string)($ctx['CDEF01_id'] ?? ''),
                'PERIOD01T_id' => (string)($ctx['PERIOD01T_id'] ?? ($ctx['period_id'] ?? '')),
                'username' => (string)($ctx['username'] ?? ''),
                'session_id' => (string)($ctx['session_id'] ?? session_id()),
            ],
            'target' => [
                'module'=>'salesorder','doc_type'=>'SORD01E','doc_id'=>$id,'doc_no'=>$evrakno,'doc_title'=>$customer,'status'=>$status
            ],
            'refs' => [
                'log_id' => $logId,
            ],
            'data' => [
                'summary' => [
                    'title' => 'Satış Siparişi: Sil',
                    'doc_no' => $evrakno,
                ]
            ],
        ]);
    }
}
