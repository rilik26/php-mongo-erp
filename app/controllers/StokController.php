<?php

class StockController
{
    public function create(): void
    {
        PermissionChecker::check('STOK01E', 'create');

        $stok = new StockDocument([
            'stok_kodu' => 'HAM001',
            'stok_adi'  => 'Ham Madde 1',
            'birim'     => 'KG'
        ]);

        $stok->save();

        echo 'STOK01E kayıt edildi';
    }
}
