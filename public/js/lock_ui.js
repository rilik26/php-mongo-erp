/**
 * public/js/lock_ui.js
 *
 * Amaç:
 * - Evrak listelerinde lock icon (🔒) göstermek
 * - Lock sahibi / status / TTL bilgisi tooltip olarak göstermek
 * - DataTables satırına hızlıca entegre olmak
 *
 * Gereksinimler:
 * - /php-mongo-erp/public/api/lock_status.php endpointi (POST JSON)
 *   body: { module, doc_type, doc_ids: [docId1, docId2...] }
 *
 * Response beklenen (örnek):
 * { ok:true, locks: { "DICT": { locked:true, status:"editing", username:"admin", ttl_left_sec: 123, expires_at:"..." } } }
 */

(function(global){
  function esc(s){
    return String(s ?? '').replace(/[&<>"']/g, (m)=>({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[m]));
  }

  function fmtTTL(sec){
    if (sec === null || sec === undefined) return '';
    sec = Number(sec);
    if (!isFinite(sec)) return '';
    if (sec <= 0) return 'expired';

    var m = Math.floor(sec / 60);
    var r = sec % 60;
    return m + 'm ' + r + 's';
  }

  function statusLabel(st){
    st = (st || 'editing').toLowerCase();
    if (st === 'viewing') return 'VIEWING';
    if (st === 'approving') return 'APPROVING';
    return 'EDITING';
  }

  function statusColor(st){
    st = (st || 'editing').toLowerCase();
    if (st === 'viewing') return { bg:'#F1F8E9', fg:'#2E7D32' };
    if (st === 'approving') return { bg:'#FFF3E0', fg:'#EF6C00' };
    return { bg:'#E3F2FD', fg:'#1565C0' };
  }

  /**
   * Lock ikon HTML
   * - locked=false: boş
   * - locked=true: 🔒 + badge + tooltip
   */
  function renderLockIcon(lock){
    if (!lock || !lock.locked) return '';

    var who = lock.username ? ('@' + lock.username) : 'unknown';
    var st = lock.status || 'editing';
    var ttl = fmtTTL(lock.ttl_left_sec);
    var exp = lock.expires_at ? lock.expires_at : '';
    var col = statusColor(st);

    var tip = [
      'Locked: ' + who,
      'Status: ' + statusLabel(st),
      ttl ? ('TTL: ' + ttl) : '',
      exp ? ('Expires: ' + exp) : ''
    ].filter(Boolean).join(' | ');

    // küçük yuvarlak badge
    var badge = '<span style="display:inline-block;margin-left:6px;padding:2px 7px;border-radius:999px;font-size:11px;font-weight:700;background:'+col.bg+';color:'+col.fg+';">'+esc(statusLabel(st))+'</span>';

    return (
      '<span class="lock-icon" title="'+esc(tip)+'" ' +
      'style="display:inline-flex;align-items:center;gap:4px;cursor:help;">' +
        '<span style="font-size:14px;line-height:1;">🔒</span>' +
        '<span style="font-size:12px;color:#444;">'+esc(who)+'</span>' +
        badge +
      '</span>'
    );
  }

  /**
   * Lock map fetch: docId array -> {docId: lockInfo}
   */
  async function fetchLocksMap(opts){
    var module = opts.module;
    var docType = opts.doc_type;
    var docIds = Array.isArray(opts.doc_ids) ? opts.doc_ids : [];

    if (!module || !docType || docIds.length === 0) {
      return { ok:false, locks:{} };
    }

    var url = new URL('/php-mongo-erp/public/api/lock_status.php', window.location.origin);

    var r = await fetch(url.toString(), {
      method:'POST',
      credentials:'same-origin',
      headers:{ 'Content-Type':'application/json' },
      body: JSON.stringify({ module: module, doc_type: docType, doc_ids: docIds })
    });

    var j = await r.json();
    if (!j || !j.ok) return { ok:false, locks:{} };

    // normalize: her doc_id için lock objesi üret
    var out = {};
    var locks = j.locks || {};
    Object.keys(locks).forEach(function(k){
      var l = locks[k] || {};
      out[k] = {
        locked: !!l.locked,
        status: l.status || null,
        username: l.username || null,
        ttl_left_sec: (typeof l.ttl_left_sec === 'number') ? l.ttl_left_sec : null,
        expires_at: l.expires_at || null,
        session_id: l.session_id || null
      };
    });

    return { ok:true, locks: out };
  }

  /**
   * DataTables için pratik helper:
   * - tableSelector: '#tbl'
   * - dt: DataTable instance (varsa)
   * - getDocId: rowData -> doc_id
   * - module/doc_type: aynı liste için sabit
   * - lockColIndex: lock ikonunun yazılacağı column index (opsiyonel)
   */
  async function applyLocksToDataTable(cfg){
    var dt = cfg.dt; // DataTable instance
    if (!dt) throw new Error('applyLocksToDataTable: dt_required');

    var getDocId = cfg.getDocId;
    if (typeof getDocId !== 'function') throw new Error('applyLocksToDataTable: getDocId_required');

    var module = cfg.module;
    var docType = cfg.doc_type;

    // DataTables current page rows
    var rows = dt.rows({ page:'current' }).data().toArray();
    var ids = [];
    rows.forEach(function(r){
      var id = getDocId(r);
      if (id) ids.push(String(id));
    });

    if (ids.length === 0) return;

    var res = await fetchLocksMap({ module: module, doc_type: docType, doc_ids: ids });
    if (!res.ok) return;

    // satırları gez: lock HTML set et
    var locks = res.locks || {};
    var lockColIndex = (typeof cfg.lockColIndex === 'number') ? cfg.lockColIndex : null;

    dt.rows({ page:'current' }).every(function(){
      var rowData = this.data();
      var docId = String(getDocId(rowData) || '');
      var lock = locks[docId];

      var html = renderLockIcon(lock);

      if (lockColIndex !== null) {
        // direct cell update
        var cellNode = this.cell(this.index(), lockColIndex).node();
        if (cellNode) cellNode.innerHTML = html || '';
      } else if (typeof cfg.onRow === 'function') {
        // custom render callback
        cfg.onRow(this, rowData, lock, html);
      }
    });

    // küçük tooltip hover (native title yeterli ama istersek custom yapılır)
  }

  global.LockUI = {
    fetchLocksMap: fetchLocksMap,
    renderLockIcon: renderLockIcon,
    applyLocksToDataTable: applyLocksToDataTable
  };
})(window);
