<?php
/**
 * public/stok/edit.php (FINAL)
 *
 * - Lock acquire (editing)
 * - Timeline VIEW + LOCK dedupe
 * - Save via /public/api/stok_save.php
 */

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/auth/SessionManager.php';
require_once __DIR__ . '/../../core/base/Context.php';
require_once __DIR__ . '/../../core/base/ContextException.php';

require_once __DIR__ . '/../../core/lock/LockManager.php';
require_once __DIR__ . '/../../core/timeline/TimelineService.php';

require_once __DIR__ . '/../../app/modules/stok/STOK01Repository.php';
require_once __DIR__ . '/../../app/modules/gdef/GDEF01TRepository.php';

SessionManager::start();

try { Context::bootFromSession(); }
catch (Throwable $e) { header('Location: /php-mongo-erp/public/login.php'); exit; }

$ctx = Context::get();
if (!is_array($ctx)) $ctx = [];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$id = trim((string)($_GET['id'] ?? ''));
$isEdit = ($id !== '' && strlen($id) === 24);

$model = ['code'=>'', 'name'=>'', 'name2'=>'', 'GDEF01_unit_code'=>'', 'unit'=>'', 'is_active'=>true, 'version'=>0];
$readOnly = false;
$lockMsg = null;

$unitRows = GDEF01TRepository::listActiveByGroup('unit', 1000);

