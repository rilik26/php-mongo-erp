<?php
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

require_once __DIR__ . '/../core/bootstrap.php';

require_once __DIR__ . '/../core/auth/SessionManager.php';
require_once __DIR__ . '/../core/base/Context.php';
require_once __DIR__ . '/../core/base/ContextException.php';

require_once __DIR__ . '/../core/auth/permission_helpers.php';
require_once __DIR__ . '/../core/action/ActionLogger.php';
require_once __DIR__ . '/../core/event/EventWriter.php';
require_once __DIR__ . '/../core/snapshot/SnapshotWriter.php';

require_once __DIR__ . '/../app/modules/lang/LANG01ERepository.php';
require_once __DIR__ . '/../app/modules/lang/LANG01TRepository.php';

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

// perm guard
if (function_exists('require_perm')) {
  require_perm('lang.manage');
}

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$tab = trim($_GET['tab'] ?? 'dict');
if (!in_array($tab, ['dict','langs'], true)) $tab = 'dict';

$q = trim($_GET['q'] ?? '');
$toast = trim($_GET['toast'] ?? '');

$msg = null;
$err = null;

// ---- helpers: LANG01E (fallback safe) ----
function lang_col(): MongoDB\Collection {
  return MongoManager::collection('LANG01E');
}

function fetch_langs_all(): array {
  // repo varsa kullan
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

  // fallback direct Mongo (existing record update)
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

  // tek default
  lang_col()->updateMany([], ['$set'=>['is_default'=>false]]);
  lang_col()->updateOne(['lang_code'=>$lc], ['$set'=>['is_default'=>true,'is_active'=>true]]);
}

