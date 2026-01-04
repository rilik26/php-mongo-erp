<?php
/**
 * app/services/StockService.php
 *
 * Legacy StockDocument bağımlılığı vardı. STOK01Repository ile uyumlu hale getirildi.
 */

require_once __DIR__ . '/../modules/stok/STOK01Repository.php';

class StockService
{
    /**
     * Context'e göre stok kartı create/update.
     *
     * @return array STOK01Repository::save dönüşü
     */
    public static function save(array $fields, array $ctx, ?string $id = null): array
    {
        PermissionChecker::check('STOK01E', 'create');
        return STOK01Repository::save($fields, $ctx, $id);
    }
}
