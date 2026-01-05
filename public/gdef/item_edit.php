<?php
/**
 * public/gdef/item_edit.php (FINAL - NO MODAL)
 *
 * - group zorunlu
 * - id varsa edit, yoksa create
 * - Kaydet: fetch POST -> api/gdef_item_save.php
 * - ✅ Lock keep-alive: GDEF01E (grup) üzerinden
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

function esc($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$group = trim((string)($_GET['group'] ?? ''));
$id    = trim((string)($_GET['id'] ?? ''));

if ($group === '') {
  header('Location: /php-mongo-erp/public/gdef/index.php'); exit;
}

$g = GDEF01TRepository::findGroupByCode($group);
if (!$g) { http_response_code(404); echo "group_not_found"; exit; }

$isEdit = ($id !== '' && strlen($id) === 24);

$item = [
  '_id' => '',
  'code' => '',
  'name' => '',
  'name2' => '',
  'is_active' => true,
];

if ($isEdit) {
  $found = GDEF01TRepository::findById($id);
  if (!$found) { http_response_code(404); echo "item_not_found"; exit; }
  if ((string)($found['GDEF01E_code'] ?? '') !== $group) { http_response_code(400); echo "group_mismatch"; exit; }

  $item['_id'] = (string)($found['_id'] ?? '');
  $item['code'] = (string)($found['code'] ?? '');
  $item['name'] = (string)($found['name'] ?? '');
  $item['name2'] = (string)($found['name2'] ?? '');
  $item['is_active'] = (bool)($found['is_active'] ?? true);
}

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
              <h4 class="mb-1"><?php echo $isEdit ? 'Satır Düzenle' : 'Satır Ekle'; ?></h4>
              <div class="text-muted" style="font-size:12px;">
                Grup: <b><?php echo esc($g['code'] ?? $group); ?></b> — <?php echo esc($g['name'] ?? ''); ?>
              </div>
            </div>

            <div class="d-flex gap-2 flex-wrap">
              <a class="btn btn-outline-secondary" href="/php-mongo-erp/public/gdef/items.php?group=<?php echo urlencode($group); ?>">← Satırlar</a>
            </div>
          </div>

          <div id="alertBox" class="alert alert-danger d-none"></div>

          <div class="card p-3">
            <div class="row g-3">

              <div class="col-md-4">
                <label class="form-label">Kod *</label>
                <input id="code" class="form-control" value="<?php echo esc($item['code']); ?>" autocomplete="off">
              </div>

              <div class="col-md-5">
                <label class="form-label">Ad *</label>
                <input id="name" class="form-control" value="<?php echo esc($item['name']); ?>" autocomplete="off">
              </div>

              <div class="col-md-3">
                <label class="form-label">Ad-2</label>
                <input id="name2" class="form-control" value="<?php echo esc($item['name2']); ?>" autocomplete="off">
              </div>

              <div class="col-md-3">
                <label class="form-label">Durum</label>
                <select id="is_active" class="form-select">
                  <option value="1" <?php echo $item['is_active'] ? 'selected' : ''; ?>>AKTİF</option>
                  <option value="0" <?php echo !$item['is_active'] ? 'selected' : ''; ?>>PASİF</option>
                </select>
              </div>

            </div>

            <div class="mt-3 d-flex gap-2">
              <button id="btnSave" class="btn btn-primary">Kaydet</button>
              <a class="btn btn-outline-secondary" href="/php-mongo-erp/public/gdef/items.php?group=<?php echo urlencode($group); ?>">Vazgeç</a>
            </div>
          </div>

        </div>
        <div class="content-backdrop fade"></div>
      </div>
    </div>
  </div>
</div>

<script>
function showErr(msg){
  const b = document.getElementById('alertBox');
  b.textContent = msg || 'Kaydetme sırasında hata oluştu. Kaydedilmedi.';
  b.classList.remove('d-none');
}

document.getElementById('btnSave').addEventListener('click', async function(){
  const payload = {
    group: <?php echo json_encode($group); ?>,
    id: <?php echo json_encode($item['_id']); ?>,
    code: (document.getElementById('code').value || '').trim(),
    name: (document.getElementById('name').value || '').trim(),
    name2: (document.getElementById('name2').value || '').trim(),
    is_active: document.getElementById('is_active').value === '1'
  };

  try{
    const r = await fetch('/php-mongo-erp/public/api/gdef_item_save.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      credentials:'same-origin',
      body: JSON.stringify(payload)
    });
    const j = await r.json();
    if (!j || j.ok !== true){
      showErr((j && (j.detail || j.msg)) ? (j.detail || j.msg) : null);
      return;
    }
    window.location.href = '/php-mongo-erp/public/gdef/items.php?group=' + encodeURIComponent(<?php echo json_encode($group); ?>) + '&msg=' + encodeURIComponent('Kaydedildi');
  }catch(e){
    showErr();
  }
});

// === lock keep-alive (grup dokümanı) ===
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