function boolv($x): bool { return (bool)$x; }

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
      // 1) DB bulk upsert (boş/NEW_ skip)
      $stat = LANG01TRepository::bulkUpsertPivot($rows, $activeLangCodes);

      // 2) cache invalidation: aktif dillerin version++
      foreach ($activeLangCodes as $lc) bump_lang_version($lc);

      // SAVE log
      $saveLogId = ActionLogger::success('I18N.ADMIN.SAVE', [
        'source' => 'public/lang_admin.php',
        'rows' => count($rows),
        'saved_rows' => $stat['saved_rows'] ?? null,
        'skipped_rows' => $stat['skipped_rows'] ?? null,
      ], $ctx);

      // SNAPSHOT FINAL STATE (aktif dillerin tamamı)
      $dictDump = [];
      foreach ($activeLangCodes as $lc) {
        $dictDump[$lc] = LANG01TRepository::dumpAll($lc); // key => [module,key,text]
      }

      // snapshot rows: key -> module + langs
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
    }
  }

  // --------- LANG META SAVE (ADD/TOGGLE/DEFAULT) ----------
  if ($action === 'save_langs') {
    $lc = strtolower(trim((string)($_POST['lang_code'] ?? '')));
    if ($lc === '') {
      $err = 'Dil kodu boş olamaz.';
    } else {
      $name = trim((string)($_POST['name'] ?? strtoupper($lc)));
      $dir  = trim((string)($_POST['direction'] ?? 'ltr'));
      if (!in_array($dir, ['ltr','rtl'], true)) $dir = 'ltr';

      $isActive = (($_POST['is_active'] ?? '') === '1');
      $isDefault = (($_POST['is_default'] ?? '') === '1');

      // existing kaydı update et (yeni insert yerine upsert ama aynı lang_code ile)
      upsert_lang_meta($lc, [
        'name' => $name,
        'direction' => $dir,
        'is_active' => $isActive,
        'is_default' => $isDefault,
      ]);

      if ($isDefault) set_default_lang($lc);

      // aktif/pasif değişince da versiyon bump (cache refresh)
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

      // PRG -> dict'e dönmek istersen, ama burada langs tabında kalalım
      header('Location: /php-mongo-erp/public/lang_admin.php?tab=langs&toast=lang_saved');
      exit;
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
$lockModule  = 'i18n';
$lockDocType = 'LANG01T';
$lockDocId   = 'DICT';
$lockDocNo   = 'LANG01T-DICT';
$lockDocTitle = 'Language Dictionary';
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title><?php echo h('Lang Admin'); ?></title>

  <!-- DataTables CDN -->
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>

  <style>
    body{ font-family: Arial, sans-serif; }
    .tabs{ display:flex; gap:10px; margin: 10px 0; }
    .tabbtn{ padding:6px 10px; border:1px solid #ccc; border-radius:8px; text-decoration:none; color:#111; background:#fff; }
    .tabbtn.active{ border-color:#1e88e5; background:#1e88e5; color:#fff; }

    table { border-collapse: collapse; width: 100%; }
    th, td { border:1px solid #ddd; padding:8px; vertical-align: top; }
    th { background:#f7f7f7; text-align:left; }

    input[type="text"], select{
      width:100%; box-sizing:border-box;
      background:#fff; color:#111;
      border:1px solid #ddd; border-radius:6px;
      padding:6px 8px;
    }
    input[type="text"]:disabled{
      background:#f3f3f3; color:#777;
    }
    textarea{ width:100%; box-sizing:border-box; border:1px solid #ddd; border-radius:6px; padding:8px; min-height:120px; }

    .small { font-size:12px; color:#666; }
    .stickybar { display:flex; gap:10px; align-items:center; margin:10px 0; flex-wrap:wrap; }
    .btn { padding:6px 10px; border:1px solid #ccc; background:#fff; cursor:pointer; border-radius:6px; text-decoration:none; color:#111; }
    .btn-primary { border-color:#1e88e5; background:#1e88e5; color:#fff; }
    .btn-danger { border-color:#e53935; background:#e53935; color:#fff; }

    .lockbar{
      display:flex; gap:12px; align-items:center; margin:10px 0; flex-wrap:wrap;
      padding:8px 10px; border:1px solid #eee; border-radius:8px; background:#fafafa;
    }
    .badge{
      display:inline-block; padding:3px 8px; border-radius:999px; font-size:12px;
      background:#E3F2FD; color:#1565C0; font-weight:600;
    }
    .statusline{ font-size:12px; color:#333; }

    /* DataTables küçük dokunuşlar */
    div.dataTables_wrapper .dataTables_length select,
    div.dataTables_wrapper .dataTables_filter input{
      border:1px solid #ddd !important;
      border-radius:6px !important;
      padding:4px 6px !important;
    }

    .keyinput{ font-weight:600; }
    .muted{ color:#888; }
  </style>
</head>
<body>

<?php require_once __DIR__ . '/../app/views/layout/header.php'; ?>

<h3>Lang Admin</h3>

<div class="small">
  Kullanıcı: <b><?php echo h($ctx['username'] ?? ''); ?></b>
  &nbsp;|&nbsp; Firma: <b><?php echo h($ctx['CDEF01_id'] ?? ''); ?></b>
  &nbsp;|&nbsp; Dönem: <b><?php echo h($ctx['period_id'] ?? ''); ?></b>
</div>

<div class="tabs">
  <a class="tabbtn <?php echo $tab==='dict'?'active':''; ?>" href="/php-mongo-erp/public/lang_admin.php?tab=dict">Dictionary</a>
  <a class="tabbtn <?php echo $tab==='langs'?'active':''; ?>" href="/php-mongo-erp/public/lang_admin.php?tab=langs">Languages</a>
</div>

<div class="lockbar">
  <span class="badge">LOCK: editing</span>
  <span class="statusline" id="lockStatusText">Lock kontrol ediliyor…</span>
  <span class="statusline" id="saveStatusText"></span>
</div>

<?php if ($err): ?><p style="color:red;"><?php echo h($err); ?></p><?php endif; ?>

<?php if ($tab === 'dict'): ?>

  <form method="GET" class="stickybar">
    <input type="hidden" name="tab" value="dict">
    <label>Search:</label>
    <input type="text" name="q" value="<?php echo h($q); ?>" placeholder="key/text/module">
    <button class="btn" type="submit">Getir</button>
    <a class="btn" href="/php-mongo-erp/public/lang_admin.php?tab=dict">Sıfırla</a>
    <span class="small">Aktif diller kolonları belirler. Pasif diller “Languages” tabında görünür.</span>
  </form>

  <form method="POST" id="langForm">
    <input type="hidden" name="action" value="save_dict">

    <div class="stickybar">
      <button class="btn btn-primary" type="submit">Kaydet</button>
      <button class="btn" type="button" id="btnAddRow">Yeni Satır</button>
      <span class="small"><?php echo (int)count($pivot); ?> satır</span>
      <span class="small muted">Boş/NEW_ key kaydedilmez.</span>
    </div>

    <table id="langTable">
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
          $safeKey = h($key);
        ?>
          <tr>
            <td>
              <input type="text"
                     name="rows[<?php echo $safeKey; ?>][module]"
                     value="<?php echo h($module); ?>">
            </td>

            <td>
              <!-- key editable (yeni satırda da çalışacak) -->
              <input class="keyinput"
                     type="text"
                     name="rows[<?php echo $safeKey; ?>][key]"
                     value="<?php echo $safeKey; ?>">
            </td>

            <?php foreach ($activeLangCodes as $lc):
              $val = (string)($row[$lc] ?? '');
            ?>
              <td>
                <input type="text"
                       name="rows[<?php echo $safeKey; ?>][<?php echo h($lc); ?>]"
                       value="<?php echo h($val); ?>">
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="stickybar">
      <button class="btn btn-primary" type="submit">Kaydet</button>
    </div>
  </form>

<?php else: ?>

  <div class="small" style="margin:10px 0;">
    Pasif dil burada görünür, sadece Dictionary tabında kolon olarak çıkmaz.
  </div>

  <?php if ($toast === 'lang_saved'): ?>
    <p style="color:green;">✅ Dil bilgisi kaydedildi.</p>
  <?php endif; ?>

  <div class="grid" style="display:grid; grid-template-columns: 1fr 1fr; gap:14px;">
    <div style="border:1px solid #eee; border-radius:10px; padding:12px;">
      <h4 style="margin:0 0 8px;">Yeni Dil Ekle / Güncelle</h4>

      <form method="POST">
        <input type="hidden" name="action" value="save_langs">

        <div class="small">lang_code (örn: en, de, fr)</div>
        <input type="text" name="lang_code" placeholder="en">

        <div class="small" style="margin-top:8px;">name</div>
        <input type="text" name="name" placeholder="English">

        <div class="small" style="margin-top:8px;">direction</div>
        <select name="direction">
          <option value="ltr">ltr</option>
          <option value="rtl">rtl</option>
        </select>

        <div style="display:flex; gap:14px; margin-top:10px;">
          <label class="small"><input type="checkbox" name="is_active" value="1" checked> aktif</label>
          <label class="small"><input type="checkbox" name="is_default" value="1"> default</label>
        </div>

        <div style="margin-top:10px;">
          <button class="btn btn-primary" type="submit">Kaydet</button>
        </div>
      </form>
    </div>

    <div style="border:1px solid #eee; border-radius:10px; padding:12px;">
      <h4 style="margin:0 0 8px;">Mevcut Diller</h4>

      <table>
        <tr>
          <th style="width:90px;">code</th>
          <th>name</th>
          <th style="width:90px;">active</th>
          <th style="width:90px;">default</th>
          <th style="width:120px;">direction</th>
          <th style="width:160px;">action</th>
        </tr>
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
              <form method="POST" style="margin:0;">
                <input type="hidden" name="action" value="save_langs">
                <input type="hidden" name="lang_code" value="<?php echo h($lc); ?>">
                <input type="hidden" name="name" value="<?php echo h($name); ?>">
                <input type="hidden" name="direction" value="<?php echo h($dir); ?>">

                <input type="hidden" name="is_active" value="<?php echo $isActive ? '0':'1'; ?>">
                <input type="hidden" name="is_default" value="<?php echo $isDefault ? '1':'0'; ?>">

                <?php if ($isActive): ?>
                  <button class="btn btn-danger" type="submit">Pasif Yap</button>
                <?php else: ?>
                  <button class="btn btn-primary" type="submit">Aktif Yap</button>
                <?php endif; ?>
              </form>

              <?php if (!$isDefault): ?>
                <form method="POST" style="margin:6px 0 0;">
                  <input type="hidden" name="action" value="save_langs">
                  <input type="hidden" name="lang_code" value="<?php echo h($lc); ?>">
                  <input type="hidden" name="name" value="<?php echo h($name); ?>">
                  <input type="hidden" name="direction" value="<?php echo h($dir); ?>">
                  <input type="hidden" name="is_active" value="1">
                  <input type="hidden" name="is_default" value="1">
                  <button class="btn" type="submit">Default Yap</button>
                </form>
              <?php endif; ?>

            </td>
          </tr>
        <?php endforeach; ?>
      </table>

      <div class="small" style="margin-top:10px;">
        Not: Dil pasife alınca silinmez; burada görünür. Dictionary tabında kolon olarak gizlenir.
      </div>
    </div>
  </div>

<?php endif; ?>

<script>
(function(){
  const lockStatusText = document.getElementById('lockStatusText');
  const saveStatusText = document.getElementById('saveStatusText');

  function setSaveStatus(msg){
    if (saveStatusText) saveStatusText.textContent = msg || '';
  }

  const qs = new URLSearchParams(window.location.search);
  if ((qs.get('toast') || '') === 'save') {
    setSaveStatus('✅ Kaydedildi.');
  }

  // ---- auto lock (document-level) ----
  const module  = <?php echo json_encode($lockModule); ?>;
  const docType = <?php echo json_encode($lockDocType); ?>;
  const docId   = <?php echo json_encode($lockDocId); ?>;
  const docNo   = <?php echo json_encode($lockDocNo); ?>;
  const docTitle= <?php echo json_encode($lockDocTitle); ?>;

  let acquired = false;

  function setReadOnlyMode(isReadOnly){
    const form = document.getElementById('langForm');
    if (!form) return;

    const inputs = form.querySelectorAll('input, textarea, select, button');
    inputs.forEach(el => {
      const t = (el.getAttribute('type') || '').toLowerCase();
      if (t === 'hidden') return;
      el.disabled = !!isReadOnly;
    });
  }

  async function acquireLock(){
    try{
      const url = new URL('/php-mongo-erp/public/api/lock_acquire.php', window.location.origin);
      url.searchParams.set('module', module);
      url.searchParams.set('doc_type', docType);
      url.searchParams.set('doc_id', docId);
      url.searchParams.set('status', 'editing');
      url.searchParams.set('ttl', '900');
      url.searchParams.set('doc_no', docNo);
      url.searchParams.set('doc_title', docTitle);

      const r = await fetch(url.toString(), { method:'GET', credentials:'same-origin' });
      const j = await r.json();

      if (!j.ok) {
        acquired = false;
        setReadOnlyMode(true);
        if (lockStatusText) lockStatusText.textContent = 'Lock alınamadı: ' + (j.error || 'unknown');
        return;
      }

      acquired = !!j.acquired;

      if (acquired) {
        setReadOnlyMode(false);
        if (lockStatusText) lockStatusText.textContent = 'Kilit sende. (editing) — çıkınca otomatik bırakılacak.';
      } else {
        setReadOnlyMode(true);
        const who = j.lock?.context?.username ? (' (' + j.lock.context.username + ')') : '';
        if (lockStatusText) lockStatusText.textContent = 'Kilit başka bir kullanıcıda' + who + '. Read-only.';
      }

    } catch(e){
      acquired = false;
      setReadOnlyMode(true);
      if (lockStatusText) lockStatusText.textContent = 'Lock hatası: ' + e.message;
    }
  }

  function releaseBeacon(){
    if (!acquired) return;
    try{
      const url = new URL('/php-mongo-erp/public/api/lock_release_beacon.php', window.location.origin);
      const fd = new FormData();
      fd.append('module', module);
      fd.append('doc_type', docType);
      fd.append('doc_id', docId);
      navigator.sendBeacon(url.toString(), fd);
    } catch(e){}
  }

  window.addEventListener('beforeunload', releaseBeacon);

  // ---- DataTables + Yeni Satır ----
  function initDataTable(){
    if (!window.jQuery || !jQuery.fn || !jQuery.fn.DataTable) return null;
    const dt = jQuery('#langTable').DataTable({
      searching: false, // server q
      pageLength: 50,
      lengthMenu: [[25, 50, 100, 250, -1], [25, 50, 100, 250, "All"]],
      order: [[0,'asc'], [1,'asc']],
      stateSave: true,
      autoWidth: false,
      dom: 'lrtip'
    });
    return dt;
  }

  function addNewRow(){
    const tbody = document.querySelector('#langTable tbody');
    if (!tbody) return;

    const ts = Date.now();
    const tmpKey = 'NEW_' + ts;

    // kolon sayısı = module + key + aktif diller
    const activeLangCodes = <?php echo json_encode($activeLangCodes); ?>;

    const tr = document.createElement('tr');

    // module
    const tdM = document.createElement('td');
    tdM.innerHTML = `<input type="text" name="rows[${tmpKey}][module]" value="common">`;
    tr.appendChild(tdM);

    // key
    const tdK = document.createElement('td');
    tdK.innerHTML = `<input class="keyinput" type="text" name="rows[${tmpKey}][key]" value="" placeholder="yeni.key">`;
    tr.appendChild(tdK);

    // lang columns
    activeLangCodes.forEach(lc => {
      const td = document.createElement('td');
      td.innerHTML = `<input type="text" name="rows[${tmpKey}][${lc}]" value="">`;
      tr.appendChild(td);
    });

    tbody.prepend(tr);

    // DataTables varsa redraw
    try {
      if (window.jQuery && jQuery.fn && jQuery.fn.DataTable) {
        const dt = jQuery('#langTable').DataTable();
        dt.row.add(tr).draw(false);
      }
    } catch(e){}

    // focus key input
    const inp = tr.querySelector('input.keyinput');
    if (inp) inp.focus();

    setSaveStatus('🟡 Yeni satır eklendi. Key girip kaydedebilirsin.');
  }

  function validateBeforeSave(){
    const form = document.getElementById('langForm');
    if (!form) return true;

    const keyInputs = form.querySelectorAll('input[name$="[key]"]');
    let invalid = false;

    keyInputs.forEach(inp => {
      const v = (inp.value || '').trim();
      // boş key ve NEW_ key kaydedilmesin
      if (v === '' || v.startsWith('NEW_')) {
        inp.style.borderColor = '#e53935';
        invalid = true;
      } else {
        inp.style.borderColor = '';
      }
    });

    if (invalid) {
      setSaveStatus('❌ Boş veya geçici (NEW_) key olan satırlar kaydedilmez. Key gir.');
      return false;
    }
    return true;
  }

  // wire
  const btn = document.getElementById('btnAddRow');
  if (btn) btn.addEventListener('click', addNewRow);

  const form = document.getElementById('langForm');
  if (form) {
    form.addEventListener('submit', function(e){
      if (!validateBeforeSave()) e.preventDefault();
    });
  }

  // start
  acquireLock();
  if (document.getElementById('langTable')) initDataTable();

})();
</script>

</body>
</html>
