<?php
/**
 * public/stok/index.php (FINAL)
 */

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/auth/SessionManager.php';
require_once __DIR__ . '/../../core/base/Context.php';

require_once __DIR__ . '/../../app/modules/stok/STOK01Repository.php';

SessionManager::start();
try { Context::bootFromSession(); }
catch (Throwable $e) { header('Location: /php-mongo-erp/public/login.php'); exit; }

$ctx = Context::get();

$rows = STOK01Repository::listByContext($ctx, 500);

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
            <h4 class="m-0">Stok Kartları</h4>
            <a class="btn btn-primary" href="/php-mongo-erp/public/stok/edit.php">Yeni</a>
          </div>

          <div class="card">
            <div class="table-responsive text-nowrap">
              <table class="table">
                <thead>
                  <tr>
                    <th>Kod</th>
                    <th>Ad</th>
                    <th>Ad-2</th>
                    <th>Birim</th>
                    <th>Durum</th>
                    <th>Versiyon</th>
                    <th class="text-end"></th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                  <tr>
                    <td><b><?php echo htmlspecialchars((string)$r['kod'], ENT_QUOTES, 'UTF-8'); ?></b></td>
                    <td><?php echo htmlspecialchars((string)$r['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)$r['name2'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)$r['unit'], ENT_QUOTES, 'UTF-8'); ?></td>

                    <td>
                      <?php if (!empty($r['is_active'])): ?>
                        <span class="badge bg-success">Aktif</span>
                      <?php else: ?>
                        <span class="badge bg-secondary">Pasif</span>
                      <?php endif; ?>
                    </td>

                    <td><?php echo (int)($r['version'] ?? 0); ?></td>

                    <td class="text-end">
                      <a class="btn btn-sm btn-outline-primary" href="/php-mongo-erp/public/stok/edit.php?id=<?php echo urlencode((string)$r['_id']); ?>">
                        Aç
                      </a>
                    </td>
                  </tr>
                <?php endforeach; ?>

                <?php if (empty($rows)): ?>
                  <tr><td colspan="7" class="text-muted">Kayıt yok.</td></tr>
                <?php endif; ?>

                </tbody>
              </table>
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
