<?php
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/auth/SessionManager.php';
require_once __DIR__ . '/../../core/base/Context.php';
require_once __DIR__ . '/../../core/base/ContextException.php';

require_once __DIR__ . '/../../app/modules/gdef/GDEF01ERepository.php';

SessionManager::start();
if (!isset($_SESSION['context']) || !is_array($_SESSION['context'])) {
  header('Location: /php-mongo-erp/public/login.php'); exit;
}
try { Context::bootFromSession(); } catch (ContextException $e) {
  header('Location: /php-mongo-erp/public/login.php'); exit;
}

function esc($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$rows = GDEF01ERepository::listAll(1000);

require_once __DIR__ . '/../../app/views/layout/header.php';
?>
<body>
<div class="layout-wrapper layout-content-navbar">
  <div class="layout-container">
    <?php require_once __DIR__ . '/../../app/views/layout/left.php'; ?>
    <div class="layout-page">
      <?php require_once __DIR__ . '/../../app/views/layout/header2.php'; ?>

      <div class="content-wrapper">
        <div class="container-xxl flex-grow-1 container-p-y">

          <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
            <h4 style="margin:0;">Grup Tanımları (GDEF)</h4>
            <a class="btn btn-primary" href="/php-mongo-erp/public/gdef/group_edit.php">+ Yeni Grup</a>
          </div>

          <div class="card mt-3">
            <div class="card-body">
              <div class="table-responsive">
                <table class="table table-sm align-middle">
                  <thead>
                    <tr>
                      <th style="width:220px;">Kod</th>
                      <th>Ad</th>
                      <th style="width:160px;">Durum</th>
                      <th style="width:220px;">İşlem</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (empty($rows)): ?>
                      <tr><td colspan="4" class="text-muted">Kayıt yok.</td></tr>
                    <?php endif; ?>

                    <?php foreach ($rows as $r): ?>
                      <?php $id = (string)($r['_id'] ?? ''); ?>
                      <tr>
                        <td><b><?php echo esc($r['kod'] ?? ''); ?></b></td>
                        <td>
                          <?php echo esc($r['name'] ?? ''); ?>
                          <?php if (!empty($r['name2'])): ?>
                            <div class="text-muted" style="font-size:12px;"><?php echo esc($r['name2']); ?></div>
                          <?php endif; ?>
                        </td>
                        <td>
                          <?php if (!empty($r['is_active'])): ?>
                            <span class="badge bg-success">AKTİF</span>
                          <?php else: ?>
                            <span class="badge bg-secondary">PASİF</span>
                          <?php endif; ?>
                        </td>
                        <td style="display:flex;gap:8px;flex-wrap:wrap;">
                          <a class="btn btn-sm btn-outline-primary" href="/php-mongo-erp/public/gdef/group_edit.php?id=<?php echo esc($id); ?>">Düzenle</a>
                          <a class="btn btn-sm btn-outline-dark" href="/php-mongo-erp/public/gdef/items.php?group=<?php echo esc($r['kod'] ?? ''); ?>">Satırlar</a>
                        </td>
                      </tr>
                    <?php endforeach; ?>

                  </tbody>
                </table>
              </div>
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
<?php require_once __DIR__ . '/../../app/views/layout/footer.php'; ?>
</body>
</html>