if ($isEdit) {
  $tmp = STOK01Repository::dumpFull($id);
  if (!empty($tmp)) $model = array_merge($model, $tmp);

  $docNo = (string)($model['code'] ?? $id);
  $docTitle = (string)($model['name'] ?? '');

  $lockRes = LockManager::acquire([
    'module' => 'stok',
    'doc_type' => 'STOK01E',
    'doc_id' => $id,
    'doc_no' => ($docNo !== '' ? $docNo : null),
    'doc_title' => ($docTitle !== '' ? $docTitle : null),
    'doc_status' => ((bool)($model['is_active'] ?? true)) ? 'ACTIVE' : 'PASSIVE',
  ], 300, 'editing');

  if (is_array($lockRes) && ($lockRes['ok'] ?? false) && !($lockRes['acquired'] ?? false)) {
    $readOnly = true;
    $u = (string)($lockRes['lock']['context']['username'] ?? 'unknown');
    $st = (string)($lockRes['lock']['status'] ?? 'editing');
    $lockMsg = "Bu evrak başka kullanıcıda kilitli: {$u} ({$st})";
  }

  // Timeline dedupe
  $k = 'STOK01E:' . $id;
  $now = time();
  if (!isset($_SESSION['tl_seen'])) $_SESSION['tl_seen'] = [];
  $last = (int)($_SESSION['tl_seen'][$k] ?? 0);
  if (($now - $last) >= 30) {
    TimelineService::log('VIEW', 'STOK01E', $id, $docNo, $ctx, ['page'=>'stok/edit']);
    $_SESSION['tl_seen'][$k] = $now;
  }

  if (is_array($lockRes) && ($lockRes['ok'] ?? false) && ($lockRes['acquired'] ?? false)) {
    $lk = 'LOCK:' . $k;
    $ll = (int)($_SESSION['tl_seen'][$lk] ?? 0);
    if (($now - $ll) >= 30) {
      TimelineService::log('LOCK', 'STOK01E', $id, $docNo, $ctx, ['ttl'=>300,'status'=>'editing']);
      $_SESSION['tl_seen'][$lk] = $now;
    }
  }
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

          <style>
            .cardx{ background:#fff;border:1px solid rgba(0,0,0,.10);border-radius:16px;padding:14px;max-width:900px;margin:0 auto; }
            .rowx{ display:grid; grid-template-columns: 1fr 1fr; gap:12px; }
            @media(max-width:900px){ .rowx{ grid-template-columns:1fr; } }
            .in{ width:100%;height:42px;border:1px solid rgba(0,0,0,.12);border-radius:12px;padding:0 12px; }
            .btn{ height:42px;padding:0 14px;border-radius:12px;border:1px solid rgba(0,0,0,.12);background:#fff;color:#111;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:8px;cursor:pointer; }
            .btn-primary{ background:#1e88e5;border-color:#1e88e5;color:#fff; }
            .btn-danger{ background:#d32f2f;border-color:#d32f2f;color:#fff; }
            .btn-success{ background:#2e7d32;border-color:#2e7d32;color:#fff; }
            .muted{ color:rgba(0,0,0,.55);font-size:12px; }
            .topbar{ display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between;margin-bottom:12px; }
          </style>

          <div class="cardx">
            <div class="topbar">
              <div>
                <div style="font-size:20px;font-weight:800;">Stok Kartı</div>
                <div class="muted">
                  Firma: <b><?php echo h($ctx['CDEF01_id'] ?? ''); ?></b> |
                  Dönem: <b><?php echo h($ctx['PERIOD01T_id'] ?? ($ctx['period_id'] ?? '')); ?></b>
                </div>
              </div>
              <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <a class="btn" href="/php-mongo-erp/public/stok/index.php">← Liste</a>
              </div>
            </div>

            <?php if ($lockMsg): ?>
              <div class="muted" style="margin-bottom:10px;color:#d32f2f;"><b><?php echo h($lockMsg); ?></b></div>
            <?php endif; ?>

            <div class="rowx">
              <div>
                <div class="muted">Kod (Mongo: <b>code</b>)</div>
                <input class="in" id="code" value="<?php echo h($model['code'] ?? ''); ?>" <?php echo $readOnly?'disabled':''; ?>>
              </div>
              <div>
                <div class="muted">Birim (Mongo: <b>unit</b>)</div>
                <select class="in" id="unit_code" <?php echo $readOnly?'disabled':''; ?>>
                  <option value="">Seçiniz</option>
                  <?php foreach ($unitRows as $u):
                    $val = (string)($u['code'] ?? '');
                    $lbl = $val . ' - ' . (string)($u['name'] ?? '');
                    $sel = ($val !== '' && $val === (string)($model['GDEF01_unit_code'] ?? '')) ? 'selected' : '';
                  ?>
                    <option value="<?php echo h($val); ?>" <?php echo $sel; ?>><?php echo h($lbl); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div>
                <div class="muted">Ad (Mongo: <b>name</b>)</div>
                <input class="in" id="name" value="<?php echo h($model['name'] ?? ''); ?>" <?php echo $readOnly?'disabled':''; ?>>
              </div>

              <div>
                <div class="muted">Ad-2 (Mongo: <b>name2</b>)</div>
                <input class="in" id="name2" value="<?php echo h($model['name2'] ?? ''); ?>" <?php echo $readOnly?'disabled':''; ?>>
              </div>

              <div style="display:flex;gap:10px;align-items:center;">
                <input type="checkbox" id="is_active" <?php echo ((bool)($model['is_active'] ?? true))?'checked':''; ?> <?php echo $readOnly?'disabled':''; ?>>
                <label for="is_active">Aktif</label>
              </div>
            </div>

            <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;">
              <?php if (!$readOnly): ?>
                <button class="btn btn-primary" id="btnSave">Kaydet</button>
              <?php else: ?>
                <span class="btn" style="opacity:.5;pointer-events:none;">Kaydet</span>
              <?php endif; ?>
            </div>

          </div>

          <script>
            function toast(msg, type){
              if (window.showToast) { window.showToast(msg, type || 'info'); return; }
              alert(msg);
            }

            async function saveStock(){
              const payload = {
                STOK01_id: <?php echo $isEdit ? json_encode($id) : '""'; ?>,
                code: document.getElementById('code').value.trim(),
                name: document.getElementById('name').value.trim(),
                name2: document.getElementById('name2').value.trim(),
                GDEF01_unit_code: document.getElementById('unit_code').value.trim(),
                is_active: document.getElementById('is_active').checked
              };

              const res = await fetch('/php-mongo-erp/public/api/stok_save.php', {
                method: 'POST',
                headers: {'Content-Type':'application/json'},
                body: JSON.stringify(payload)
              });

              const j = await res.json().catch(()=>null);
              if (!j) { toast('Sunucu yanıtı okunamadı.', 'danger'); return; }

              if (j.ok) {
                toast('Kaydedildi.', 'success');
                if (!<?php echo $isEdit ? 'true' : 'false'; ?> && j.stok_id) {
                  location.href = '/php-mongo-erp/public/stok/edit.php?id=' + encodeURIComponent(j.stok_id);
                }
                return;
              }

              // validation
              if (j.detail === 'code_not_unique') {
                toast('Bu stok kodu zaten var. Kaydedilmedi.', 'danger');
                return;
              }
              if (j.detail === 'code_required') { toast('Kod zorunlu.', 'danger'); return; }
              if (j.detail === 'unit_required') { toast('Birim zorunlu.', 'danger'); return; }

              toast(j.msg || 'Kaydetme sırasında hata oluştu. Kaydedilmedi.', 'danger');
            }

            const btn = document.getElementById('btnSave');
            if (btn) btn.addEventListener('click', (e)=>{ e.preventDefault(); saveStock(); });
          </script>

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
