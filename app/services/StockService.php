<?php
require_once __DIR__ . '/../action/ActionLogger.php';
class StockService
{
    public static function create(array $data): void
    {
        PermissionChecker::check('STOK01E', 'create');

        $stok = new StockDocument($data);
        $stok->save();

    }
}
