(function () {
  const el = document.getElementById('gdefItemEditLock');
  if (!el) return;

  const base = el.getAttribute('data-base') || '';
  const group = el.getAttribute('data-group') || '';
  const id = el.getAttribute('data-id') || '';

  // item edit ekranı için doc_id:
  // - edit ise: satır _id
  // - create ise: group adı (en azından locks’da görünür)
  const docId = id || group;
  if (!docId) return;

  fetch(base + '/public/api/lock_acquire.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      module: 'gdef',
      doc_type: 'GDEF01T',
      doc_id: docId,
      ttl: 120
    })
  }).catch(() => {});
})();
