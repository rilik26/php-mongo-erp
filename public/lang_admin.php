<?php
require_once __DIR__ . '/../app/views/layout/header.php';
/**
 * public/lang_admin.php (FINAL)
 *
 * TABLAR:
 * - tab=dict  : sözlük (aktif dillere göre kolon)
 * - tab=langs : dil yönetimi (aktif/pasif, default, yeni dil ekle)
 *
 * LOCK:
 * - sayfa açılınca auto acquire(editing)
 * - çıkınca beacon release
 *
 * SAVE:
 * - PRG ile redirect ?toast=save
 * - lockbar'da "Kaydedildi" göster
 */



SessionManager::start();

// login guard
if (!isset($_SESSION['context']) || !is_array($_SESSION['context'])) {
  header('Location: /php-mongo-erp/public/login.php');
  exit;
}

try {
  Context::bootFromSession();
} catch (ContextException $e) {
  header('Location: /php-mongo-erp/public/login.php');
  exit;
}

$ctx = Context::get();

if (function_exists('require_perm')) {
  require_perm('lang.manage');
}

// h() burada tanımlama! header2.php içinde olabilir.
// Yine de yoksa fallback:
if (!function_exists('h')) {
  function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

// ---- input ----
$tab = trim($_GET['tab'] ?? 'dict');
if (!in_array($tab, ['dict','langs'], true)) $tab = 'dict';

$q = trim($_GET['q'] ?? '');
$toast = trim($_GET['toast'] ?? '');

$err = null;

// ---- helpers: LANG01E fallback safe ----
function lang_col(): MongoDB\Collection {
  return MongoManager::collection('LANG01E');
}

function fetch_langs_all(): array {
  if (class_exists('LANG01ERepository') && method_exists('LANG01ERepository','listAll')) {
    $x = LANG01ERepository::listAll();
    return is_array($x) ? $x : [];
  }
  $cur = lang_col()->find([], ['sort'=>['is_default'=>-1,'lang_code'=>1]]);
  $out = [];
  foreach ($cur as $d) {
    if ($d instanceof MongoDB\Model\BSONDocument) $d = $d->getArrayCopy();
    $out[] = $d;
  }
  return $out;
}

function fetch_langs_active(): array {
  if (class_exists('LANG01ERepository') && method_exists('LANG01ERepository','listActive')) {
    $x = LANG01ERepository::listActive();
    return is_array($x) ? $x : [];
  }
  $cur = lang_col()->find(['is_active'=>true], ['sort'=>['is_default'=>-1,'lang_code'=>1]]);
  $out = [];
  foreach ($cur as $d) {
    if ($d instanceof MongoDB\Model\BSONDocument) $d = $d->getArrayCopy();
    $out[] = $d;
  }
  return $out;
}

function upsert_lang_meta(string $lc, array $set): void {
  $lc = strtolower(trim($lc));
  if ($lc === '') return;

  if (class_exists('LANG01ERepository') && method_exists('LANG01ERepository','upsertMeta')) {
    LANG01ERepository::upsertMeta($lc, $set);
    return;
  }

  $set['lang_code'] = $lc;
  $set['updated_at'] = new MongoDB\BSON\UTCDateTime((int)floor(microtime(true)*1000));

  lang_col()->updateOne(
    ['lang_code' => $lc],
    ['$set' => $set, '$setOnInsert' => ['created_at' => $set['updated_at'], 'version' => 1]],
    ['upsert' => true]
  );
}

function bump_lang_version(string $lc): void {
  $lc = strtolower(trim($lc));
  if ($lc === '') return;

  if (class_exists('LANG01ERepository') && method_exists('LANG01ERepository','bumpVersion')) {
    LANG01ERepository::bumpVersion($lc);
    return;
  }

  lang_col()->updateOne(
    ['lang_code'=>$lc],
    [
      '$inc' => ['version' => 1],
      '$set' => ['updated_at' => new MongoDB\BSON\UTCDateTime((int)floor(microtime(true)*1000))]
    ],
    ['upsert' => true]
  );
}

function set_default_lang(string $lc): void {
  $lc = strtolower(trim($lc));
  if ($lc === '') return;

  lang_col()->updateMany([], ['$set'=>['is_default'=>false]]);
  lang_col()->updateOne(['lang_code'=>$lc], ['$set'=>['is_default'=>true,'is_active'=>true]]);
}

// ---- view log + event ----
$viewLogId = ActionLogger::info('I18N.ADMIN.VIEW', [
  'source' => 'public/lang_admin.php',
  'tab' => $tab
], $ctx);

EventWriter::emit(
  'I18N.ADMIN.VIEW',
  ['source' => 'public/lang_admin.php', 'tab' => $tab],
  ['module' => 'i18n', 'doc_type' => 'LANG01T', 'doc_id' => 'DICT', 'doc_no' => 'LANG01T-DICT'],
  $ctx,
  ['log_id' => $viewLogId]
);

// ---- POST actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = trim($_POST['action'] ?? '');

  // --------- DICTIONARY SAVE ----------
  if ($action === 'save_dict') {
    $rows = $_POST['rows'] ?? [];
    if (!is_array($rows)) $rows = [];

    $activeLangMeta = fetch_langs_active();
    $activeLangCodes = [];
    foreach ($activeLangMeta as $li) {
      $lc = strtolower(trim((string)($li['lang_code'] ?? '')));
      if ($lc !== '') $activeLangCodes[] = $lc;
    }
    if (empty($activeLangCodes)) $activeLangCodes = ['tr'];

    if (empty($rows)) {
      $err = 'Kaydedilecek satır yok.';
    } else {
      try {
        $stat = LANG01TRepository::bulkUpsertPivot($rows, $activeLangCodes);

        foreach ($activeLangCodes as $lc) bump_lang_version($lc);

        $saveLogId = ActionLogger::success('I18N.ADMIN.SAVE', [
          'source' => 'public/lang_admin.php',
          'rows' => count($rows),
          'saved_rows' => $stat['saved_rows'] ?? null,
          'skipped_rows' => $stat['skipped_rows'] ?? null,
        ], $ctx);

        // snapshot dump (final state)
        $dictDump = [];
        foreach ($activeLangCodes as $lc) {
          $dictDump[$lc] = LANG01TRepository::dumpAll($lc);
        }

        $keys = [];
        foreach ($dictDump as $lc => $m) $keys = array_merge($keys, array_keys($m));
        $keys = array_values(array_unique($keys));
        sort($keys);

        $finalRows = [];
        foreach ($keys as $k) {
          $row = ['key' => $k, 'module' => 'common'];
          foreach ($activeLangCodes as $lc) $row[$lc] = '';
          foreach ($activeLangCodes as $lc) {
            if (isset($dictDump[$lc][$k])) {
              $row['module'] = $dictDump[$lc][$k]['module'] ?? $row['module'];
              $row[$lc] = $dictDump[$lc][$k]['text'] ?? '';
            }
          }
          $finalRows[$k] = $row;
        }

        $snap = SnapshotWriter::capture(
          [
            'module' => 'i18n',
            'doc_type' => 'LANG01T',
            'doc_id' => 'DICT',
            'doc_no' => 'LANG01T-DICT',
            'doc_title' => 'Language Dictionary',
          ],
          [
            'active_langs' => $activeLangCodes,
            'rows' => $finalRows
          ],
          [
            'reason' => 'bulk_upsert',
            'changed_fields' => ['rows'],
            'rows_count' => count($rows),
            'saved_rows' => $stat['saved_rows'] ?? null,
            'skipped_rows' => $stat['skipped_rows'] ?? null,
          ]
        );

        EventWriter::emit(
          'I18N.ADMIN.SAVE',
          [
            'source' => 'public/lang_admin.php',
            'summary' => [
              'mode' => 'lang',
              'changed_keys_count' => (int)($stat['saved_rows'] ?? 0),
              'skipped_rows' => (int)($stat['skipped_rows'] ?? 0),
              'skipped_keys_sample' => $stat['skipped_keys_sample'] ?? [],
            ],
          ],
          [
            'module' => 'i18n',
            'doc_type' => 'LANG01T',
            'doc_id' => 'DICT',
            'doc_no' => 'LANG01T-DICT',
            'doc_title' => 'Language Dictionary',
          ],
          $ctx,
          [
            'log_id' => $saveLogId,
            'snapshot_id' => $snap['snapshot_id'] ?? null,
            'prev_snapshot_id' => $snap['prev_snapshot_id'] ?? null,
          ]
        );

        // PRG
        $redir = '/php-mongo-erp/public/lang_admin.php?tab=dict&toast=save';
        if ($q !== '') $redir .= '&q=' . rawurlencode($q);
        header('Location: ' . $redir);
        exit;

      } catch(Throwable $e) {
        $err = 'Kaydetme hatası: ' . $e->getMessage();
      }
    }
  }

  // --------- LANG META SAVE ----------
  if ($action === 'save_langs') {
    $lc = strtolower(trim((string)($_POST['lang_code'] ?? '')));
    if ($lc === '') {
      $err = 'Dil kodu boş olamaz.';
    } else {
      try {
        $name = trim((string)($_POST['name'] ?? strtoupper($lc)));
        $dir  = trim((string)($_POST['direction'] ?? 'ltr'));
        if (!in_array($dir, ['ltr','rtl'], true)) $dir = 'ltr';

        $isActive  = (($_POST['is_active'] ?? '') === '1');
        $isDefault = (($_POST['is_default'] ?? '') === '1');

        upsert_lang_meta($lc, [
          'name' => $name,
          'direction' => $dir,
          'is_active' => $isActive,
          'is_default' => $isDefault,
        ]);

        if ($isDefault) set_default_lang($lc);

        bump_lang_version($lc);

        $saveLogId = ActionLogger::success('I18N.LANG.META.SAVE', [
          'source' => 'public/lang_admin.php',
          'lang_code' => $lc,
          'is_active' => $isActive,
          'is_default' => $isDefault,
        ], $ctx);

        EventWriter::emit(
          'I18N.LANG.META.SAVE',
          [
            'source' => 'public/lang_admin.php',
            'summary' => [
              'lang_code' => $lc,
              'name' => $name,
              'is_active' => $isActive,
              'is_default' => $isDefault,
            ],
          ],
          [
            'module' => 'i18n',
            'doc_type' => 'LANG01E',
            'doc_id' => $lc,
            'doc_no' => 'LANG-' . strtoupper($lc),
            'doc_title' => $name,
          ],
          $ctx,
          ['log_id' => $saveLogId]
        );

        header('Location: /php-mongo-erp/public/lang_admin.php?tab=langs&toast=lang_saved');
        exit;

      } catch(Throwable $e) {
        $err = 'Dil kaydetme hatası: ' . $e->getMessage();
      }
    }
  }
}

