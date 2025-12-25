<?php
/**
 * STOK01Document.php
 *
 * AMAÇ:
 * - STOK01E evrakı için BaseDocument'i kullanarak standart CRUD kazanmak
 *
 * SORUMLULUK:
 * - collectionCode() döndürmek
 */

require_once __DIR__ . '/../../core/base/BaseDocument.php';

final class STOK01Document extends BaseDocument
{
    public static function collectionCode(): string
    {
        return 'STOK01E';
    }
}
