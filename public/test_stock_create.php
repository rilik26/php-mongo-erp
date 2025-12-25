<?php

require_once __DIR__ . '/index.php'; // 🔥 ZORUNLU

require_once __DIR__ . '/../app/modules/stok/StockDocument.php';
require_once __DIR__ . '/../core/action/ActionLogger.php';

$stok = new StockDocument([
    'stok_kodu' => 'HAM001',
    'stok_adi'  => 'Ham Madde 1',
    'birim'     => 'KG'
]);

$stok->save();
        if($stok->save())
        {
            ActionLogger::log('ok_stock_create');
        }
        {
            ActionLogger::log('nok_stock_create');
        }
echo 'STOK KAYDI OK';