// ---- active langs decide columns ----
$allLangs = fetch_langs_all();
$activeLangs = fetch_langs_active();

$activeLangCodes = [];
foreach ($activeLangs as $li) {
  $lc = strtolower(trim((string)($li['lang_code'] ?? '')));
  if ($lc !== '') $activeLangCodes[] = $lc;
}
if (empty($activeLangCodes)) $activeLangCodes = ['tr'];

// ---- dict pivot ----
$pivot = [];
if ($tab === 'dict') {
  $pivot = LANG01TRepository::listPivot($activeLangCodes, $q, 1200);
}

// Lock target (document-level)
$lockModule   = 'i18n';
$lockDocType  = 'LANG01T';
$lockDocId    = 'DICT';
$lockDocNo    = 'LANG01T-DICT';
$lockDocTitle = 'Language Dictionary';


?>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<style>
  .tabbtn.active{ font-weight:800; text-decoration:underline; }
  #langTable input{ width:100%; box-sizing:border-box; }
</style>

<body>
<div class="layout-wrapper layout-content-navbar">
  <div class="layout-container">

    <?php require_once __DIR__ . '/../app/views/layout/left.php'; ?>

    <div class="layout-page">

      <?php require_once __DIR__ . '/../app/views/layout/header2.php'; ?>

      <div class="content-wrapper">
        <div class="container-xxl flex-grow-1 container-p-y">

          <div class="row g-6">

            <div class="col-md-4">
              <div class="card card-border-shadow-primary h-100">
                <div class="card-body">
                  <div class="d-flex align-items-center mb-2">
                    <h4 class="mb-0"><?php _e('system.menu.language.manager'); ?></h4>
                  </div>
                </div>
              </div>
            </div>

            <div class="col-md-4">
              <div class="card card-border-shadow-primary h-100">
                <div class="card-body">
                  <div class="d-flex align-items-center mb-2">
                    <h4 class="mb-0">
                      <a class="tabbtn <?php echo $tab==='dict'?'active':''; ?>"
                         href="/php-mongo-erp/public/lang_admin.php?tab=dict"><?php _e('language_manager_translations'); ?></a>
                    </h4>
                  </div>
                </div>
              </div>
            </div>

            <div class="col-md-4">
              <div class="card card-border-shadow-primary h-100">
                <div class="card-body">
                  <div class="d-flex align-items-center mb-2">
                    <h4 class="mb-0">
                      <a class="tabbtn <?php echo $tab==='langs'?'active':''; ?>"
                         href="/php-mongo-erp/public/lang_admin.php?tab=langs"><?php _e('language_manager_languages'); ?></a>
                    </h4>
                  </div>
                </div>
              </div>
            </div>

            <!-- LOCK BAR -->
            <div class="col-md-12">
              <div class="alert alert-outline-primary d-flex align-items-center flex-wrap row-gap-2" role="alert">
                <span class="alert-icon rounded">
                  <i class="icon-base ri ri-information-line icon-md"></i>
                </span>
                <span class="ms-2"><b>LOCK:</b> editing</span>
                <span class="statusline ms-2" id="lockStatusText">Lock kontrol ediliyor…</span>
                <span class="statusline ms-2" id="saveStatusText"></span>
              </div>

              <?php if ($toast === 'save'): ?>
                <div class="alert alert-outline-success d-flex align-items-center flex-wrap row-gap-2" role="alert">
                  <span class="alert-icon rounded">
                    <i class="icon-base ri ri-check-line icon-md"></i>
                  </span>
                  <span class="ms-2">✅ Kaydedildi.</span>
                </div>
              <?php endif; ?>

              <?php if ($toast === 'lang_saved'): ?>
                <div class="alert alert-outline-primary d-flex align-items-center flex-wrap row-gap-2" role="alert">
                  <span class="alert-icon rounded">
                    <i class="icon-base ri ri-check-line icon-md"></i>
                  </span>
                  <span class="ms-2">✅ Dil bilgisi kaydedildi.</span>
                </div>
              <?php endif; ?>

              <?php if ($err): ?>
                <div class="alert alert-outline-danger d-flex align-items-center flex-wrap row-gap-2" role="alert">
                  <span class="alert-icon rounded">
                    <i class="icon-base ri ri-alert-line icon-md"></i>
                  </span>
                  <span class="ms-2"><?php echo h($err); ?></span>
                </div>
              <?php endif; ?>
            </div>

            <?php if ($tab === 'dict'): ?>

              <div class="col-md-12">
                <div class="card">
                  <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Dictionary</h5>
                  </div>

                  <div class="card-body">

                    <form method="GET" class="mb-4">
                      <input type="hidden" name="tab" value="dict">
                      <div class="row g-3 align-items-end">
                        <div class="col-md-6">
                          <div class="form-floating form-floating-outline">
                            <input type="text" class="form-control" name="q" value="<?php echo h($q); ?>" placeholder="search">
                            <label>key/text/module</label>
                          </div>
                        </div>
                        <div class="col-md-6 d-flex gap-2">
                          <button class="btn btn-primary" type="submit">Getir</button>
                          <a class="btn btn-outline-primary" href="/php-mongo-erp/public/lang_admin.php?tab=dict">Sıfırla</a>
                        </div>
                      </div>

                      <div class="alert alert-primary mt-3" role="alert">
                        Aktif diller kolonları belirler. Pasif diller “Languages” tabında görünür.
                      </div>
                    </form>

                    <form method="POST" id="dictForm">
                      <input type="hidden" name="action" value="save_dict">

                      <div class="d-flex gap-2 mb-3">
                        <button class="btn btn-primary" type="submit">Kaydet</button>
                        <button class="btn btn-outline-primary" type="button" id="btnAddRow">Yeni Satır</button>
                        <div class="ms-auto alert alert-secondary mb-0 py-2">
                          <?php echo (int)count($pivot); ?> satır var. Boş/NEW_ key kaydedilmez.
                        </div>
                      </div>

                      <div class="table-responsive">
                        <table id="langTable" class="table table-bordered">
                          <thead>
                          <tr>
                            <th style="width:160px;">module</th>
                            <th style="width:260px;">key</th>
                            <?php foreach ($activeLangCodes as $lc): ?>
                              <th><?php echo h(strtoupper($lc)); ?></th>
                            <?php endforeach; ?>
                          </tr>
                          </thead>

                          <tbody>
                          <?php foreach ($pivot as $key => $row):
                            $module = (string)($row['module'] ?? 'common');
                            $safeKey = (string)$key;
                          ?>
                            <tr>
                              <td>
                                <input type="text" class="form-control"
                                       name="rows[<?php echo h($safeKey); ?>][module]"
                                       value="<?php echo h($module); ?>">
                              </td>

                              <td>
                                <input type="text" class="form-control keyinput"
                                       name="rows[<?php echo h($safeKey); ?>][key]"
                                       value="<?php echo h($safeKey); ?>">
                              </td>

                              <?php foreach ($activeLangCodes as $lc):
                                $val = (string)($row[$lc] ?? '');
                              ?>
                                <td>
                                  <input type="text" class="form-control"
                                         name="rows[<?php echo h($safeKey); ?>][<?php echo h($lc); ?>]"
                                         value="<?php echo h($val); ?>">
                                </td>
                              <?php endforeach; ?>
                            </tr>
                          <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>

                    </form>

                  </div>
                </div>
              </div>

            <?php else: ?>

              <div class="col-md-12">
                <div class="alert alert-primary" role="alert">
                  Pasif dil burada görünür, sadece Dictionary tabında kolon olarak çıkmaz.
                </div>
              </div>

              <div class="col-md-6">
                <div class="card">
                  <div class="card-header">
                    <h5 class="mb-0">Yeni Dil Ekle / Güncelle</h5>
                  </div>
                  <div class="card-body">
                    <form method="POST">
                      <input type="hidden" name="action" value="save_langs">

                      <div class="mb-3">
                        <label class="form-label">lang_code (örn: en, de, fr)</label>
                        <input type="text" class="form-control" name="lang_code" placeholder="en">
                      </div>

                      <div class="mb-3">
                        <label class="form-label">name</label>
                        <input type="text" class="form-control" name="name" placeholder="English">
                      </div>

                      <div class="mb-3">
                        <label class="form-label">direction</label>
                        <select class="form-select" name="direction">
                          <option value="ltr">ltr</option>
                          <option value="rtl">rtl</option>
                        </select>
                      </div>

                      <div class="d-flex gap-4 mb-3">
                        <label class="form-check">
                          <input class="form-check-input" type="checkbox" name="is_active" value="1" checked>
                          <span class="form-check-label">aktif</span>
                        </label>
                        <label class="form-check">
                          <input class="form-check-input" type="checkbox" name="is_default" value="1">
                          <span class="form-check-label">default</span>
                        </label>
                      </div>

                      <button class="btn btn-primary" type="submit">Kaydet</button>
                    </form>
                  </div>
                </div>
              </div>

              <div class="col-md-6">
                <div class="card">
                  <div class="card-header">
                    <h5 class="mb-0">Mevcut Diller</h5>
                  </div>
                  <div class="card-body table-responsive">
                    <table class="table table-bordered">
                      <thead>
                      <tr>
                        <th style="width:90px;">code</th>
                        <th>name</th>
                        <th style="width:90px;">active</th>
                        <th style="width:90px;">default</th>
                        <th style="width:120px;">direction</th>
                        <th style="width:180px;">action</th>
                      </tr>
                      </thead>
                      <tbody>
                      <?php foreach ($allLangs as $li):
                        if ($li instanceof MongoDB\Model\BSONDocument) $li = $li->getArrayCopy();
                        $lc = strtolower(trim((string)($li['lang_code'] ?? '')));
                        if ($lc === '') continue;
                        $name = (string)($li['name'] ?? strtoupper($lc));
                        $dir = (string)($li['direction'] ?? 'ltr');
                        $isActive = (bool)($li['is_active'] ?? false);
                        $isDefault = (bool)($li['is_default'] ?? false);
                      ?>
                        <tr>
                          <td><b><?php echo h($lc); ?></b></td>
                          <td><?php echo h($name); ?></td>
                          <td><?php echo $isActive ? '✅' : '—'; ?></td>
                          <td><?php echo $isDefault ? '⭐' : '—'; ?></td>
                          <td><?php echo h($dir); ?></td>
                          <td>
                            <form method="POST" style="display:inline-block; margin:0;">
                              <input type="hidden" name="action" value="save_langs">
                              <input type="hidden" name="lang_code" value="<?php echo h($lc); ?>">
                              <input type="hidden" name="name" value="<?php echo h($name); ?>">
                              <input type="hidden" name="direction" value="<?php echo h($dir); ?>">
                              <input type="hidden" name="is_active" value="<?php echo $isActive ? '0':'1'; ?>">
                              <input type="hidden" name="is_default" value="<?php echo $isDefault ? '1':'0'; ?>">
                              <?php if ($isActive): ?>
                                <button class="btn btn-danger btn-sm" type="submit">Pasif Yap</button>
                              <?php else: ?>
                                <button class="btn btn-primary btn-sm" type="submit">Aktif Yap</button>
                              <?php endif; ?>
                            </form>

                            <?php if (!$isDefault): ?>
                              <form method="POST" style="display:inline-block; margin:6px 0 0;">
                                <input type="hidden" name="action" value="save_langs">
                                <input type="hidden" name="lang_code" value="<?php echo h($lc); ?>">
                                <input type="hidden" name="name" value="<?php echo h($name); ?>">
                                <input type="hidden" name="direction" value="<?php echo h($dir); ?>">
                                <input type="hidden" name="is_active" value="1">
                                <input type="hidden" name="is_default" value="1">
                                <button class="btn btn-outline-secondary btn-sm" type="submit">Default Yap</button>
                              </form>
                            <?php endif; ?>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                      </tbody>
                    </table>

                    <div class="text-muted mt-2" style="font-size:12px;">
                      Not: Dil pasife alınca silinmez; burada görünür. Dictionary tabında kolon olarak gizlenir.
                    </div>
                  </div>
                </div>
              </div>

            <?php endif; ?>

          </div>
        </div>

        <div class="content-backdrop fade"></div>
      </div>

    </div>
  </div>

  <div class="layout-overlay layout-menu-toggle"></div>
  <div class="drag-target"></div>
