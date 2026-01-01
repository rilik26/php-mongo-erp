<?php
/**
 * core/sequence/SequenceService.php (FINAL)
 *
 * AMAÇ:
 * - Sadece SORD01E için (scope = firma + dönem) artan evrak no üretmek
 * - Refresh'te artmaması için: evrakno sadece SAVE sırasında alınır.
 *
 * Koleksiyon: SEQ01T
 * Doc:
 * - key: string (örn "SORD01E")
 * - scope: { CDEF01_id: "...", PERIOD01T_id: "..." }
 * - seq: int
 */

final class SequenceService
{
    /**
     * Sadece save anında çağır.
     * @return int next sequence value (1,2,3...)
     */
    public static function nextDocNo(string $key, array $scope): int
    {
        $key = trim($key);
        if ($key === '') throw new InvalidArgumentException('seq_key_required');

        // scope normalize (array sırası önemli değil ama biz stabil string yapmıyoruz, direkt object saklıyoruz)
        $scope = is_array($scope) ? $scope : [];
        if (empty($scope)) throw new InvalidArgumentException('seq_scope_required');

        $now = new MongoDB\BSON\UTCDateTime((int)floor(microtime(true) * 1000));

        // ✅ Mongo "conflict" hatası: aynı path'i iki operatörde güncelleme -> seq’i setOnInsert’e koyma!
        $doc = MongoManager::collection('SEQ01T')->findOneAndUpdate(
            ['key' => $key, 'scope' => $scope],
            [
                '$inc' => ['seq' => 1],
                '$setOnInsert' => [
                    'created_at' => $now,
                    'active'     => true
                ],
                '$set' => [
                    'updated_at' => $now
                ]
            ],
            [
                'upsert' => true,
                'returnDocument' => MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER
            ]
        );

        if ($doc instanceof MongoDB\Model\BSONDocument) $doc = $doc->getArrayCopy();
        $seq = (int)($doc['seq'] ?? 0);

        if ($seq <= 0) throw new RuntimeException('seq_failed');
        return $seq;
    }
}
