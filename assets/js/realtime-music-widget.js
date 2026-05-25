import { getApp, getApps, initializeApp } from 'https://www.gstatic.com/firebasejs/12.7.0/firebase-app.js';
import { getAuth, signInAnonymously } from 'https://www.gstatic.com/firebasejs/12.7.0/firebase-auth.js';
import {
    getDatabase,
    limitToLast,
    onValue,
    push,
    query,
    ref,
    remove,
    serverTimestamp,
    set,
    update
} from 'https://www.gstatic.com/firebasejs/12.7.0/firebase-database.js';

(function bootstrapRealtimeMusicWidget() {
    const config = window.EMS_REALTIME_MUSIC_CONFIG || null;
    const openButton = document.getElementById('emsLiveMusicBtn');
    const modal = document.getElementById('emsLiveMusicModal');

    if (!config || !config.enabled || !openButton || !modal) {
        return;
    }

    const closeButton = document.getElementById('emsLiveMusicClose');
    const closeBottomButton = document.getElementById('emsLiveMusicCloseBottom');
    const badge = document.getElementById('emsLiveMusicBadge');
    const queueCountEl = document.getElementById('emsLiveMusicQueueCount');
    const queueListEl = document.getElementById('emsLiveMusicQueue');
    const metaEl = document.getElementById('emsLiveMusicMeta');
    const nowLabelEl = document.getElementById('emsLiveMusicNowLabel');
    const nowMetaEl = document.getElementById('emsLiveMusicNowMeta');
    const primaryActionBtn = document.getElementById('emsLiveMusicPrimaryAction');
    const skipBtn = document.getElementById('emsLiveMusicSkip');
    const enableAudioBtn = document.getElementById('emsLiveMusicEnableAudio');
    const tutorialModal = document.getElementById('emsLiveMusicTutorialModal');
    const tutorialCloseBtn = document.getElementById('emsLiveMusicTutorialClose');
    const tutorialDismissBtn = document.getElementById('emsLiveMusicTutorialDismiss');
    const tutorialOpenBtn = document.getElementById('emsLiveMusicTutorialOpen');
    const form = document.getElementById('emsLiveMusicForm');
    const urlInput = document.getElementById('emsLiveMusicUrl');
    const formNoteEl = document.getElementById('emsLiveMusicFormNote');
    const audioEl = document.getElementById('emsLiveMusicAudio');
    const embedWrapEl = document.getElementById('emsLiveMusicEmbedWrap');
    const hintEl = document.getElementById('emsLiveMusicHint');

    const viewer = {
        id: String(config.viewer?.userId || '').trim(),
        name: String(config.viewer?.name || '').trim() || 'Visitor',
        role: String(config.viewer?.role || '').trim(),
        unit: String(config.viewer?.unit || '').trim()
    };

    const queuePath = String(config.paths?.queue || 'ems_live_music/global_room/queue');
    const statePath = String(config.paths?.state || 'ems_live_music/global_room/state');
    const maxQueueItems = Number(config.ui?.maxQueueItems || 25);
    const viewerScope = viewer.id || 'guest';
    const modalOpenKey = 'emsLiveMusicModalOpen';
    const cachedStateKey = 'emsLiveMusicStateCache';
    const cachedQueueKey = 'emsLiveMusicQueueCache';
    const localAudioEnabledKey = 'emsLiveMusicListeningEnabled:' + viewerScope;
    const localPausedKey = 'emsLiveMusicPausedLocally:' + viewerScope;
    const localUnlockedKey = 'emsLiveMusicUnlocked:' + viewerScope;
    const tutorialSeenKey = 'emsLiveMusicTutorialSeen:' + viewerScope;

    const app = getApps().length ? getApp() : initializeApp(config.firebase);
    const auth = getAuth(app);
    const database = getDatabase(app);

    let authUid = '';
    let queueItems = [];
    let currentState = null;
    let playerUnlocked = readStorage(localUnlockedKey) !== '0';
    let localListeningEnabled = readStorage(localAudioEnabledKey) !== '0';
    let localPlaybackPaused = readStorage(localPausedKey) === '1';
    let youtubePlayer = null;
    let youtubeApiPromise = null;
    let youtubeReady = false;
    let currentYoutubeVideoId = '';
    let syncingAudio = false;
    let queueLoaded = false;
    let stateLoaded = false;
    let pendingYoutubeState = null;
    let pendingUserPlayRequest = false;
    let pendingBootstrapQueueId = '';

    restoreCachedUi();
    setAudioUnlockUi();
    restoreModalState();
    if (currentState && currentState.queueId) {
        applyPlaybackState().catch(function () {
            return null;
        });
    }

    openButton.addEventListener('click', function () {
        openModal();
    });

    closeButton?.addEventListener('click', closeModal);
    closeBottomButton?.addEventListener('click', closeModal);
    tutorialCloseBtn?.addEventListener('click', dismissTutorialModal);
    tutorialDismissBtn?.addEventListener('click', dismissTutorialModal);
    tutorialOpenBtn?.addEventListener('click', function () {
        dismissTutorialModal();
        openModal();
    });

    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            closeModal();
        }
    });

    tutorialModal?.addEventListener('click', function (event) {
        if (event.target === tutorialModal) {
            dismissTutorialModal();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !modal.classList.contains('hidden')) {
            closeModal();
            return;
        }

        if (event.key === 'Escape' && tutorialModal && !tutorialModal.classList.contains('hidden')) {
            dismissTutorialModal();
        }
    });

    enableAudioBtn?.addEventListener('click', async function () {
        if (!playerUnlocked || !localListeningEnabled) {
            playerUnlocked = true;
            localListeningEnabled = true;
            localPlaybackPaused = false;
            pendingUserPlayRequest = true;
            writeStorage(localUnlockedKey, '1');
            writeStorage(localAudioEnabledKey, '1');
            writeStorage(localPausedKey, '0');
            setAudioUnlockUi();
            setLocalPlaybackUi();
            setFormNote('Audio live diaktifkan di browser ini.', false, true);
            await applyPlaybackState();
            return;
        }

        localListeningEnabled = false;
        localPlaybackPaused = false;
        pendingUserPlayRequest = false;
        writeStorage(localAudioEnabledKey, '0');
        writeStorage(localPausedKey, '0');
        pauseLocalPlayback();
        setAudioUnlockUi();
        setLocalPlaybackUi();
        setFormNote('Audio live dinonaktifkan di browser ini.', false, true);
    });

    primaryActionBtn?.addEventListener('click', async function () {
        if (!currentState || !currentState.queueId) {
            return;
        }

        if (!playerUnlocked) {
            playerUnlocked = true;
            writeStorage(localUnlockedKey, '1');
        }

        if (!localListeningEnabled) {
            localListeningEnabled = true;
            writeStorage(localAudioEnabledKey, '1');
        }

        if (!localPlaybackPaused && isLocallyPlaying()) {
            localPlaybackPaused = true;
            writeStorage(localPausedKey, '1');
            pauseLocalPlayback();
            setLocalPlaybackUi();
            setFormNote('Audio dijeda hanya di browser ini. Live music global tetap berjalan.', false, true);
            return;
        }

        localPlaybackPaused = false;
        writeStorage(localPausedKey, '0');
        await applyPlaybackState();
        setAudioUnlockUi();
        setLocalPlaybackUi();
        setFormNote('Audio diputar lagi di browser ini dan mengikuti waktu live terbaru.', false, true);
    });

    skipBtn?.addEventListener('click', async function () {
        await skipCurrentTrack();
    });

    form?.addEventListener('submit', async function (event) {
        event.preventDefault();

        if (!authUid) {
            setFormNote('Firebase masih menghubungkan. Coba lagi sebentar.', true, false);
            return;
        }

        const rawUrl = String(urlInput?.value || '').trim();
        const track = buildTrackPayload(rawUrl);

        if (!track) {
            setFormNote('Link tidak dikenali. Gunakan YouTube, Spotify, TikTok, atau URL audio direct.', true, false);
            return;
        }

        try {
            const itemRef = await push(ref(database, queuePath), {
                authUid: authUid,
                addedById: viewer.id || ('guest:' + authUid),
                addedByName: viewer.name,
                addedByRole: viewer.role,
                addedByUnit: viewer.unit,
                createdAt: serverTimestamp(),
                sourceType: track.sourceType,
                playbackType: track.playbackType,
                isPlayable: track.isPlayable,
                sourceLabel: track.sourceLabel,
                title: track.title,
                url: track.url,
                streamUrl: track.streamUrl,
                embedUrl: track.embedUrl,
                youtubeId: track.youtubeId
            });

            urlInput.value = '';
            setFormNote(track.isPlayable ? 'Masuk ke antrian live music.' : 'Masuk ke antrian sebagai link referensi.', false, true);

            if (!currentState || !currentState.queueId) {
                const queuedTrack = { ...track, id: itemRef.key, addedByName: viewer.name, addedByRole: viewer.role };
                await activateTrack(queuedTrack, 0);
            }
        } catch (error) {
            console.error('Failed to add live music queue item.', error);
            setFormNote(humanizeFirebaseError(error, 'Gagal menambahkan antrian music.'), true, false);
        }
    });

    queueListEl?.addEventListener('click', async function (event) {
        const actionButton = event.target.closest('[data-music-action]');
        if (!actionButton) {
            return;
        }

        const action = String(actionButton.getAttribute('data-music-action') || '');
        const itemId = String(actionButton.getAttribute('data-track-id') || '');
        const item = queueItems.find(function (entry) {
            return entry.id === itemId;
        });

        if (!item) {
            return;
        }

        if (action === 'play') {
            if (!item.isPlayable) {
                setFormNote('Track ini hanya link referensi. Gunakan audio direct atau YouTube untuk siaran live.', true, false);
                return;
            }

            await activateTrack(item, 0);
            return;
        }

        if (action === 'remove') {
            await removeQueueItem(item);
        }
    });

    audioEl?.addEventListener('loadedmetadata', function () {
        syncAudioPosition();
    });

    audioEl?.addEventListener('ended', function () {
        if (currentState && currentState.queueId) {
            skipCurrentTrack().catch(function () {
                return null;
            });
        }
    });

    window.addEventListener('pagehide', persistUiSnapshot);

    signInAnonymously(auth)
        .then(function (credential) {
            authUid = credential.user.uid;
            subscribeQueue();
            subscribeState();
        })
        .catch(function (error) {
            console.error('Anonymous Firebase auth failed for live music.', error);
            setFormNote(humanizeFirebaseError(error, 'Live music belum aktif. Cek konfigurasi Firebase.'), true, false);
            metaEl.textContent = 'Live music belum aktif.';
        });

    function openModal() {
        modal.classList.remove('hidden');
        document.body.classList.add('modal-open');
        window.sessionStorage.setItem(modalOpenKey, '1');
    }

    function closeModal() {
        modal.classList.add('hidden');
        syncBodyModalState();
        window.sessionStorage.setItem(modalOpenKey, '0');
    }

    function restoreModalState() {
        if (window.sessionStorage.getItem(modalOpenKey) === '1') {
            openModal();
        }
    }

    function openTutorialModal() {
        if (!tutorialModal) {
            return;
        }

        tutorialModal.classList.remove('hidden');
        document.body.classList.add('modal-open');
    }

    function dismissTutorialModal() {
        if (!tutorialModal) {
            return;
        }

        tutorialModal.classList.add('hidden');
        writeStorage(tutorialSeenKey, '1');
        syncBodyModalState();
    }

    function syncBodyModalState() {
        const hasVisibleModal =
            (modal && !modal.classList.contains('hidden')) ||
            (tutorialModal && !tutorialModal.classList.contains('hidden'));

        document.body.classList.toggle('modal-open', Boolean(hasVisibleModal));
    }

    function restoreCachedUi() {
        const cachedQueue = safeParseJSON(window.sessionStorage.getItem(cachedQueueKey));
        const cachedState = safeParseJSON(window.sessionStorage.getItem(cachedStateKey));

        if (Array.isArray(cachedQueue) && cachedQueue.length) {
            queueItems = cachedQueue;
            renderQueue(queueItems);
            updateQueueBadge(queueItems.length);
        } else {
            renderQueue([]);
        }

        if (cachedState && typeof cachedState === 'object') {
            currentState = cachedState;
            renderCurrentState(currentState);
        } else {
            renderCurrentState(null);
        }
    }

    function persistUiSnapshot() {
        try {
            window.sessionStorage.setItem(cachedQueueKey, JSON.stringify(queueItems.slice(0, maxQueueItems)));
            window.sessionStorage.setItem(cachedStateKey, JSON.stringify(currentState || null));
        } catch (error) {
            return;
        }
    }

    function subscribeQueue() {
        onValue(query(ref(database, queuePath), limitToLast(maxQueueItems)), function (snapshot) {
            const nextQueue = [];

            snapshot.forEach(function (childSnapshot) {
                const value = childSnapshot.val() || {};
                nextQueue.push({
                    id: childSnapshot.key,
                    title: sanitizeText(value.title || 'Tanpa judul'),
                    url: sanitizeText(value.url || ''),
                    streamUrl: sanitizeText(value.streamUrl || ''),
                    embedUrl: sanitizeText(value.embedUrl || ''),
                    youtubeId: sanitizeText(value.youtubeId || ''),
                    sourceType: sanitizeText(value.sourceType || 'link'),
                    playbackType: sanitizeText(value.playbackType || 'unsupported'),
                    sourceLabel: sanitizeText(value.sourceLabel || 'Link'),
                    isPlayable: Boolean(value.isPlayable),
                    addedByName: sanitizeText(value.addedByName || 'Visitor'),
                    addedByRole: sanitizeText(value.addedByRole || ''),
                    createdAt: Number(value.createdAt || 0)
                });
            });

            nextQueue.sort(function (left, right) {
                return (left.createdAt || 0) - (right.createdAt || 0);
            });

            queueItems = nextQueue;
            queueLoaded = true;
            renderQueue(nextQueue);
            updateQueueBadge(nextQueue.length);
            persistUiSnapshot();
            maybeBootstrapFirstPlayable();
        });
    }

    function subscribeState() {
        onValue(ref(database, statePath), function (snapshot) {
            currentState = snapshot.val() || null;
            stateLoaded = true;
            if (currentState && currentState.queueId) {
                pendingBootstrapQueueId = String(currentState.queueId);
            }
            renderCurrentState(currentState);
            persistUiSnapshot();
            maybeBootstrapFirstPlayable();

            maybeShowTutorialModal();
            applyPlaybackState().catch(function (error) {
                console.error('Failed to apply shared music state.', error);
            });
        });
    }

    function maybeBootstrapFirstPlayable() {
        if (!stateLoaded) {
            return;
        }

        if (currentState && currentState.queueId) {
            return;
        }

        if (!queueItems.length) {
            pendingBootstrapQueueId = '';
            return;
        }

        const firstPlayable = queueItems.find(function (item) {
            return item.isPlayable;
        });

        if (!firstPlayable) {
            pendingBootstrapQueueId = '';
            return;
        }

        if (pendingBootstrapQueueId === firstPlayable.id) {
            return;
        }

        pendingBootstrapQueueId = firstPlayable.id;
        activateTrack(firstPlayable, 0).catch(function () {
            pendingBootstrapQueueId = '';
            return null;
        });
    }

    async function activateTrack(item, positionMs) {
        if (!item || !item.id || !item.isPlayable) {
            return;
        }

        await set(ref(database, statePath), {
            queueId: item.id,
            title: item.title,
            url: item.url,
            streamUrl: item.streamUrl || '',
            embedUrl: item.embedUrl || '',
            youtubeId: item.youtubeId || '',
            sourceType: item.sourceType,
            playbackType: item.playbackType,
            sourceLabel: item.sourceLabel,
            status: 'playing',
            positionMs: Math.max(0, Number(positionMs || 0)),
            startedAt: Date.now(),
            addedByName: item.addedByName || viewer.name,
            addedByRole: item.addedByRole || viewer.role,
            activatedByName: viewer.name,
            updatedAt: serverTimestamp()
        });
    }

    async function skipCurrentTrack() {
        if (!currentState || !currentState.queueId) {
            return;
        }

        const currentQueueId = currentState.queueId;
        const nextPlayable = queueItems.find(function (item) {
            return item.id !== currentQueueId && item.isPlayable;
        });

        await remove(ref(database, queuePath + '/' + currentQueueId));

        if (nextPlayable) {
            await activateTrack(nextPlayable, 0);
            return;
        }

        await remove(ref(database, statePath));
    }

    async function removeQueueItem(item) {
        if (!item || !item.id) {
            return;
        }

        if (currentState && currentState.queueId === item.id) {
            await skipCurrentTrack();
            return;
        }

        await remove(ref(database, queuePath + '/' + item.id));
    }

    async function applyPlaybackState() {
        if (!audioEl || !embedWrapEl) {
            return;
        }

        if (!currentState || !currentState.queueId) {
            syncingAudio = true;
            audioEl.pause();
            audioEl.classList.add('hidden');
            audioEl.removeAttribute('src');
            audioEl.load();
            syncingAudio = false;
            clearEmbed();
            return;
        }

        if (currentState.playbackType === 'audio') {
            clearEmbed();
            audioEl.classList.remove('hidden');
            const nextSrc = String(currentState.streamUrl || currentState.url || '');
            if (!nextSrc) {
                return;
            }

            if (audioEl.getAttribute('src') !== nextSrc) {
                audioEl.src = nextSrc;
                audioEl.load();
            }

            syncAudioPosition();

            if (shouldPlayLocally()) {
                await attemptAudioPlayback(pendingUserPlayRequest);
            } else {
                audioEl.pause();
            }

            return;
        }

        if (currentState.playbackType === 'youtube') {
            audioEl.pause();
            audioEl.classList.add('hidden');
            audioEl.removeAttribute('src');
            audioEl.load();
            await syncYoutubePlayback();
            return;
        }

        audioEl.pause();
        audioEl.classList.add('hidden');
        audioEl.removeAttribute('src');
        audioEl.load();
        renderReferenceEmbed();
    }

    function renderCurrentState(state) {
        if (!nowLabelEl || !nowMetaEl || !primaryActionBtn || !skipBtn || !hintEl) {
            return;
        }

        if (!state || !state.queueId) {
            nowLabelEl.textContent = 'Belum ada musik aktif';
            nowMetaEl.textContent = 'Tambahkan link audio direct atau YouTube untuk mulai siaran.';
            primaryActionBtn.textContent = 'Putar';
            primaryActionBtn.disabled = true;
            skipBtn.disabled = true;
            setAudioUnlockUi();
            hintEl.textContent = 'Link Spotify atau TikTok akan masuk antrian sebagai referensi. Audio sinkron realtime saat ini hanya untuk audio direct dan YouTube.';
            return;
        }

        const statusLabel = state.status === 'playing' ? 'Sedang live' : 'Dijeda';
        const sourceLabel = state.sourceLabel || readableSourceLabel(state.sourceType);
        const byLine = compactUserLabel(state.addedByName, state.addedByRole);

        nowLabelEl.textContent = state.title || 'Tanpa judul';
        nowMetaEl.textContent = statusLabel + ' | ' + sourceLabel + ' | ditambahkan ' + byLine;
        primaryActionBtn.textContent = (!localListeningEnabled || localPlaybackPaused || state.status !== 'playing') ? 'Putar' : 'Jeda';
        primaryActionBtn.disabled = state.playbackType === 'unsupported';
        skipBtn.disabled = false;
        setAudioUnlockUi();

        if (state.playbackType === 'unsupported') {
            hintEl.textContent = 'Track ini hanya tampil sebagai referensi link. Gunakan audio direct atau YouTube untuk live sinkron.';
        } else if (!localListeningEnabled) {
            hintEl.textContent = 'Audio dimatikan hanya di browser ini. Device lain tetap mendengar live music.';
        } else if (localPlaybackPaused) {
            hintEl.textContent = 'Audio dijeda hanya di browser ini. Klik Putar untuk lompat ke waktu live terbaru.';
        } else if (playerUnlocked) {
            hintEl.textContent = 'Live music aktif otomatis di browser ini. Klik Audio Nonaktif jika ingin mematikan suara.';
        } else {
            hintEl.textContent = 'Live music akan mencoba aktif otomatis. Jika browser menahan autoplay, klik Audio Aktif.';
        }
    }

    function renderQueue(items) {
        if (!queueListEl || !queueCountEl) {
            return;
        }

        queueCountEl.textContent = items.length + ' item';

        if (!queueLoaded && !items.length) {
            queueListEl.innerHTML = '<div class="ems-live-music-empty">Memuat antrian music...</div>';
            return;
        }

        if (!items.length) {
            queueListEl.innerHTML = '<div class="ems-live-music-empty">Belum ada antrian music.</div>';
            return;
        }

        queueListEl.innerHTML = items.map(function (item) {
            const isLive = currentState && currentState.queueId === item.id;
            const itemClass = isLive ? 'ems-live-music-item is-live' : 'ems-live-music-item';
            const chipClass = isLive ? 'ems-live-music-chip is-live' : 'ems-live-music-chip';
            const supportChip = item.isPlayable
                ? '<span class="ems-live-music-chip">Sinkron</span>'
                : '<span class="ems-live-music-chip is-muted">Referensi</span>';
            const playDisabled = item.isPlayable ? '' : ' disabled';

            return '' +
                '<div class="' + itemClass + '">' +
                    '<div class="ems-live-music-item-main">' +
                        '<div class="ems-live-music-item-top">' +
                            '<div class="ems-live-music-item-title">' + escapeHtml(item.title) + '</div>' +
                            '<span class="' + chipClass + '">' + escapeHtml(isLive ? 'Live' : (item.sourceLabel || readableSourceLabel(item.sourceType))) + '</span>' +
                            supportChip +
                        '</div>' +
                        '<div class="ems-live-music-item-meta">Ditambahkan ' + escapeHtml(compactUserLabel(item.addedByName, item.addedByRole)) + '</div>' +
                        '<a class="ems-live-music-item-url" href="' + escapeHtml(item.url) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(item.url) + '</a>' +
                    '</div>' +
                    '<div class="ems-live-music-item-actions">' +
                        '<button type="button" class="ems-live-music-btn-mini" data-music-action="play" data-track-id="' + escapeHtml(item.id) + '"' + playDisabled + '>Putar</button>' +
                        '<button type="button" class="ems-live-music-btn-mini is-danger" data-music-action="remove" data-track-id="' + escapeHtml(item.id) + '">Hapus</button>' +
                    '</div>' +
                '</div>';
        }).join('');
    }

    function updateQueueBadge(count) {
        if (!badge) {
            return;
        }

        badge.textContent = String(count);
        badge.style.display = count > 0 ? 'inline-flex' : 'none';
    }

    function setAudioUnlockUi() {
        if (!enableAudioBtn) {
            return;
        }

        enableAudioBtn.textContent = playerUnlocked && localListeningEnabled ? 'Audio Nonaktif' : 'Audio Aktif';
        enableAudioBtn.classList.toggle('is-muted', playerUnlocked && !localListeningEnabled);
    }

    function maybeShowTutorialModal() {
        if (!currentState || !currentState.queueId || currentState.playbackType === 'unsupported' || currentState.status !== 'playing') {
            return;
        }

        if (readStorage(tutorialSeenKey) === '1') {
            return;
        }

        if (tutorialModal && tutorialModal.classList.contains('hidden')) {
            openTutorialModal();
        }
    }

    function setLocalPlaybackUi() {
        if (!primaryActionBtn || !currentState || !currentState.queueId) {
            return;
        }

        primaryActionBtn.textContent = shouldPlayLocally() ? 'Jeda' : 'Putar';
    }

    function shouldPlayLocally() {
        return Boolean(
            currentState &&
            currentState.queueId &&
            currentState.status === 'playing' &&
            playerUnlocked &&
            localListeningEnabled &&
            !localPlaybackPaused
        );
    }

    function isLocallyPlaying() {
        if (!currentState || currentState.status !== 'playing') {
            return false;
        }

        if (currentState.playbackType === 'audio' && audioEl) {
            return !audioEl.paused;
        }

        if (currentState.playbackType === 'youtube' && youtubePlayer && youtubeReady && window.YT && window.YT.PlayerState) {
            try {
                return youtubePlayer.getPlayerState() === window.YT.PlayerState.PLAYING;
            } catch (error) {
                return shouldPlayLocally();
            }
        }

        return shouldPlayLocally();
    }

    function pauseLocalPlayback() {
        if (audioEl) {
            audioEl.pause();
        }

        if (youtubePlayer && youtubeReady) {
            try {
                youtubePlayer.pauseVideo();
            } catch (error) {
                return;
            }
        }
    }

    function isAutoplayBlockedError(error) {
        const errorName = String(error?.name || '');
        return errorName === 'NotAllowedError' || errorName === 'SecurityError';
    }

    function isRetryablePlaybackError(error) {
        const errorName = String(error?.name || '');
        return errorName === 'AbortError' || errorName === 'NotSupportedError';
    }

    function waitForAudioReady(timeoutMs) {
        return new Promise(function (resolve) {
            if (!audioEl) {
                resolve(false);
                return;
            }

            let finished = false;
            let timer = null;
            const cleanup = function (result) {
                if (finished) {
                    return;
                }

                finished = true;
                audioEl.removeEventListener('canplay', onReady);
                audioEl.removeEventListener('loadeddata', onReady);
                audioEl.removeEventListener('canplaythrough', onReady);
                if (timer) {
                    window.clearTimeout(timer);
                }
                resolve(result);
            };
            const onReady = function () {
                cleanup(true);
            };

            audioEl.addEventListener('canplay', onReady, { once: true });
            audioEl.addEventListener('loadeddata', onReady, { once: true });
            audioEl.addEventListener('canplaythrough', onReady, { once: true });
            timer = window.setTimeout(function () {
                cleanup(false);
            }, timeoutMs);
        });
    }

    async function attemptAudioPlayback(retryOnPendingMedia) {
        if (!audioEl) {
            return;
        }

        try {
            await audioEl.play();
            pendingUserPlayRequest = false;
            return;
        } catch (error) {
            if (retryOnPendingMedia && isRetryablePlaybackError(error)) {
                const ready = await waitForAudioReady(1800);
                if (ready) {
                    try {
                        await audioEl.play();
                        pendingUserPlayRequest = false;
                        return;
                    } catch (retryError) {
                        error = retryError;
                    }
                }
            }

            if (isAutoplayBlockedError(error)) {
                console.warn('Browser blocked shared audio autoplay.', error);
                playerUnlocked = false;
                pendingUserPlayRequest = false;
                writeStorage(localUnlockedKey, '0');
                setAudioUnlockUi();
                setLocalPlaybackUi();
                setFormNote('Browser menahan autoplay. Klik Audio Aktif jika suara belum terdengar.', true, false);
                return;
            }

            console.warn('Failed to start shared audio playback.', error);
            if (retryOnPendingMedia) {
                setFormNote('Audio sedang dimuat. Jika masih hening, klik Audio Aktif sekali lagi.', true, false);
            }
        }
    }

    function setFormNote(message, isError, isSuccess) {
        if (!formNoteEl) {
            return;
        }

        formNoteEl.textContent = message;
        formNoteEl.classList.toggle('is-error', Boolean(isError));
        formNoteEl.classList.toggle('is-success', Boolean(isSuccess));
    }

    function buildTrackPayload(rawUrl) {
        if (!rawUrl) {
            return null;
        }

        const parsedUrl = parseUrl(rawUrl);
        if (!parsedUrl) {
            return null;
        }

        const normalizedTitle = sanitizeText(inferTitleFromUrl(parsedUrl));
        const hostname = parsedUrl.hostname.toLowerCase();
        const pathname = parsedUrl.pathname || '';
        const directAudioMatch = pathname.match(/\.((mp3)|(wav)|(ogg)|(m4a)|(aac)|(flac))$/i);

        if (hostname.includes('youtube.com') || hostname.includes('youtu.be')) {
            const videoId = extractYoutubeId(parsedUrl);
            if (!videoId) {
                return null;
            }

            return {
                title: normalizedTitle || ('YouTube ' + videoId),
                url: parsedUrl.href,
                sourceType: 'youtube',
                playbackType: 'youtube',
                sourceLabel: 'YouTube',
                isPlayable: true,
                streamUrl: '',
                youtubeId: videoId,
                embedUrl: 'https://www.youtube.com/embed/' + videoId + '?enablejsapi=1&playsinline=1&rel=0&modestbranding=1'
            };
        }

        if (directAudioMatch || /^audio\//i.test(guessMimeFromUrl(parsedUrl.href))) {
            return {
                title: normalizedTitle || inferTitleFromUrl(parsedUrl),
                url: parsedUrl.href,
                sourceType: 'audio',
                playbackType: 'audio',
                sourceLabel: 'Audio',
                isPlayable: true,
                streamUrl: parsedUrl.href,
                youtubeId: '',
                embedUrl: ''
            };
        }

        if (hostname.includes('spotify.com')) {
            return {
                title: normalizedTitle || inferTitleFromUrl(parsedUrl),
                url: parsedUrl.href,
                sourceType: 'spotify',
                playbackType: 'unsupported',
                sourceLabel: 'Spotify',
                isPlayable: false,
                streamUrl: '',
                youtubeId: '',
                embedUrl: ''
            };
        }

        if (hostname.includes('tiktok.com')) {
            return {
                title: normalizedTitle || inferTitleFromUrl(parsedUrl),
                url: parsedUrl.href,
                sourceType: 'tiktok',
                playbackType: 'unsupported',
                sourceLabel: 'TikTok',
                isPlayable: false,
                streamUrl: '',
                youtubeId: '',
                embedUrl: ''
            };
        }

        return {
            title: normalizedTitle || inferTitleFromUrl(parsedUrl),
            url: parsedUrl.href,
            sourceType: 'link',
            playbackType: 'unsupported',
            sourceLabel: 'Link',
            isPlayable: false,
            streamUrl: '',
            youtubeId: '',
            embedUrl: ''
        };
    }

    function syncAudioPosition() {
        if (!audioEl || !currentState || currentState.playbackType !== 'audio') {
            return;
        }

        const targetSeconds = computeTargetPositionMs(currentState) / 1000;
        if (!Number.isFinite(targetSeconds)) {
            return;
        }

        if (Math.abs((audioEl.currentTime || 0) - targetSeconds) > 1.2) {
            try {
                syncingAudio = true;
                audioEl.currentTime = Math.max(0, targetSeconds);
            } catch (error) {
                return;
            } finally {
                syncingAudio = false;
            }
        }
    }

    async function syncYoutubePlayback() {
        if (!embedWrapEl || !currentState || currentState.playbackType !== 'youtube') {
            return;
        }

        const videoId = String(currentState.youtubeId || '');
        if (!videoId) {
            return;
        }

        embedWrapEl.classList.remove('hidden');
        if (!embedWrapEl.querySelector('#emsLiveMusicYoutubePlayer')) {
            embedWrapEl.innerHTML =
                '<div class="ems-live-music-player-loading">Memuat live YouTube...</div>' +
                '<div id="emsLiveMusicYoutubePlayer" class="ems-live-music-youtube"></div>';
        }

        await ensureYoutubeApi();

        const targetSeconds = computeTargetPositionMs(currentState) / 1000;
        pendingYoutubeState = { videoId, targetSeconds };

        if (!youtubePlayer) {
            youtubePlayer = new window.YT.Player('emsLiveMusicYoutubePlayer', {
                videoId: videoId,
                playerVars: {
                    autoplay: 0,
                    controls: 1,
                    rel: 0,
                    modestbranding: 1,
                    playsinline: 1
                },
                events: {
                    onReady: function () {
                        youtubeReady = true;
                        currentYoutubeVideoId = videoId;
                        const loadingEl = embedWrapEl.querySelector('.ems-live-music-player-loading');
                        if (loadingEl) {
                            loadingEl.remove();
                        }
                        const stateToApply = pendingYoutubeState || { videoId, targetSeconds };
                        applyYoutubeState(stateToApply.videoId, stateToApply.targetSeconds).catch(function () {
                            return null;
                        });
                    },
                    onStateChange: function (event) {
                        if (event.data === window.YT.PlayerState.ENDED && currentState && currentState.queueId) {
                            skipCurrentTrack().catch(function () {
                                return null;
                            });
                        }
                    }
                }
            });
            return;
        }

        if (!youtubeReady) {
            return;
        }

        await applyYoutubeState(videoId, targetSeconds);
    }

    async function applyYoutubeState(videoId, targetSeconds) {
        if (!youtubePlayer || !youtubeReady) {
            return;
        }

        const shouldPlay = shouldPlayLocally();
        const safeSeconds = Math.max(0, Number(targetSeconds || 0));

        if (currentYoutubeVideoId !== videoId) {
            currentYoutubeVideoId = videoId;
            if (shouldPlay) {
                youtubePlayer.loadVideoById(videoId, safeSeconds);
            } else {
                youtubePlayer.cueVideoById(videoId, safeSeconds);
            }
            return;
        }

        try {
            youtubePlayer.seekTo(safeSeconds, true);
        } catch (error) {
            return;
        }

        if (shouldPlay) {
            youtubePlayer.playVideo();
            if (pendingUserPlayRequest) {
                window.setTimeout(function () {
                    try {
                        youtubePlayer.playVideo();
                    } catch (error) {
                        return;
                    }
                }, 150);
            }
            pendingUserPlayRequest = false;
        } else {
            youtubePlayer.pauseVideo();
        }
    }

    function ensureYoutubeApi() {
        if (window.YT && window.YT.Player) {
            return Promise.resolve(window.YT);
        }

        if (youtubeApiPromise) {
            return youtubeApiPromise;
        }

        youtubeApiPromise = new Promise(function (resolve) {
            const existingScript = document.getElementById('emsYoutubeIframeApi');
            if (!existingScript) {
                const script = document.createElement('script');
                script.id = 'emsYoutubeIframeApi';
                script.src = 'https://www.youtube.com/iframe_api';
                document.head.appendChild(script);
            }

            const previousReady = window.onYouTubeIframeAPIReady;
            window.onYouTubeIframeAPIReady = function () {
                if (typeof previousReady === 'function') {
                    previousReady();
                }
                resolve(window.YT);
            };
        });

        return youtubeApiPromise;
    }

    function clearEmbed() {
        if (!embedWrapEl) {
            return;
        }

        embedWrapEl.classList.add('hidden');
        embedWrapEl.innerHTML = '';

        if (youtubePlayer) {
            try {
                youtubePlayer.destroy();
            } catch (error) {
                return;
            } finally {
                youtubePlayer = null;
                youtubeReady = false;
                currentYoutubeVideoId = '';
            }
        }
    }

    function renderReferenceEmbed() {
        if (!embedWrapEl || !currentState) {
            return;
        }

        clearEmbed();
        embedWrapEl.classList.remove('hidden');
        embedWrapEl.innerHTML =
            '<div class="ems-live-music-embed-frame">' +
                '<div class="ems-live-music-empty">' +
                    'Track ini tersimpan sebagai referensi link dan tidak bisa diputar sinkron langsung dari browser. ' +
                    '<a class="ems-live-music-item-url" href="' + escapeHtml(currentState.url || '') + '" target="_blank" rel="noopener noreferrer">Buka link sumber</a>' +
                '</div>' +
            '</div>';
    }

    function computeTargetPositionMs(state) {
        if (!state) {
            return 0;
        }

        const base = Math.max(0, Number(state.positionMs || 0));
        if (state.status !== 'playing') {
            return base;
        }

        const startedAt = Number(state.startedAt || 0);
        if (!startedAt) {
            return base;
        }

        return Math.max(0, base + (Date.now() - startedAt));
    }

    function getCurrentPlaybackPosition() {
        if (!currentState) {
            return 0;
        }

        if (currentState.playbackType === 'audio' && audioEl) {
            return Math.max(0, Math.floor((audioEl.currentTime || 0) * 1000));
        }

        if (currentState.playbackType === 'youtube' && youtubePlayer && youtubeReady) {
            try {
                return Math.max(0, Math.floor(Number(youtubePlayer.getCurrentTime() || 0) * 1000));
            } catch (error) {
                return computeTargetPositionMs(currentState);
            }
        }

        return computeTargetPositionMs(currentState);
    }

    function compactUserLabel(name, role) {
        const safeName = sanitizeText(name || 'Visitor');
        const safeRole = sanitizeText(role || '');
        return safeRole ? (safeName + ' | ' + safeRole) : safeName;
    }

    function readableSourceLabel(sourceType) {
        const source = String(sourceType || '').toLowerCase();
        if (source === 'audio') {
            return 'Audio';
        }
        if (source === 'youtube') {
            return 'YouTube';
        }
        if (source === 'spotify') {
            return 'Spotify';
        }
        if (source === 'tiktok') {
            return 'TikTok';
        }
        return 'Link';
    }

    function parseUrl(value) {
        try {
            return new URL(value);
        } catch (error) {
            return null;
        }
    }

    function safeParseJSON(rawValue) {
        if (!rawValue) {
            return null;
        }

        try {
            return JSON.parse(rawValue);
        } catch (error) {
            return null;
        }
    }

    function readStorage(key) {
        try {
            return window.localStorage.getItem(key);
        } catch (error) {
            try {
                return window.sessionStorage.getItem(key);
            } catch (nestedError) {
                return null;
            }
        }
    }

    function writeStorage(key, value) {
        try {
            window.localStorage.setItem(key, value);
            return;
        } catch (error) {
            try {
                window.sessionStorage.setItem(key, value);
            } catch (nestedError) {
                return;
            }
        }
    }

    function extractYoutubeId(url) {
        if (!url) {
            return '';
        }

        const hostname = url.hostname.toLowerCase();
        if (hostname.includes('youtu.be')) {
            return url.pathname.replace(/^\/+/, '').split('/')[0] || '';
        }

        const videoParam = url.searchParams.get('v');
        if (videoParam) {
            return videoParam;
        }

        const pathSegments = url.pathname.split('/').filter(Boolean);
        const shortsIndex = pathSegments.indexOf('shorts');
        if (shortsIndex >= 0 && pathSegments[shortsIndex + 1]) {
            return pathSegments[shortsIndex + 1];
        }

        const embedIndex = pathSegments.indexOf('embed');
        if (embedIndex >= 0 && pathSegments[embedIndex + 1]) {
            return pathSegments[embedIndex + 1];
        }

        return '';
    }

    function inferTitleFromUrl(url) {
        if (!url) {
            return 'Tanpa judul';
        }

        const hostname = String(url.hostname || '').toLowerCase();
        if (hostname.includes('youtube.com') || hostname.includes('youtu.be')) {
            const videoId = extractYoutubeId(url);
            return videoId ? ('YouTube ' + videoId) : 'YouTube';
        }

        if (hostname.includes('spotify.com')) {
            const segment = (url.pathname || '').split('/').filter(Boolean).pop() || 'Spotify';
            return 'Spotify ' + decodeURIComponent(segment);
        }

        if (hostname.includes('tiktok.com')) {
            const segment = (url.pathname || '').split('/').filter(Boolean).pop() || 'TikTok';
            return 'TikTok ' + decodeURIComponent(segment);
        }

        const lastSegment = (url.pathname || '').split('/').filter(Boolean).pop() || url.hostname;
        return decodeURIComponent(lastSegment).replace(/[-_]+/g, ' ').trim() || 'Tanpa judul';
    }

    function guessMimeFromUrl(url) {
        const value = String(url || '').toLowerCase();
        if (value.endsWith('.mp3')) return 'audio/mpeg';
        if (value.endsWith('.wav')) return 'audio/wav';
        if (value.endsWith('.ogg')) return 'audio/ogg';
        if (value.endsWith('.m4a')) return 'audio/mp4';
        if (value.endsWith('.aac')) return 'audio/aac';
        if (value.endsWith('.flac')) return 'audio/flac';
        return '';
    }

    function sanitizeText(value) {
        return String(value || '').replace(/\s+/g, ' ').trim();
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function humanizeFirebaseError(error, fallbackMessage) {
        const code = String(error && error.code ? error.code : '').toUpperCase();
        const message = String(error && error.message ? error.message : '');

        if (code.includes('PERMISSION_DENIED') || message.includes('Permission denied')) {
            return 'Firebase menolak akses live music. Publish rules untuk ems_live_music terlebih dahulu.';
        }

        if (code.includes('AUTH/CONFIGURATION-NOT-FOUND')) {
            return 'Anonymous Auth Firebase belum aktif untuk project ini.';
        }

        if (code.includes('AUTH/INVALID-API-KEY')) {
            return 'API key Firebase tidak valid di konfigurasi aplikasi.';
        }

        return fallbackMessage;
    }
})();