</div>

<?php require_once __DIR__ . '/../app/views/layout/footer.php'; ?>

<!-- ✅ DataTables sadece bu sayfada -->
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>

<script>
(function(){

  // ✅ Kaydedildi mesajı
  const qs = new URLSearchParams(window.location.search);
  const toast = qs.get('toast') || '';
  const saveStatusText = document.getElementById('saveStatusText');
  if (saveStatusText) {
    if (toast === 'save') saveStatusText.textContent = '✅ Kaydedildi.';
    else saveStatusText.textContent = '';
  }

  // ✅ Yeni satır ekleme
  const btnAddRow = document.getElementById('btnAddRow');
  const tableBody = document.querySelector('#langTable tbody');

  if (btnAddRow && tableBody) {
    btnAddRow.addEventListener('click', function(){
      const ts = Date.now();
      const key = 'NEW_' + ts;

      let html = '';
      html += '<tr>';
      html += '<td><input class="form-control" type="text" name="rows['+key+'][module]" value="common"></td>';
      html += '<td><input class="form-control keyinput" type="text" name="rows['+key+'][key]" value="'+key+'"></td>';

      const langs = <?php echo json_encode(array_values($activeLangCodes)); ?>;
      langs.forEach(function(lc){
        html += '<td><input class="form-control" type="text" name="rows['+key+']['+lc+']" value=""></td>';
      });

      html += '</tr>';

      tableBody.insertAdjacentHTML('afterbegin', html);
      const firstKey = tableBody.querySelector('tr:first-child input.keyinput');
      if (firstKey) firstKey.focus();

    });
  }

  // ✅ DataTable
  if (window.jQuery && jQuery.fn && jQuery.fn.DataTable) {
    const t = document.getElementById('langTable');
    if (t && !t.classList.contains('dt-initialized')) {
      jQuery('#langTable').DataTable({
        searching: false,
        paging: true,
        pageLength: 50,
        lengthMenu: [[25,50,100,250,-1],[25,50,100,250,"All"]],
        order: [],
        autoWidth: false
      });
      t.classList.add('dt-initialized');
    }
  }

  // =========================
  // 🔒 LOCK
  // =========================
  const lockStatusText = document.getElementById('lockStatusText');

  const lockModule  = <?php echo json_encode($lockModule); ?>;
  const lockDocType = <?php echo json_encode($lockDocType); ?>;
  const lockDocId   = <?php echo json_encode($lockDocId); ?>;
  const lockDocNo   = <?php echo json_encode($lockDocNo); ?>;
  const lockDocTitle= <?php echo json_encode($lockDocTitle); ?>;

  let acquired = false;

  async function acquireLock(){
    const url = new URL('/php-mongo-erp/public/api/lock_acquire.php', window.location.origin);
    url.searchParams.set('module', lockModule);
    url.searchParams.set('doc_type', lockDocType);
    url.searchParams.set('doc_id', lockDocId);
    url.searchParams.set('status', 'editing');
    url.searchParams.set('ttl', '900');
    if (lockDocNo) url.searchParams.set('doc_no', lockDocNo);
    if (lockDocTitle) url.searchParams.set('doc_title', lockDocTitle);

    try{
      const r = await fetch(url.toString(), { method:'GET', credentials:'same-origin' });
      const j = await r.json();

      if (!j.ok) {
        acquired = false;
        if (lockStatusText) lockStatusText.textContent = 'Lock alınamadı: ' + (j.error || 'unknown');
        return;
      }

      acquired = !!j.acquired;

      if (acquired) {
        if (lockStatusText) lockStatusText.textContent = 'Kilit sende. (editing) — çıkınca otomatik bırakılacak.';
      } else {
        const who = j.lock?.context?.username ? (' (' + j.lock.context.username + ')') : '';
        if (lockStatusText) lockStatusText.textContent = 'Kilit başka bir kullanıcıda' + who + '.';
      }
    } catch(e){
      acquired = false;
      if (lockStatusText) lockStatusText.textContent = 'Lock hatası: ' + e.message;
    }
  }

  function releaseBeacon(){
    if (!acquired) return;

    const url = new URL('/php-mongo-erp/public/api/lock_release_beacon.php', window.location.origin);
    const fd = new FormData();
    fd.append('module', lockModule);
    fd.append('doc_type', lockDocType);
    fd.append('doc_id', lockDocId);

    try { navigator.sendBeacon(url.toString(), fd); } catch(e){}
  }

  window.addEventListener('beforeunload', releaseBeacon);
  acquireLock();

})();
</script>

</body>
</html>

