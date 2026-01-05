<?php
/**
 * public/gdef/items.php (FINAL - NO MODAL)
 *
 * URL: /php-mongo-erp/public/gdef/items.php?group=unit
 *
 * ✅ HTML ekranı (JSON değil)
 * ✅ Lock keep-alive (GDEF01E dokümanı üzerinden)
 * ✅ Satır ekle / düzenle ayrı sayfa (modal yok)
 * ✅ Pasife/Aktife al butonları çalışır (POST -> api/gdef_item_toggle.php)
 */

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/auth/SessionManager.php';
require_once __DIR__ . '/../../core/base/Context.php';
require_once __DIR__ . '/../../core/base/ContextException.php';

require_once __DIR__ . '/../../app/modules/gdef/GDEF01TRepository.php';

SessionManager::start();

if (!isset($_SESSION['context']) || !is_array($_SESSION['context'])) {
  header('Location: /php-mongo-erp/public/login.php'); exit;
}

try { Context::bootFromSession(); }
catch (ContextException $e) {
  header('Location: /php-mongo-erp/public/login.php'); exit;
}

$ctx = Context::get();
date_default_timezone_set('Europe/Istanbul');

function esc($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$group = trim((string)($_GET['group'] ?? ''));
if ($group === '') {
  header('Location: /php-mongo-erp/public/gdef/index.php'); exit;
}

$g = GDEF01TRepository::findGroupByCode($group);
if (!$g) {
  http_response_code(404);
  echo "group_not_found";
  exit;
}

$items = GDEF01TRepository::listByGroup($group);

// flash msg
$flash = trim((string)($_GET['msg'] ?? ''));

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

          <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
            <div>
              <h4 class="mb-1">Grup Satırları</h4>
              <div class="text-muted" style="font-size:12px;">
                Grup: <b><?php echo esc($g['code'] ?? $group); ?></b>
                — <?php echo esc($g['name'] ?? ''); ?>
              </div>
            </div>

            <div class="d-flex gap-2 flex-wrap">
              <a class="btn btn-outline-secondary" href="/php-mongo-erp/public/gdef/index.php">← Gruplar</a>
              <a class="btn btn-primary" href="/php-mongo-erp/public/gdef/item_edit.php?group=<?php echo urlencode($group); ?>">+ Satır Ekle</a>
            </div>
          </div>

          <?php if ($flash !== ''): ?>
            <div class="alert alert-info"><?php echo esc($flash); ?></div>
          <?php endif; ?>

          <div class="card">
            <div class="table-responsive">
              <table class="table table-hover mb-0">
                <thead>
                  <tr>
                    <th style="width:180px;">Kod</th>
                    <th>Ad</th>
                    <th style="width:220px;">Ad-2</th>
                    <th style="width:120px;">Durum</th>
                    <th style="width:280px;" class="text-end">İşlemler</th>
                  </tr>
                </thead>
                <tbody>
                <?php if (empty($items)): ?>
                  <tr><td colspan="5" class="text-muted">Satır yok.</td></tr>
                <?php endif; ?>

                <?php foreach ($items as $it):
                  $id = (string)($it['_id'] ?? '');
                  $active = (bool)($it['is_active'] ?? true);
                ?>
                  <tr>
                    <td><span class="badge bg-label-dark"><?php echo esc($it['code'] ?? ''); ?></span></td>
                    <td><?php echo esc($it['name'] ?? ''); ?></td>
                    <td><?php echo esc($it['name2'] ?? ''); ?></td>
                    <td>
                      <?php if ($active): ?>
                        <span class="badge bg-success">AKTİF</span>
                      <?php else: ?>
                        <span class="badge bg-secondary">PASİF</span>
                      <?php endif; ?>
                    </td>
                    <td class="text-end">
                      <a class="btn btn-sm btn-outline-primary"
                         href="/php-mongo-erp/public/gdef/item_edit.php?group=<?php echo urlencode($group); ?>&id=<?php echo urlencode($id); ?>">
                        Düzenle
                      </a>

                      <form method="POST"
                            action="/php-mongo-erp/public/api/gdef_item_toggle.php"
                            style="display:inline-block; margin-left:6px;"
                            onsubmit="return confirm('Emin misin?');">
                        <input type="hidden" name="group" value="<?php echo esc($group); ?>">
                        <input type="hidden" name="id" value="<?php echo esc($id); ?>">
                        <input type="hidden" name="to" value="<?php echo $active ? '0' : '1'; ?>">

                        <?php if ($active): ?>
                          <button type="submit" class="btn btn-sm btn-outline-danger">Pasife Al</button>
                        <?php else: ?>
                          <button type="submit" class="btn btn-sm btn-outline-success">Aktife Al</button>
                        <?php endif; ?>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
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

<script>
// === lock keep-alive ===
// Bu sayfa grup satırlarını yönetiyor => GDEF01E (grup) dokümanını kilitliyoruz.
(function(){
  const groupId = <?php echo json_encode((string)($g['_id'] ?? '')); ?>;
  if (!groupId || groupId.length !== 24) return;

  const module = 'gdef';
  const docType = 'GDEF01E';
  const docId = groupId;

  function ping(){
    try{
      const u = new URL('/php-mongo-erp/public/api/lock_acquire.php', window.location.origin);
      u.searchParams.set('module', module);
      u.searchParams.set('doc_type', docType);
      u.searchParams.set('doc_id', docId);
      u.searchParams.set('status', 'editing');
      u.searchParams.set('ttl', '300');

      // meta
      u.searchParams.set('doc_no', <?php echo json_encode((string)($g['code'] ?? $group)); ?>);
      u.searchParams.set('doc_title', <?php echo json_encode((string)($g['name'] ?? '')); ?>);
      u.searchParams.set('doc_status', <?php echo json_encode(((bool)($g['is_active'] ?? true)) ? 'ACTIVE' : 'PASSIVE'); ?>);

      fetch(u.toString(), { method:'GET', credentials:'same-origin' });
    } catch(e){}
  }

  ping();
  setInterval(ping, 90000);
})();
</script>

<?php require_once __DIR__ . '/../../app/views/layout/footer.php'; ?>
</body>
</html>
