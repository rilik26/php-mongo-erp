/**
 * public/js/lock_datatables.js  (FINAL - BULK)
 *
 * Requires:
 * - DataTables instance (dt)
 * - your rows must have doc_id (or customize getDocId)
 *
 * Example:
 *  LockDTBulk.attach({
 *    table: dt,
 *    module: 'i18n',
 *    doc_type: 'LANG01T',
 *    getDocId: (row) => row.key, // e.g. if doc_id field is "key"
 *    renderInto: (tr) => tr.querySelector('.lock-cell')
 *  });
 */

window.LockDTBulk = (function(){
  const cache = new Map(); // doc_id -> {ts, info}
  const TTL_MS = 5000;

  function escHtml(s){
    return String(s ?? '').replace(/[&<>"']/g, (m)=>({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[m]));
  }

  function iconHTML(info){
    if (!info) return `<span title="Kilit yok" style="opacity:.35">🔓</span>`;

    const u = info.username || 'unknown';
    const st = info.status || 'editing';
    const exp = info.expires_at || '';
    const ttl = (typeof info.ttl_left_sec === 'number') ? `${info.ttl_left_sec}s` : '';
    const title = `Kilitli: ${u} (${st})\nexpires: ${exp}\nttl: ${ttl}`;
    return `<span title="${escHtml(title)}">🔒</span>`;
  }

  async function fetchBulk({ module, doc_type, doc_ids }){
    const url = new URL('/php-mongo-erp/public/api/lock_status.php', window.location.origin);

    const r = await fetch(url.toString(), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ module, doc_type, doc_ids })
    });

    const j = await r.json();
    if (!j || !j.ok) throw new Error(j?.error || 'lock_status_error');
    return j.locks || {};
  }

  function now(){ return Date.now(); }

  function isFresh(docId){
    const c = cache.get(docId);
    return c && (now() - c.ts) < TTL_MS;
  }

  function setCache(docId, info){
    cache.set(docId, { ts: now(), info: info || null });
  }

  function getCache(docId){
    const c = cache.get(docId);
    return c ? c.info : null;
  }

  async function paintCurrentPage({ table, module, doc_type, getDocId, renderInto }){
    const rows = [];
    const rowNodes = [];

    table.rows({ page:'current' }).every(function(){
      const rowData = this.data();
      const rowNode = this.node();

      const docId = getDocId(rowData);
      if (!docId) return;

      rows.push(String(docId));
      rowNodes.push({ rowNode, docId, rowData });
    });

    const uniqueDocIds = Array.from(new Set(rows)).filter(Boolean);

    // önce cache ile çiz (hızlı)
    rowNodes.forEach(({ rowNode, docId }) => {
      const cell = renderInto(rowNode);
      if (!cell) return;

      if (isFresh(docId)) {
        cell.innerHTML = iconHTML(getCache(docId));
      } else {
        cell.innerHTML = `<span title="Kontrol ediliyor…" style="opacity:.45">⏳</span>`;
      }
    });

    // stale olanları bulk çek
    const need = uniqueDocIds.filter(id => !isFresh(id));
    if (need.length === 0) return;

    let locksMap = {};
    try {
      locksMap = await fetchBulk({ module, doc_type, doc_ids: need });
    } catch (e) {
      // hata olursa sadece warning ikon bas
      rowNodes.forEach(({ rowNode, docId }) => {
        if (!need.includes(docId)) return;
        const cell = renderInto(rowNode);
        if (!cell) return;
        cell.innerHTML = `<span title="${escHtml('lock error: ' + e.message)}" style="opacity:.55">⚠</span>`;
      });
      return;
    }

    // cache yaz
    need.forEach(docId => {
      setCache(docId, locksMap[docId] || null);
    });

    // tekrar çiz
    rowNodes.forEach(({ rowNode, docId }) => {
      if (!need.includes(docId)) return;
      const cell = renderInto(rowNode);
      if (!cell) return;
      cell.innerHTML = iconHTML(getCache(docId));
    });
  }

  function attach({ table, module, doc_type, getDocId, renderInto }){
    if (!table) throw new Error('DataTable instance required');
    if (!module || !doc_type) throw new Error('module & doc_type required');
    if (typeof getDocId !== 'function') throw new Error('getDocId(row) function required');
    if (typeof renderInto !== 'function') throw new Error('renderInto(tr) function required');

    // draw hook
    table.on('draw', function(){
      paintCurrentPage({ table, module, doc_type, getDocId, renderInto });
    });

    // first paint
    table.draw(false);
  }

  return { attach };
})();
