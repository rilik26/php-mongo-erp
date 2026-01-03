<?php
/**
 * public/salesorder/index.php (FINAL - THEME)
 */

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/auth/SessionManager.php';
require_once __DIR__ . '/../../core/base/Context.php';

require_once __DIR__ . '/../../app/modules/salesorder/SORDRepository.php';

SessionManager::start();
try { Context::bootFromSession(); }
catch (Throwable $e) { header('Location: /php-mongo-erp/public/login.php'); exit; }

$ctx = Context::get();
$rows = SORDRepository::listByContext($ctx, 200);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

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

          <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <h4 class="mb-0">Satış Siparişleri</h4>
            <div class="d-flex gap-2">
              <a class="btn btn-outline-primary" href="/php-mongo-erp/public/locks.php">Locks</a>
              <a class="btn btn-primary" href="/php-mongo-erp/public/salesorder/edit.php">Yeni</a>
            </div>
          </div>

          <div class="card">
            <div class="table-responsive text-nowrap">
              <table class="table">
                <thead>
                  <tr>
                    <th>Evrak No</th>
                    <th>Müşteri</th>
                    <th>Durum</th>
                    <th>Versiyon</th>
                    <th class="text-end"></th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                  <?php
                    $id = (string)($r['_id'] ?? '');
                    $docNo = (string)($r['evrakno'] ?? '');
                    $docTitle = (string)($r['customer'] ?? '');
                    $status = (string)($r['status'] ?? '');
                    $ver = (string)($r['version'] ?? '');
                    $tlUrl = '/php-mongo-erp/public/timeline.php?module=salesorder&doc_type=SORD01E&doc_id=' . rawurlencode($id);
                  ?>
                  <tr>
                    <td><?php echo h($docNo); ?></td>
                    <td><?php echo h($docTitle); ?></td>
                    <td><?php echo h($status); ?></td>
                    <td><?php echo h($ver); ?></td>
                    <td class="text-end">
                      <div class="d-inline-flex gap-2">
                        <a class="btn btn-sm btn-outline-secondary" target="_blank" href="<?php echo h($tlUrl); ?>">Timeline</a>
                        <a class="btn btn-sm btn-outline-primary"
                           href="/php-mongo-erp/public/salesorder/edit.php?id=<?php echo h($id); ?>">
                           Aç
                        </a>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if (empty($rows)): ?>
                  <tr><td colspan="5" class="text-center text-muted p-4">Kayıt yok</td></tr>
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
</div>
<?php require_once BASE_PATH . '/app/views/layout/footer.php'; ?>
</body>
</html>
