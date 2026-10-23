(() => {
  const enqueueRoot = document.querySelector('[data-ai-queue-enqueue]');
  if (enqueueRoot) {
    const enqueueStatus = enqueueRoot.querySelector('[data-enqueue-status]');
    const retryButton = enqueueRoot.querySelector('[data-enqueue-retry]');
    const progress = enqueueRoot.querySelector('[data-enqueue-progress]');
    const submit = async () => {
      retryButton.hidden = true;
      enqueueStatus.textContent = 'Mendaftarkan pekerjaan di antrean…';
      try {
        const response = await fetch(enqueueRoot.dataset.submitUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': enqueueRoot.dataset.csrf }, body: JSON.stringify({ job_type: enqueueRoot.dataset.jobType, references: JSON.parse(enqueueRoot.dataset.references), return_to: enqueueRoot.dataset.returnTo }) });
        const data = await response.json();
        if (!response.ok || !data.ok || !data.wait_url) throw new Error(data.error || 'Pekerjaan belum dapat dimasukkan ke antrean.');
        window.location.assign(data.wait_url);
      } catch (error) {
        progress.hidden = true;
        enqueueStatus.textContent = error.message || 'Terjadi kendala saat menghubungkan ke antrean.';
        retryButton.hidden = false;
      }
    };
    retryButton.addEventListener('click', submit);
    submit();
    return;
  }
  const root = document.querySelector('[data-ai-queue]');
  if (!root) return;
  const statusNode = root.querySelector('[data-queue-status]');
  const positionNode = root.querySelector('[data-queue-position]');
  const activeNode = root.querySelector('[data-queue-active]');
  const resultNode = root.querySelector('[data-queue-result]');
  const continueNode = root.querySelector('[data-queue-continue]');
  const messages = { queued: 'Menunggu giliran di antrean…', running: 'Model AI sedang bekerja…', completed: 'Permintaan selesai.', failed: 'Permintaan tidak berhasil.' };
  const pause = (ms) => new Promise((resolve) => window.setTimeout(resolve, ms));
  const renderActive = (items) => {
    activeNode.replaceChildren();
    if (!Array.isArray(items) || items.length === 0) {
      const li = document.createElement('li'); li.textContent = 'Belum ada pekerjaan yang sedang berjalan.'; activeNode.append(li); return;
    }
    items.forEach((item) => {
      const li = document.createElement('li');
      li.textContent = `${item.requester_name || 'Pengguna'} — ${item.feature_label || 'Permintaan AI'}`;
      activeNode.append(li);
    });
  };
  const poll = async () => {
    let delay = 1800;
    for (;;) {
      let response;
      try { response = await fetch(root.dataset.statusUrl, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } }); }
      catch { statusNode.textContent = 'Koneksi terputus; mencoba kembali…'; await pause(delay); delay = Math.min(8000, delay * 1.5); continue; }
      if (response.status === 401 || response.status === 404) { statusNode.textContent = 'Sesi berakhir atau pekerjaan tidak dapat ditemukan. Muat ulang dan masuk kembali.'; return; }
      let data;
      try { data = await response.json(); } catch { data = null; }
      if (!response.ok || !data || !data.ok) { statusNode.textContent = data?.error || 'Status belum dapat dibaca; mencoba kembali…'; await pause(delay); delay = Math.min(8000, delay * 1.5); continue; }
      statusNode.textContent = messages[data.status] || 'Memeriksa status…';
      positionNode.textContent = data.status === 'queued' && data.position ? `Posisi antrean: ${data.position}` : '';
      renderActive(data.active);
      if (data.status === 'completed') {
        root.querySelector('.ai-queue-progress').hidden = true;
        if (data.result !== undefined) { resultNode.hidden = false; resultNode.textContent = typeof data.result === 'string' ? data.result : JSON.stringify(data.result, null, 2); }
        if (root.dataset.nextUrl) continueNode.hidden = false;
        return;
      }
      if (data.status === 'failed') { root.querySelector('.ai-queue-progress').hidden = true; positionNode.textContent = data.error || 'Silakan coba kembali nanti.'; return; }
      await pause(delay); delay = Math.min(8000, delay * 1.35);
    }
  };
  poll();
})();
