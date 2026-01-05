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
$ctx = Context::get();

function esc($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$id = trim((string)($_GET['id'] ?? ''));
if ($id !== '' && strlen($id) !== 24) $id = '';
$isEdit = ($id !== '');

$doc = $isEdit ? GDEF01ERepository::findById($id) : null;
if ($isEdit && !$doc) { header('Location: /php-mongo-erp/public/gdef/index.php'); exit; }

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
            <h4 style="margin:0;"><?php echo $isEdit ? 'Grup Düzenle' : 'Yeni Grup'; ?></h4>
            <a class="btn btn-outline-secondary" href="/php-mongo-erp/public/gdef/index.php">← Liste</a>
          </div>

          <div class="card mt-3">
            <div class="card-body">
              <input type="hidden" id="GDEF01E_id" value="<?php echo esc($id); ?>">

              <div class="row g-3">
                <div class="col-md-4">
                  <label class="form-label">Kod</label>
                  <input class="form-control" id="kod" value="<?php echo esc($doc['kod'] ?? ''); ?>">
                </div>
                <div class="col-md-4">
                  <label class="form-label">Ad</label>
                  <input class="form-control" id="name" value="<?php echo esc($doc['name'] ?? ''); ?>">
                </div>
                <div class="col-md-4">
                  <label class="form-label">Ad-2</label>
                  <input class="form-control" id="name2" value="<?php echo esc($doc['name2'] ?? ''); ?>">
                </div>
                <div class="col-md-4">
                  <label class="form-label">Durum</label>
                  <?php $active = (bool)($doc['is_active'] ?? true); ?>
                  <select class="form-select" id="is_active">
                    <option value="1" <?php echo $active ? 'selected' : ''; ?>>AKTİF</option>
                    <option value="0" <?php echo !$active ? 'selected' : ''; ?>>PASİF</option>
                  </select>
                </div>
              </div>

              <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:18px;">
                <button class="btn btn-primary" id="btnSave">Kaydet</button>
                <span class="text-muted" id="saveMsg" style="align-self:center;"></span>
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
<div id="gdefGroupEditApp"
     data-base="/php-mongo-erp"
     data-docid="<?php echo htmlspecialchars((string)($_GET['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
</div>
<script src="/php-mongo-erp/public/assets/js/gdef_group_edit.js?v=1"></script>

<?php require_once __DIR__ . '/../../app/views/layout/footer.php'; ?>

<script>
(function(){
  const idEl = document.getElementById('GDEF01E_id');
  const msg  = document.getElementById('saveMsg');

  function toast(t, ok){
    msg.textContent = t || "";
    msg.style.color = ok ? "green" : "crimson";
  }

  document.getElementById('btnSave').addEventListener('click', async function(){
    toast("", true);

    const payload = {
      GDEF01E_id: idEl.value || null,
      kod: document.getElementById('kod').value || "",
      name: document.getElementById('name').value || "",
      name2: document.getElementById('name2').value || "",
      is_active: (document.getElementById('is_active').value === "1")
    };

    try{
      const res = await fetch("/php-mongo-erp/public/api/gdef_group_save.php", {
        method:"POST",
        headers:{ "Content-Type":"application/json" },
        body: JSON.stringify(payload),
        credentials:"same-origin"
      });
      const j = await res.json();
      if(j && j.ok){
        toast("Kaydedildi.", true);
        if(j.id && !idEl.value){
          idEl.value = j.id;
          history.replaceState(null, "", "/php-mongo-erp/public/gdef/group_edit.php?id=" + encodeURIComponent(j.id));
        }
        return;
      }
      if(j && j.detail === "kod_not_unique"){
        toast("Bu grup kodu zaten var. Kaydedilmedi.", false);
        return;
      }
      toast((j && j.msg) ? j.msg : "Kaydetme sırasında hata oluştu. Kaydedilmedi.", false);
    }catch(e){
      toast("Kaydetme sırasında hata oluştu. Kaydedilmedi.", false);
    }
  });
})();
</script>
<script>
// === lock keep-alive (group edit) ===
(function(){
  const isEdit = true;
  const docId = <?php echo json_encode((string)($id ?? '')); ?>; // senin sayfandaki grup _id değişkeni
  if (!isEdit || !docId || docId.length !== 24) return;

  const module = 'gdef';
  const docType = 'GDEF01E';

  function getMeta(){
    const code = (document.querySelector('[name="code"]')?.value || '').trim();
    const name = (document.querySelector('[name="name"]')?.value || '').trim();
    const active = (document.querySelector('[name="is_active"]')?.value || '1') === '1';
    return { code, name, active };
  }

  async function ping(){
    try{
      const m = getMeta();
      const u = new URL('/php-mongo-erp/public/api/lock_acquire.php', window.location.origin);
      u.searchParams.set('module', module);
      u.searchParams.set('doc_type', docType);
      u.searchParams.set('doc_id', docId);
      u.searchParams.set('status', 'editing');
      u.searchParams.set('ttl', '300');
      if (m.code) u.searchParams.set('doc_no', m.code);
      if (m.name) u.searchParams.set('doc_title', m.name);
      u.searchParams.set('doc_status', m.active ? 'ACTIVE' : 'PASSIVE');
      await fetch(u.toString(), { method:'GET', credentials:'same-origin' });
    } catch(e){}
  }

  ping();
  setInterval(ping, 90000);
})();
</script>

</body>
</html>
