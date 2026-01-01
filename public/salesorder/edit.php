<?php
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/auth/SessionManager.php';
require_once __DIR__ . '/../../core/base/Context.php';
require_once __DIR__ . '/../../core/base/ContextException.php';

require_once __DIR__ . '/../../core/lock/LockService.php';
require_once __DIR__ . '/../../core/timeline/TimelineService.php';

require_once __DIR__ . '/../../app/modules/salesorder/SORDRepository.php';

SessionManager::start();

try { Context::bootFromSession(); }
catch (Throwable $e) {
  header('Location: /php-mongo-erp/public/login.php'); exit;
}

$ctx = Context::get();
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$id = trim((string)($_GET['id'] ?? ''));

// ✅ Yeni evrak: evrakno üretme YOK (refresh'te artmasın)
$model = ['header'=>['evrakno'=>'','customer'=>'','status'=>'DRAFT','version'=>0], 'lines'=>[]];

$isEdit = ($id !== '' && strlen($id) === 24);

if ($isEdit) {
  $tmp = SORDRepository::dumpFull($id);
  if (!empty($tmp)) $model = $tmp;

  $evrakno  = (string)($model['header']['evrakno'] ?? $id);
  $customer = (string)($model['header']['customer'] ?? '');

  // ✅ Sayfaya girince lock al/refresh et (doc_title=customer)
  LockService::acquireOrRefresh(
    'salesorder',
    'SORD01E',
    $id,
    ($evrakno !== '' ? $evrakno : null),
    ($customer !== '' ? $customer : null),
    $ctx,
    300,
    'editing'
  );

  // ✅ Timeline: refresh spam olmasın diye (session içinde) 30 sn dedupe
  $k = 'SORD01E:' . $id;
  $now = time();
  if (!isset($_SESSION['tl_seen'])) $_SESSION['tl_seen'] = [];
  $last = (int)($_SESSION['tl_seen'][$k] ?? 0);
  if (($now - $last) >= 30) {
    TimelineService::log('VIEW', 'SORD01E', $id, $evrakno, $ctx, ['page'=>'salesorder/edit']);
    $_SESSION['tl_seen'][$k] = $now;
  }

  // LOCK event: sadece ilk girişte bas (aynı dedupe ile)
  $lk = 'LOCK:' . $k;
  $ll = (int)($_SESSION['tl_seen'][$lk] ?? 0);
  if (($now - $ll) >= 30) {
    TimelineService::log('LOCK', 'SORD01E', $id, $evrakno, $ctx, ['ttl'=>300]);
    $_SESSION['tl_seen'][$lk] = $now;
  }
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    // DELETE (hard)
    if (!empty($_POST['delete']) && $_POST['delete'] === '1') {
      if (!$isEdit) throw new RuntimeException('delete_requires_id');
      SORDRepository::deleteHard($id, $ctx);
      LockService::release('salesorder', 'SORD01E', $id, $ctx);
      header('Location: /php-mongo-erp/public/salesorder/index.php');
      exit;
    }

    $header = [
      // ✅ evrakno boş olabilir; repository ilk SAVE'de üretir
      'evrakno'  => trim((string)($_POST['evrakno'] ?? '')),
      'customer' => trim((string)($_POST['customer'] ?? '')),
      'status'   => trim((string)($_POST['status'] ?? 'DRAFT')),
    ];

    // satırlar
    $lines = [];
    $names  = $_POST['item_name'] ?? [];
    $qtys   = $_POST['qty'] ?? [];
    $prices = $_POST['price'] ?? [];

    $n = max(count($names), count($qtys), count($prices));
    for ($i=0; $i<$n; $i++) {
      $name = trim((string)($names[$i] ?? ''));
      if ($name === '') continue;
      $lines[] = [
        'line_no'   => $i+1,
        'item_name' => $name,
        'qty'       => (float)($qtys[$i] ?? 0),
        'price'     => (float)($prices[$i] ?? 0),
      ];
    }

    $res = SORDRepository::save($header, $lines, $ctx, ($isEdit ? $id : null));
    $newId = (string)($res['SORD01_id'] ?? '');

    // ✅ Yeni kayıtta save sonrası lock al
    if ($newId !== '' && strlen($newId) === 24) {
      LockService::acquireOrRefresh(
        'salesorder',
        'SORD01E',
        $newId,
        (string)($res['evrakno'] ?? ''),
        (string)($header['customer'] ?? ''),
        $ctx,
        300,
        'editing'
      );
    }

    header('Location: /php-mongo-erp/public/salesorder/edit.php?id=' . urlencode($newId));
    exit;

  } catch (Throwable $e) {
    $error = $e->getMessage();
  }
}

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

          <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0">Satış Siparişi</h4>
            <div class="d-flex gap-2">
              <a class="btn btn-outline-secondary" href="/php-mongo-erp/public/salesorder/index.php">Liste</a>

              <?php if ($isEdit): ?>
              <form method="POST" onsubmit="return confirm('Silinsin mi? (hard delete)');" style="display:inline;">
                <input type="hidden" name="delete" value="1">
                <button class="btn btn-outline-danger" type="submit">Sil</button>
              </form>
              <?php endif; ?>
            </div>
          </div>

          <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo h($error); ?></div>
          <?php endif; ?>

          <form method="POST">
            <div class="card mb-3">
              <div class="card-body">

                <div class="row g-3">
                  <div class="col-md-4">
                    <label class="form-label">Evrak No</label>
                    <input class="form-control" name="evrakno"
                           value="<?php echo h($model['header']['evrakno'] ?? ''); ?>"
                           placeholder="Yeni kayıtta ilk kayıtta otomatik verilir">
                  </div>

                  <div class="col-md-5">
                    <label class="form-label">Müşteri</label>
                    <input class="form-control" name="customer"
                           value="<?php echo h($model['header']['customer'] ?? ''); ?>">
                  </div>

                  <div class="col-md-3">
                    <label class="form-label">Durum</label>
                    <select class="form-control" name="status">
                      <?php $st = (string)($model['header']['status'] ?? 'DRAFT'); ?>
                      <option value="DRAFT" <?php echo $st==='DRAFT'?'selected':''; ?>>DRAFT</option>
                      <option value="SAVED" <?php echo $st==='SAVED'?'selected':''; ?>>SAVED</option>
                      <option value="APPROVED" <?php echo $st==='APPROVED'?'selected':''; ?>>APPROVED</option>
                    </select>
                  </div>
                </div>

              </div>
            </div>

            <div class="card">
              <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0">Satırlar</h6>
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="addRow()">Satır Ekle</button>
              </div>
              <div class="card-body">
                <div class="table-responsive">
                  <table class="table" id="linesTbl">
                    <thead>
                      <tr>
                        <th>Ürün</th>
                        <th style="width:120px;">Miktar</th>
                        <th style="width:160px;">Fiyat</th>
                        <th style="width:60px;"></th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach (($model['lines'] ?? []) as $ln): ?>
                        <tr>
                          <td><input class="form-control" name="item_name[]" value="<?php echo h($ln['item_name'] ?? ''); ?>"></td>
                          <td><input class="form-control" name="qty[]" type="number" step="0.01" value="<?php echo h($ln['qty'] ?? 0); ?>"></td>
                          <td><input class="form-control" name="price[]" type="number" step="0.01" value="<?php echo h($ln['price'] ?? 0); ?>"></td>
                          <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()">X</button></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>

                <div class="mt-3 text-end">
                  <button class="btn btn-primary" type="submit">Kaydet</button>
                </div>

              </div>
            </div>
          </form>

        </div>
        <div class="content-backdrop fade"></div>
      </div>

    </div>
  </div>
</div>

<script>
function addRow(){
  const tb = document.querySelector('#linesTbl tbody');
  const tr = document.createElement('tr');
  tr.innerHTML = `
    <td><input class="form-control" name="item_name[]" /></td>
    <td><input class="form-control" name="qty[]" type="number" step="0.01" value="1" /></td>
    <td><input class="form-control" name="price[]" type="number" step="0.01" value="0" /></td>
    <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()">X</button></td>
  `;
  tb.appendChild(tr);
}
</script>

<?php require_once BASE_PATH . '/app/views/layout/footer.php'; ?>
</body>
</html>
