<?php
/**
 * app/modules/salesorder/SORDRepository.php (FINAL)
 *
 * Koleksiyonlar:
 * - SORD01E (Evrak / Header)
 * - SORD01T (Tablo / Lines)
 *
 * Kurallar:
 * - SORD01E.CDEF01_id    : string (24 char)
 * - SORD01E.PERIOD01T_id : string (24 char)  ✅ referans
 * - SORD01E.evrakno      : evrak numarası (doc_no karşılığı)
 */

final class SORDRepository
{
    public static function save(array $header, array $lines, array $ctx): array
    {
        $companyId  = trim((string)($ctx['CDEF01_id'] ?? ''));
        $periodOid  = trim((string)($ctx['PERIOD01T_id'] ?? '')); // ✅ yeni

        if ($companyId === '' || strlen($companyId) !== 24) {
            throw new InvalidArgumentException('company_required');
        }
        if ($periodOid === '' || strlen($periodOid) !== 24) {
            throw new InvalidArgumentException('period_required');
        }

        $evrakno = trim((string)($header['evrakno'] ?? ''));
        if ($evrakno === '') {
            throw new InvalidArgumentException('evrakno_required');
        }

        $now = new MongoDB\BSON\UTCDateTime((int)floor(microtime(true) * 1000));

        // Header normalize
        $hdrSet = $header;

        // ✅ zorunlu alanlar
        $hdrSet['CDEF01_id']    = $companyId;
        $hdrSet['PERIOD01T_id'] = $periodOid;   // ✅ ref
        $hdrSet['evrakno']      = $evrakno;

        // eski alanı yanlışlıkla taşımayalım
        unset($hdrSet['period_id']);

        $hdrSet['updated_at'] = $now;

        // Upsert header by (companyId + periodOid + evrakno)
        MongoManager::collection('SORD01E')->updateOne(
            ['CDEF01_id'=>$companyId, 'PERIOD01T_id'=>$periodOid, 'evrakno'=>$evrakno],
            [
                '$set' => $hdrSet,
                '$setOnInsert' => [
                    'created_at' => $now,
                    'version'    => 1
                ]
            ],
            ['upsert' => true]
        );

        // Read back header (to get _id)
        $doc = MongoManager::collection('SORD01E')->findOne([
            'CDEF01_id'=>$companyId,
            'PERIOD01T_id'=>$periodOid,
            'evrakno'=>$evrakno
        ]);

        if (!$doc) throw new RuntimeException('header_save_failed');
        if ($doc instanceof MongoDB\Model\BSONDocument) $doc = $doc->getArrayCopy();

        $sordId = (string)($doc['_id'] ?? '');
        if ($sordId === '') throw new RuntimeException('header_id_missing');

        // Lines strategy (Phase-1): delete+insert
        MongoManager::collection('SORD01T')->deleteMany([
            'SORD01_id' => $sordId
        ]);

        $bulk = [];
        $i = 0;

        foreach ($lines as $ln) {
            if (!is_array($ln)) continue;
            $i++;

            $ln['SORD01_id']  = $sordId;                 // string ref
            $ln['line_no']    = (int)($ln['line_no'] ?? $i);
            $ln['created_at'] = $now;

            // opsiyonel
            // $ln['CDEF01_id']    = $companyId;
            // $ln['PERIOD01T_id'] = $periodOid;

            $bulk[] = $ln;
        }

        if (!empty($bulk)) {
            MongoManager::collection('SORD01T')->insertMany($bulk);
        }

        // bump header version
        MongoManager::collection('SORD01E')->updateOne(
            ['_id' => new MongoDB\BSON\ObjectId($sordId)],
            [
                '$inc' => ['version' => 1],
                '$set' => ['updated_at' => $now]
            ]
        );

        return [
            'SORD01_id'     => $sordId,
            'evrakno'       => $evrakno,
            'CDEF01_id'     => $companyId,
            'PERIOD01T_id'  => $periodOid,
            'lines_count'   => count($bulk),
            'version'       => (int)(($doc['version'] ?? 1) + 1),
        ];
    }

    public static function dumpFull(string $sordId): array
    {
        $sordId = trim($sordId);
        if ($sordId === '' || strlen($sordId) !== 24) return [];

        $hdr = MongoManager::collection('SORD01E')->findOne([
            '_id' => new MongoDB\BSON\ObjectId($sordId)
        ]);
        if (!$hdr) return [];
        if ($hdr instanceof MongoDB\Model\BSONDocument) $hdr = $hdr->getArrayCopy();

        $linesCur = MongoManager::collection('SORD01T')->find(
            ['SORD01_id' => $sordId],
            ['sort' => ['line_no' => 1]]
        );

        $lines = [];
        foreach ($linesCur as $l) {
            if ($l instanceof MongoDB\Model\BSONDocument) $l = $l->getArrayCopy();
            $lines[] = $l;
        }

        return [
            'header' => $hdr,
            'lines'  => $lines,
        ];
    }
}
