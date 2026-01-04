<?php
/**
 * app/controllers/StokController.php
 *
 * Bu controller proje içinde şu an route edilmiyor.
 * Ancak legacy StockDocument referansını kaldırmak için güncellendi.
 */

require_once __DIR__ . '/../services/StockService.php';

class StockController
{
    public function create(array $ctx): array
    {
        return StockService::save([
            'stok_kodu' => 'HAM001',
            'stok_adi'  => 'Ham Madde 1',
            'birim'     => 'KG',
            'is_active' => true,
        ], $ctx, null);
    }
}
