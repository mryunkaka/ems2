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
  const waitNode = root.querySelector('[data-queue-wait]');
  const workerNoteNode = root.querySelector('[data-queue-worker-note]');
  const activeNode = root.querySelector('[data-queue-active]');
  const aheadNode = root.querySelector('[data-queue-ahead]');
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
      li.textContent = `${item.requester_name || 'Pengguna'} — ${item.feature_label || 'Permintaan AI'} (berjalan ${formatDuration(item.running_seconds)})`;
      activeNode.append(li);
    });
  };
  const formatDuration = (seconds) => {
    const total = Math.max(0, Math.floor(Number(seconds) || 0));
    if (total < 60) return `${total} detik`;
    const minutes = Math.floor(total / 60);
    const remainder = total % 60;
    return remainder ? `${minutes} menit ${remainder} detik` : `${minutes} menit`;
  };
  const renderAhead = (items) => {
    aheadNode.replaceChildren();
    if (!Array.isArray(items) || items.length === 0) {
      const li = document.createElement('li'); li.textContent = 'Tidak ada pekerjaan lain di depan Anda.'; aheadNode.append(li); return;
    }
    items.forEach((item) => {
      const li = document.createElement('li');
      li.textContent = `${item.requester_name || 'Pengguna'} — ${item.feature_label || 'Permintaan AI'} (menunggu ${formatDuration(item.waited_seconds)})`;
      aheadNode.append(li);
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
      waitNode.textContent = data.status === 'queued'
        ? `Sudah menunggu ${formatDuration(data.waited_seconds)}. Perkiraan giliran Anda mulai: sekitar ${formatDuration(data.estimated_wait_seconds)}.`
        : (data.status === 'running' ? `Sedang diproses selama ${formatDuration(data.job_running_seconds)}.` : '');
      workerNoteNode.hidden = !data.worker_delayed;
      if (data.worker_delayed) {
        const localHost = ['localhost', '127.0.0.1', '::1'].includes(window.location.hostname);
        workerNoteNode.textContent = localHost
          ? 'Belum ada worker yang memproses antrean lebih dari satu menit. Untuk memprosesnya di localhost, jalankan worker PHP dari folder proyek: C:\\Server\\php-8.5.12-nts-Win32-vs17-x64\\php.exe actions\\ai_queue_worker.php.'
          : 'Belum ada worker yang memproses antrean lebih dari satu menit. Periksa cron hosting dan log worker; cron hosting seharusnya menjalankan worker CLI setiap menit.';
      }
      renderAhead(data.queue_ahead);
      renderActive(data.active);
      if (data.status === 'completed') {
        root.querySelector('.ai-queue-progress').hidden = true;
        if (root.dataset.nextUrl) {
          statusNode.textContent = 'Selesai. Mengalihkan Anda ke halaman tujuan…';
          resultNode.hidden = true;
          continueNode.hidden = false;
          window.setTimeout(() => window.location.assign(root.dataset.nextUrl), 1200);
        } else if (data.result !== undefined) {
          resultNode.hidden = false;
          resultNode.textContent = typeof data.result === 'string' ? data.result : JSON.stringify(data.result, null, 2);
        }
        return;
      }
      if (data.status === 'failed') { root.querySelector('.ai-queue-progress').hidden = true; positionNode.textContent = data.error || 'Silakan coba kembali nanti.'; return; }
      await pause(delay); delay = Math.min(8000, delay * 1.35);
    }
  };
  poll();
})();
