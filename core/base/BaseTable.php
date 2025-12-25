<?php
/**
 * BaseTable
 *
 * Tüm TABLO koleksiyonlarının
 * (T ile başlayanlar) atasıdır.
 *
 * AMAÇ:
 * - Evraka bağlı satır yönetimi
 * - document_id zorunluluğu
 * - Bağımsız tablo kullanımını engellemek
 */

abstract class BaseTable
{
    protected string $collectionCode;

    /**
     * Tabloya satır ekler
     *
     * @param string $documentId
     * @param array  $row
     */
    public function addRow(string $documentId, array $row): void
    {
        $row['document_id'] = $documentId;
        $row['created_at']  = new MongoDB\BSON\UTCDateTime();

        MongoManager::insertTableRow(
            $this->collectionCode,
            $row
        );
    }
}
