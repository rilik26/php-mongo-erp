<?php
require_once __DIR__ . '/../app/views/layout/header.php';
/**
 * public/audit_view.php (FINAL)
 *
 * - Audit ekranı
 * - Filtre: module/doc_type/doc_id
 * - ✅ GENDOC zinciri: SNAP01E üzerinden V1→V2→V3 (tüm versiyonlar)
 * - Snapshot / Diff / Timeline linkleri
 * - BSONDocument -> array stabil
 * - facility filtre stabil (facility varsa: equal + null + missing)
 */

SessionManager::start();

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

ActionLogger::info('AUDIT.VIEW', [
  'source' => 'public/audit_view.php',
], $ctx);

// --- helpers (h/esc çakışma önlemi) ---
if (!function_exists('h')) {
  function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('esc')) {
  function esc($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

function bson_to_array($v) {
  if ($v instanceof MongoDB\Model\BSONDocument || $v instanceof MongoDB\Model\BSONArray) {
    $v = $v->getArrayCopy();
  }
  if ($v instanceof MongoDB\BSON\UTCDateTime) return $v->toDateTime()->format('c');
  if ($v instanceof MongoDB\BSON\ObjectId) return (string)$v;
  if (is_array($v)) {
    $out = [];
    foreach ($v as $k => $vv) $out[$k] = bson_to_array($vv);
    return $out;
  }
  return $v;
}

function fmt_tr($iso): string {
  if (!$iso) return '-';
  try {
    $dt = new DateTime((string)$iso);
    $dt->setTimezone(new DateTimeZone('Europe/Istanbul'));
    return $dt->format('d.m.Y H:i:s');
  } catch(Throwable $e) {
    return (string)$iso;
  }
}

// ---- input ----
$module  = trim($_GET['module'] ?? '');
$docType = trim($_GET['doc_type'] ?? '');
$docId   = trim($_GET['doc_id'] ?? '');

$limitEvents = (int)($_GET['limit'] ?? 50);
if ($limitEvents < 10) $limitEvents = 10;
if ($limitEvents > 200) $limitEvents = 200;

// ---- tenant scope ----
$cdef     = $ctx['CDEF01_id'] ?? null;
$period   = $ctx['period_id'] ?? null;
$facility = $ctx['facility_id'] ?? null;

/**
 * facility filtre stabil:
 * - facility yoksa filtreleme
 * - facility varsa: equal + null + missing birlikte göster
 */
function apply_facility_filter(array &$filter, $facility): void {
  if ($facility === null || $facility === '') return;

  if (isset($filter['$and']) && is_array($filter['$and'])) {
    $filter['$and'][] = [
      '$or' => [
        ['context.facility_id' => $facility],
        ['context.facility_id' => null],
        ['context.facility_id' => ['$exists' => false]],
      ]
    ];
    return;
  }

  $filter['$and'] = [
    [
      '$or' => [
        ['context.facility_id' => $facility],
        ['context.facility_id' => null],
        ['context.facility_id' => ['$exists' => false]],
      ]
    ]
  ];
}

// ---- snapshot list (chain) ----
$snapshots = [];
$snapErr = null;

if ($module !== '' && $docType !== '' && $docId !== '') {
  $snapFilter = [
    'target.module'   => $module,
    'target.doc_type' => $docType,
    'target.doc_id'   => $docId,
  ];

  if ($cdef) $snapFilter['context.CDEF01_id'] = $cdef;

  // period: current + GLOBAL
  if ($period) {
    $snapFilter['$or'] = [
      ['context.period_id' => $period],
      ['context.period_id' => 'GLOBAL'],
    ];
  }

  apply_facility_filter($snapFilter, $facility);

  try {
    $cur = MongoManager::collection('SNAP01E')->find(
      $snapFilter,
      [
        'sort' => ['version' => 1, 'created_at' => 1],
        'projection' => [
          '_id' => 1,
          'version' => 1,
          'created_at' => 1,
          'hash' => 1,
          'prev_snapshot_id' => 1,
          'target' => 1,
          'context.username' => 1,
        ],
        'limit' => 500,
      ]
    );
    $snapshots = array_map('bson_to_array', iterator_to_array($cur));
  } catch(Throwable $e) {
    $snapErr = $e->getMessage();
    $snapshots = [];
  }
}

// ---- events ----
$events = [];
$evErr = null;

if ($module !== '' && $docType !== '' && $docId !== '') {
  $evFilter = [
    'target.module'   => $module,
    'target.doc_type' => $docType,
    'target.doc_id'   => $docId,
  ];

  if ($cdef) $evFilter['context.CDEF01_id'] = $cdef;

  if ($period) {
    $evFilter['$or'] = [
      ['context.period_id' => $period],
      ['context.period_id' => 'GLOBAL'],
    ];
  }

  apply_facility_filter($evFilter, $facility);

  try {
    $curE = MongoManager::collection('EVENT01E')->find(
      $evFilter,
      [
        'sort' => ['created_at' => -1],
        'limit' => $limitEvents,
        'projection' => [
          '_id' => 1,
          'event_code' => 1,
          'created_at' => 1,
          'context.username' => 1,
          'data.summary' => 1,
          'refs' => 1,
          'target' => 1,
        ]
      ]
    );
    $events = array_map('bson_to_array', iterator_to_array($curE));
  } catch(Throwable $e) {
    $evErr = $e->getMessage();
    $events = [];
  }
}

function pick_target_meta(array $snapshots): array {
  foreach ($snapshots as $s) {
    $t = $s['target'] ?? [];
    if (!is_array($t)) continue;
    $docNo = (string)($t['doc_no'] ?? '');
    $title = (string)($t['doc_title'] ?? '');
    $status= (string)($t['status'] ?? '');
    if ($docNo !== '' || $title !== '' || $status !== '') {
      return ['doc_no'=>$docNo, 'doc_title'=>$title, 'status'=>$status];
    }
  }
  return ['doc_no'=>'', 'doc_title'=>'', 'status'=>''];
}

$meta = pick_target_meta($snapshots);

// ❌ ikinci header include kaldırıldı (zaten dosyanın en başında var)
// require_once __DIR__ . '/../app/views/layout/header.php';
?>

<body>
<div class="layout-wrapper layout-content-navbar">
  <div class="layout-container">

    <?php require_once __DIR__ . '/../app/views/layout/left.php'; ?>

    <div class="layout-page">

      <?php require_once __DIR__ . '/../app/views/layout/header2.php'; ?>

      <div class="content-wrapper">
        <div class="container-xxl flex-grow-1 container-p-y">

          <div class="row g-6">

            <div class="col-md-12">
              <div class="card card-border-shadow-primary">
                <div class="card-body">
                  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div>
                      <h4 class="mb-1">Audit View</h4>
                      <div class="small-muted">
                        Firma: <b><?php echo esc($ctx['CDEF01_id'] ?? ''); ?></b>
                        &nbsp;|&nbsp; Dönem: <b><?php echo esc($ctx['period_id'] ?? ''); ?></b>
                        &nbsp;|&nbsp; Kullanıcı: <b><?php echo esc($ctx['username'] ?? ''); ?></b>
                      </div>
                    </div>
                  </div>

                  <form method="GET" class="row g-3 mt-3">
                    <div class="col-md-3">
                      <label class="form-label">module</label>
                      <input class="form-control" name="module" value="<?php echo esc($module); ?>" placeholder="örn: gen">
                    </div>
                    <div class="col-md-3">
                      <label class="form-label">doc_type</label>
                      <input class="form-control" name="doc_type" value="<?php echo esc($docType); ?>" placeholder="örn: GENDOC01T">
                    </div>
                    <div class="col-md-3">
                      <label class="form-label">doc_id</label>
                      <input class="form-control" name="doc_id" value="<?php echo esc($docId); ?>" placeholder="örn: DOC-001">
                    </div>
                    <div class="col-md-3 d-flex gap-2 align-items-end">
                      <button class="btn btn-primary" type="submit">Getir</button>
                      <a class="btn btn-outline-primary" href="/php-mongo-erp/public/audit_view.php">Sıfırla</a>
                    </div>
                  </form>

                </div>
              </div>
            </div>

            <?php if ($module === '' || $docType === '' || $docId === ''): ?>

              <div class="col-md-12">
                <div class="alert alert-primary" role="alert">
                  module / doc_type / doc_id girip “Getir” de.
                </div>
              </div>

            <?php else: ?>

              <!-- TARGET CARD -->
              <div class="col-md-12">
                <div class="card">
                  <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                      <div>
                        <b>Target:</b>
                        <span class="code-pill"><?php echo esc($module); ?></span>
                        /
                        <span class="code-pill"><?php echo esc($docType); ?></span>
                        /
                        <span class="code-pill"><?php echo esc($docId); ?></span>
                      </div>
                      <div class="d-flex gap-2">
                        <a class="btn btn-outline-primary" target="_blank"
                           href="/php-mongo-erp/public/timeline.php?module=<?php echo urlencode($module); ?>&doc_type=<?php echo urlencode($docType); ?>&doc_id=<?php echo urlencode($docId); ?>">
                          Timeline
                        </a>
                      </div>
                    </div>

                    <div class="d-flex gap-2 flex-wrap mt-3">
                      <?php if (($meta['doc_no'] ?? '') !== ''): ?>
                        <span class="badge bg-label-primary">doc_no: <b><?php echo esc($meta['doc_no']); ?></b></span>
                      <?php endif; ?>
                      <?php if (($meta['doc_title'] ?? '') !== ''): ?>
                        <span class="badge bg-label-info">title: <b><?php echo esc($meta['doc_title']); ?></b></span>
                      <?php endif; ?>
                      <?php if (($meta['status'] ?? '') !== ''): ?>
                        <span class="badge bg-label-warning">status: <b><?php echo esc($meta['status']); ?></b></span>
                      <?php endif; ?>
                      <span class="badge bg-label-secondary">snapshots: <b><?php echo (int)count($snapshots); ?></b></span>
                    </div>
                  </div>
                </div>
              </div>

              <!-- SNAP CHAIN -->
              <div class="col-md-12">
                <div class="card">
                  <div class="card-header">
                    <h5 class="mb-0">Snapshot Zinciri (V1 → V2 → ...)</h5>
                  </div>
                  <div class="card-body">

                    <?php if ($snapErr): ?>
                      <div class="alert alert-danger">Snapshot query error: <?php echo esc($snapErr); ?></div>
                    <?php elseif (empty($snapshots)): ?>
                      <div class="alert alert-warning">Snapshot bulunamadı.</div>
                    <?php else: ?>

                      <div class="chainwrap">
                        <?php
                          $prevVer = null;
                          foreach ($snapshots as $i => $s):
                            $sid = (string)($s['_id'] ?? '');
                            $ver = (int)($s['version'] ?? ($i+1));
                            $created = fmt_tr($s['created_at'] ?? '');
                            $who = (string)($s['context']['username'] ?? '-');

                            $snapHref = '/php-mongo-erp/public/snapshot_view.php?snapshot_id=' . rawurlencode($sid);

                            if ($prevVer !== null && $sid) {
                              $diffHref = '/php-mongo-erp/public/snapshot_diff_view.php?snapshot_id=' . rawurlencode($sid);
                              echo '<span class="arrow">→ <a target="_blank" href="'.esc($diffHref).'">Diff (v'.(int)$prevVer.'→v'.(int)$ver.')</a></span>';
                            }
                        ?>
                            <span class="chainnode">
                              <a target="_blank" href="<?php echo esc($snapHref); ?>">
                                <b>V<?php echo (int)$ver; ?></b>
                              </a>
                              <span class="small-muted"><?php echo esc($created); ?></span>
                              <span class="small-muted">— <?php echo esc($who); ?></span>
                            </span>
                        <?php
                            $prevVer = $ver;
                          endforeach;
                        ?>
                      </div>

                      <div class="small-muted mt-3">
                        Not: Diff linki, “sonraki snapshot” üzerinden hesaplanır. (V2 linki V1→V2 diff’idir.)
                      </div>

                    <?php endif; ?>

                  </div>
                </div>
              </div>

              <!-- EVENTS -->
              <div class="col-md-12">
                <div class="card">
                  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h5 class="mb-0">Son Event’ler</h5>
                    <div class="small-muted">limit: <?php echo (int)$limitEvents; ?></div>
                  </div>
                  <div class="card-body">

                    <?php if ($evErr): ?>
                      <div class="alert alert-danger">Event query error: <?php echo esc($evErr); ?></div>
                    <?php elseif (empty($events)): ?>
                      <div class="alert alert-warning">Event bulunamadı.</div>
                    <?php else: ?>

                      <div class="table-responsive">
                        <table class="table table-bordered">
                          <thead>
                            <tr>
                              <th style="width:220px;">Zaman</th>
                              <th style="width:220px;">Kullanıcı</th>
                              <th style="width:260px;">event_code</th>
                              <th>Özet</th>
                              <th style="width:240px;">Link</th>
                            </tr>
                          </thead>
                          <tbody>
                          <?php foreach ($events as $ev):
                            $t = fmt_tr($ev['created_at'] ?? '');
                            $u = (string)($ev['context']['username'] ?? '-');
                            $code = (string)($ev['event_code'] ?? '');
                            $sum = $ev['data']['summary'] ?? null;

                            $sumTxt = '';
                            if (is_array($sum)) {
                              $sumTxt = json_encode($sum, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                            } elseif ($sum !== null) {
                              $sumTxt = (string)$sum;
                            }

                            $refs = (array)($ev['refs'] ?? []);
                            $logId = (string)($refs['log_id'] ?? '');
                            $snapId = (string)($refs['snapshot_id'] ?? '');
                          ?>
                            <tr>
                              <td class="small-muted"><?php echo esc($t); ?></td>
                              <td class="small-muted"><?php echo esc($u); ?></td>
                              <td><span class="code-pill"><?php echo esc($code); ?></span></td>
                              <td class="small-muted"><?php echo esc($sumTxt ?: '-'); ?></td>
                              <td class="small-muted">
                                <?php if ($logId): ?>
                                  <a target="_blank" href="/php-mongo-erp/public/log_view.php?log_id=<?php echo esc(urlencode($logId)); ?>">LOG</a>
                                <?php else: ?>
                                  LOG -
                                <?php endif; ?>
                                &nbsp;|&nbsp;
                                <?php if ($snapId): ?>
                                  <a target="_blank" href="/php-mongo-erp/public/snapshot_view.php?snapshot_id=<?php echo esc(urlencode($snapId)); ?>">SNAP</a>
                                <?php else: ?>
                                  SNAP -
                                <?php endif; ?>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>

                    <?php endif; ?>

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
</body>
</html>
