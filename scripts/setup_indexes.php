<?php
/**
 * scripts/setup_indexes.php
 *
 * AMAÇ:
 * - UACT01E (log), EVENT01E (event), SNAP01E (snapshot), LOCK01T (lock) indexlerini kurmak
 * - Tekrar çalıştırılabilir (idempotent) yaklaşım
 *
 * ÇALIŞTIR:
 *   cd C:\xampp\htdocs\php-mongo-erp
 *   php scripts\setup_indexes.php
 */

require_once __DIR__ . '/../core/bootstrap.php';

use MongoDB\Collection;

function indexExists(Collection $col, string $indexName): bool
{
    foreach ($col->listIndexes() as $idx) {
        $name = $idx->getName();
        if ($name === $indexName) return true;
    }
    return false;
}

function ensureIndex(Collection $col, array $keys, array $options): void
{
    $name = $options['name'] ?? null;
    if (!$name) {
        throw new RuntimeException("Index options must include 'name'.");
    }

    if (indexExists($col, $name)) {
        echo "SKIP  : {$col->getCollectionName()} -> {$name}\n";
        return;
    }

    $col->createIndex($keys, $options);
    echo "CREATE: {$col->getCollectionName()} -> {$name}\n";
}

echo "== INDEX SETUP START ==\n";

/**
 * 1) UACT01E (Audit Logs)
 */
$uact = MongoManager::collection('UACT01E');

ensureIndex($uact,
    ['CDEF01_id' => 1, 'period_id' => 1, 'created_at' => -1],
    ['name' => 'uact_company_period_createdAt_desc']
);

ensureIndex($uact,
    ['username' => 1, 'created_at' => -1],
    ['name' => 'uact_username_createdAt_desc']
);

ensureIndex($uact,
    ['action_code' => 1, 'created_at' => -1],
    ['name' => 'uact_action_createdAt_desc']
);

// NOT: target.* indexini log standardında target alanlarını eklediğimiz gün koyacağız.
// ensureIndex($uact, ['target.doc_type'=>1,'target.doc_id'=>1,'created_at'=>-1], ['name'=>'uact_doc_createdAt_desc']);

/**
 * 2) EVENT01E (Domain Events)
 */
$event = MongoManager::collection('EVENT01E');

ensureIndex($event,
    ['target.doc_type' => 1, 'target.doc_id' => 1, 'created_at' => -1],
    ['name' => 'event_doc_createdAt_desc']
);

ensureIndex($event,
    ['context.CDEF01_id' => 1, 'context.period_id' => 1, 'created_at' => -1],
    ['name' => 'event_company_period_createdAt_desc']
);

ensureIndex($event,
    ['event_code' => 1, 'created_at' => -1],
    ['name' => 'event_code_createdAt_desc']
);

ensureIndex($event,
    ['context.username' => 1, 'created_at' => -1],
    ['name' => 'event_username_createdAt_desc']
);

ensureIndex($event,
    ['target.module' => 1, 'created_at' => -1],
    ['name' => 'event_module_createdAt_desc']
);

/**
 * 3) SNAP01E (Snapshots)
 */
$snap = MongoManager::collection('SNAP01E');

// Unique: doc_type + doc_id + version
ensureIndex($snap,
    ['doc_type' => 1, 'doc_id' => 1, 'version' => 1],
    ['name' => 'snap_doc_version_unique', 'unique' => true]
);

ensureIndex($snap,
    ['doc_type' => 1, 'doc_id' => 1, 'created_at' => -1],
    ['name' => 'snap_doc_createdAt_desc']
);

// opsiyonel (hash doğrulama/arama için)
ensureIndex($snap,
    ['meta.hash' => 1],
    ['name' => 'snap_hash']
);

/**
 * 4) LOCK01T (Locks)
 */
$lock = MongoManager::collection('LOCK01T');

ensureIndex($lock,
    ['is_active' => 1, 'context.CDEF01_id' => 1, 'context.period_id' => 1, 'locked_at' => -1],
    ['name' => 'lock_active_company_period_lockedAt_desc']
);

ensureIndex($lock,
    ['is_active' => 1, 'expires_at' => 1],
    ['name' => 'lock_active_expiresAt']
);

ensureIndex($lock,
    ['doc_type' => 1, 'doc_id' => 1, 'is_active' => 1],
    ['name' => 'lock_doc_active']
);

// Partial Unique: aynı evrakta aynı anda sadece 1 aktif "edit" kilidi
ensureIndex($lock,
    ['doc_type' => 1, 'doc_id' => 1, 'lock_type' => 1],
    [
        'name' => 'lock_doc_edit_active_unique',
        'unique' => true,
        'partialFilterExpression' => [
            'is_active' => true,
            'lock_type' => 'edit'
        ]
    ]
);

echo "== INDEX SETUP DONE ==\n";
