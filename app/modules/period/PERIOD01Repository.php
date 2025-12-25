<?php
/**
 * PERIOD01Repository.php
 *
 * AMAÇ:
 * - Firma bazlı PERIOD01T (dönem tablosu) veri erişimi
 *
 * MODEL:
 * - CDEF01_id: firma _id (string, 24 char)
 * - period_id: "2025"
 * - title: "2025 Dönemi"
 * - is_open: true/false
 *
 * SORUMLULUK:
 * - listOpenPeriods(): sadece açık dönemler
 * - listAllPeriods(): açık + kapalı dönemler (UI için)
 * - isOpen(): doğrulama (login / change_period güvenliği)
 */

class PERIOD01Repository
{
    /**
     * Firma için SADECE açık dönemleri getir
     */
    public static function listOpenPeriods(string $companyId): array
    {
        $cursor = MongoManager::collection('PERIOD01T')->find(
            [
                'CDEF01_id' => $companyId,
                'is_open'   => true
            ],
            ['sort' => ['period_id' => 1]]
        );

        return self::normalizeCursor($cursor);
    }

    /**
     * Firma için TÜM dönemleri getir (açık + kapalı)
     * UI’da disabled göstermek için kullanılır.
     */
    public static function listAllPeriods(string $companyId): array
    {
        $cursor = MongoManager::collection('PERIOD01T')->find(
            [
                'CDEF01_id' => $companyId
            ],
            ['sort' => ['period_id' => 1]]
        );

        return self::normalizeCursor($cursor);
    }

    /**
     * Period o firma için açık mı?
     * Güvenlik kontrolü burada yapılır (UI hacklenemez).
     */
    public static function isOpen(string $periodId, string $companyId): bool
    {
        $doc = MongoManager::collection('PERIOD01T')->findOne([
            'CDEF01_id' => $companyId,
            'period_id' => $periodId,
            'is_open'   => true
        ]);

        return (bool)$doc;
    }

    /**
     * Ortak normalize
     */
    private static function normalizeCursor($cursor): array
    {
        $out = [];

        foreach ($cursor as $doc) {
            $arr = (array)$doc;
            $out[] = [
                'period_id' => $arr['period_id'] ?? null,
                'title'     => $arr['title'] ?? ($arr['period_id'] ?? ''),
                'is_open'   => (bool)($arr['is_open'] ?? false),
            ];
        }

        return $out;
    }
}
