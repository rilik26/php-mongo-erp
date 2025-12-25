<?php
/**
 * roles.php
 * Amaç: Rol bazlı yetki tanımları
 */

return [

    'admin' => [
        '*' => ['*'], // her evrak, her aksiyon
    ],

    'depo_sorumlusu' => [
        'STOK01E' => ['view', 'create', 'update'],
        'WHOU01E' => ['view'],
    ],

    'uretim' => [
        'MFG01E' => ['view', 'create'],
        'BOM01E' => ['view'],
    ],

];
