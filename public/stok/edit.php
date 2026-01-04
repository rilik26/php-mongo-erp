<?php
/**
 * public/stok/edit.php (FINAL)
 */

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/auth/SessionManager.php';
require_once __DIR__ . '/../../core/base/Context.php';
require_once __DIR__ . '/../../core/base/ContextException.php';

require_once __DIR__ . '/../../core/action/ActionLogger.php';
require_once __DIR__ . '/../../core/event/EventWriter.php';
require_once __DIR__ . '/../../core/snapshot/SnapshotWriter.php';
require_once __DIR__ . '/../../core/webhook/WebhookService.php';

require_once __DIR__ . '/../../app/modules/stok/STOK01Repository.php';

SessionManager::start();
try { Context::bootFromSession(); }
catch (Throwable $e) { header('Location: /php-mongo-erp/public/login.php'); exit; }

$ctx = Context::get();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$id = trim((string)($_GET['id'] ?? ''));
if ($id !== '' && strlen($id) !== 24) $id = '';

$err = '';

// --- SAVE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post = $_POST;

    $fields = [
        'kod'       => trim((string)($post['kod'] ?? '')),
        'name'      => trim((string)($post['name'] ?? '')),
        'name2'     => trim((string)($post['name2'] ?? '')),
        'unit'      => trim((string)($post['unit'] ?? '')),
        'is_active' => (isset($post['is_active']) && $post['is_active'] === '1'),
    ];

    try {
        if ($fields['kod'] === '') {
            throw new InvalidArgumentException('kod_required');
        }

        $stat = STOK01Repository::save($fields, $ctx, ($id !== '' ? $id : null));
        $id = (string)($stat['STOK01_id'] ?? $id);

        // LOG
        $logId = ActionLogger::success('STOK.SAVE', [
            'source'   => 'public/stok/edit.php',
            'kod'      => $stat['kod'] ?? null,
            'STOK01_id'=> $stat['STOK01_id'] ?? null,
            'is_active'=> $stat['is_active'] ?? null,
            'version'  => $stat['version'] ?? null,
        ], $ctx);

        // EVENT (SAVE)
        EventWriter::emit(
            'STOK.SAVE',
            [
                'source' => 'public/stok/edit.php',
                'summary' => [
                    'kod'       => $stat['kod'] ?? null,
                    'name'      => $stat['name'] ?? null,
                    'is_active' => $stat['is_active'] ?? null,
                    'version'   => $stat['version'] ?? null,
                ],
            ],
            [
                'module'    => 'stok',
                'doc_type'  => 'STOK01E',
                'doc_id'    => $stat['STOK01_id'] ?? null,
                'doc_no'    => $stat['kod'] ?? null,
                'doc_title' => $stat['name'] ?? 'Stock',
                'status'    => ($stat['is_active'] ?? true) ? 'ACTIVE' : 'PASSIVE',
            ],
            $ctx,
            ['log_id' => $logId]
        );

        // SNAPSHOT
        $dump = STOK01Repository::dumpFull((string)$stat['STOK01_id']);
        $snap = SnapshotWriter::capture(
            [
                'module'    => 'stok',
                'doc_type'  => 'STOK01E',
                'doc_id'    => $stat['STOK01_id'],
                'doc_no'    => $stat['kod'],
                'doc_title' => $stat['name'] ?? 'Stock',
                'status'    => ($stat['is_active'] ?? true) ? 'ACTIVE' : 'PASSIVE',
            ],
            $dump,
            [
                'reason' => 'save',
                'changed_fields' => array_keys($fields),
                'version' => $stat['version'] ?? null,
            ]
        );

        EventWriter::emit(
            'STOK.SNAPSHOT',
            [
                'source' => 'public/stok/edit.php',
                'summary' => [
                    'snapshot_id' => $snap['snapshot_id'] ?? null,
                ],
            ],
            [
                'module'    => 'stok',
                'doc_type'  => 'STOK01E',
                'doc_id'    => $stat['STOK01_id'],
                'doc_no'    => $stat['kod'],
                'doc_title' => $stat['name'] ?? 'Stock',
                'status'    => ($stat['is_active'] ?? true) ? 'ACTIVE' : 'PASSIVE',
            ],
            $ctx,
            [
                'log_id' => $logId,
                'snapshot_id' => $snap['snapshot_id'] ?? null,
                'prev_snapshot_id' => $snap['prev_snapshot_id'] ?? null,
            ]
        );

        // WEBHOOK
        WebhookService::dispatch('STOK.SAVE', [
            'STOK01_id' => $stat['STOK01_id'],
            'kod'       => $stat['kod'],
            'name'      => $stat['name'] ?? null,
            'name2'     => $stat['name2'] ?? null,
            'unit'      => $stat['unit'] ?? null,
            'is_active' => $stat['is_active'] ?? null,
            'version'   => $stat['version'] ?? null,
        ], $ctx);

        WebhookService::dispatch('STOK.SNAPSHOT', [
            'STOK01_id' => $stat['STOK01_id'],
            'kod'       => $stat['kod'],
            'snapshot_id'      => $snap['snapshot_id'] ?? null,
            'prev_snapshot_id' => $snap['prev_snapshot_id'] ?? null,
        ], $ctx);

        header('Location: /php-mongo-erp/public/stok/edit.php?id=' . urlencode($id) . '&ok=1');
        exit;

    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

// --- LOAD ---
$doc = null;
if ($id !== '') {
    $doc = STOK01Repository::dumpFull($id);
}

// geriye uyumlu load
$kod   = trim((string)($doc['kod'] ?? ($doc['stok_kodu'] ?? '')));
$name  = trim((string)($doc['name'] ?? ($doc['stok_adi'] ?? '')));
$name2 = trim((string)($doc['name2'] ?? ''));
$unit  = trim((string)($doc['unit'] ?? ($doc['birim'] ?? '')));
$is_active = (bool)($doc['is_active'] ?? true);
$version   = (int)($doc['version'] ?? 0);

$ok = (string)($_GET['ok'] ?? '') === '1';

require_once BASE_PATH . '/app/views/layout/header.php';
?>
<body>
<div class="layout-wrapper layout-content-navbar">
  <div class="layout-container">
    <?php require_once BASE_PATH . '/app/views/layout/left.php'; ?>

    <div class="layout-page">
      <?php require_once BASE_PATH . '/app/views/layout/header2.php'; ?>

      <div class="content-wrapper">
        <div class="container-xxl flex-grow-1 container-p-y">

          <div class="d-flex align-items-center justify-content-between mb-3">
            <h4 class="m-0">Stok Kartı</h4>
            <div>
              <a class="btn btn-outline-secondary" href="/php-mongo-erp/public/stok/index.php">← Liste</a>
            </div>
          </div>

          <?php if ($ok): ?>
            <div class="alert alert-success">Kaydedildi.</div>
          <?php endif; ?>

          <?php if ($err !== ''): ?>
            <div class="alert alert-danger">Kaydetme sırasında hata oluştu. Kaydedilmedi. (<?php echo h($err); ?>)</div>
          <?php endif; ?>

          <div class="card">
            <div class="card-body">
              <form method="post" action="">
                <div class="row g-3">
                  <div class="col-md-4">
                    <label class="form-label">Kod (benzersiz)</label>
                    <input class="form-control" name="kod" value="<?php echo h($kod); ?>" required />
                  </div>

                  <div class="col-md-4">
                    <label class="form-label">Ad</label>
                    <input class="form-control" name="name" value="<?php echo h($name); ?>" />
                  </div>

                  <div class="col-md-4">
                    <label class="form-label">Ad-2</label>
                    <input class="form-control" name="name2" value="<?php echo h($name2); ?>" />
                  </div>

                  <div class="col-md-3">
                    <label class="form-label">Birim</label>
                    <input class="form-control" name="unit" value="<?php echo h($unit); ?>" />
                  </div>

                  <div class="col-md-3">
                    <label class="form-label">Durum</label>
                    <select class="form-select" name="is_active">
                      <option value="1" <?php echo $is_active ? 'selected' : ''; ?>>Aktif</option>
                      <option value="0" <?php echo !$is_active ? 'selected' : ''; ?>>Pasif</option>
                    </select>
                  </div>

                  <div class="col-md-3">
                    <label class="form-label">Versiyon</label>
                    <input class="form-control" value="<?php echo (int)$version; ?>" disabled />
                  </div>

                  <div class="col-12">
                    <button class="btn btn-primary" type="submit">Kaydet</button>
                  </div>
                </div>
              </form>
            </div>
          </div>

        </div>

        <div class="content-backdrop fade"></div>
      </div>
    </div>
  </div>

  <div class="layout-overlay layout-menu-toggle"></div>
  <div class="drag-target"></div>
</div>

<?php require_once BASE_PATH . '/app/views/layout/footer.php'; ?>
</body>
</html>
