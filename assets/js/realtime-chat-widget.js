import { initializeApp } from 'https://www.gstatic.com/firebasejs/12.7.0/firebase-app.js';
import { getAuth, signInAnonymously } from 'https://www.gstatic.com/firebasejs/12.7.0/firebase-auth.js';
import {
    getDatabase,
    ref,
    push,
    set,
    update,
    remove,
    onValue,
    onDisconnect,
    serverTimestamp,
    query,
    limitToLast
} from 'https://www.gstatic.com/firebasejs/12.7.0/firebase-database.js';

(function bootstrapRealtimeChatWidget() {
    const config = window.EMS_REALTIME_CHAT_CONFIG || null;
    const root = document.getElementById('emsLiveChat');

    if (!config || !config.enabled || !root) {
        return;
    }

    const toggleButton = document.getElementById('emsLiveChatToggle');
    const closeButton = document.getElementById('emsLiveChatClose');
    const viewersButton = document.getElementById('emsLiveChatViewersButton');
    const viewersModal = document.getElementById('emsLiveChatViewersModal');
    const viewersCloseButton = document.getElementById('emsLiveChatViewersClose');
    const onlineCountEls = Array.from(document.querySelectorAll('[data-ems-live-chat-online-count]'));
    const viewersMetaEl = document.getElementById('emsLiveChatViewersMeta');
    const viewersListEl = document.getElementById('emsLiveChatViewers');
    const messagesEl = document.getElementById('emsLiveChatMessages');
    const form = document.getElementById('emsLiveChatForm');
    const input = document.getElementById('emsLiveChatInput');
    const sendButton = document.getElementById('emsLiveChatSend');
    const onlineStatusEl = document.getElementById('emsLiveChatStatus');
    const onlineLabelEl = document.getElementById('emsLiveChatOnlineLabel');

    const app = initializeApp(config.firebase);
    const auth = getAuth(app);
    const database = getDatabase(app);

    const roomPath = String(config.paths?.messages || 'ems_live_chat/global_room/messages');
    const presencePath = String(config.paths?.presence || 'ems_presence/live_visitors');
    const maxMessages = Number(config.ui?.maxMessages || 40);

    const sessionId = (window.crypto && typeof window.crypto.randomUUID === 'function')
        ? window.crypto.randomUUID()
        : ('session-' + Date.now() + '-' + Math.random().toString(16).slice(2));
    const guestId = getOrCreateGuestId();
    const visitorProfile = buildVisitorProfile(guestId, sessionId);

    let authUid = null;
    let scrolledToBottom = true;

    toggleButton?.addEventListener('click', function () {
        root.classList.toggle('is-open');
        if (root.classList.contains('is-open')) {
            input?.focus();
            scrollMessagesToBottom();
        }
    });

    closeButton?.addEventListener('click', function () {
        root.classList.remove('is-open');
    });

    viewersButton?.addEventListener('click', function () {
        viewersModal?.classList.remove('hidden');
    });

    viewersCloseButton?.addEventListener('click', function () {
        viewersModal?.classList.add('hidden');
    });

    viewersModal?.addEventListener('click', function (event) {
        if (event.target === viewersModal) {
            viewersModal.classList.add('hidden');
        }
    });

    messagesEl?.addEventListener('scroll', function () {
        const threshold = 40;
        scrolledToBottom = messagesEl.scrollTop + messagesEl.clientHeight >= messagesEl.scrollHeight - threshold;
    });

    form?.addEventListener('submit', async function (event) {
        event.preventDefault();

        const text = String(input?.value || '').trim();
        if (!text || !authUid) {
            return;
        }

        sendButton.disabled = true;

        try {
            await push(ref(database, roomPath), {
                authUid,
                sessionId,
                senderId: visitorProfile.senderId,
                senderName: visitorProfile.name,
                senderRole: visitorProfile.role,
                senderUnit: visitorProfile.unit,
                pageTitle: visitorProfile.pageTitle,
                pagePath: visitorProfile.pagePath,
                text: text.slice(0, 500),
                createdAt: serverTimestamp()
            });

            input.value = '';
            scrolledToBottom = true;
            scrollMessagesToBottom();
        } catch (error) {
            console.error('Failed to send realtime chat message.', error);
            setStatus('Gagal kirim pesan realtime.', true);
        } finally {
            sendButton.disabled = false;
        }
    });

    setStatus('Menghubungkan live chat...', false);

    signInAnonymously(auth)
        .then(function (credential) {
            authUid = credential.user.uid;
            attachPresence(authUid);
            subscribePresence();
            subscribeMessages();
            setStatus('Live chat aktif.', false);
        })
        .catch(function (error) {
            console.error('Anonymous Firebase auth failed.', error);
            setStatus('Live chat belum aktif. Cek konfigurasi Firebase.', true);
        });

    function attachPresence(uid) {
        const connectedRef = ref(database, '.info/connected');
        const sessionRef = ref(database, presencePath + '/' + sessionId);

        onValue(connectedRef, async function (snapshot) {
            if (snapshot.val() !== true) {
                updateOnlineCount(0);
                return;
            }

            try {
                await onDisconnect(sessionRef).remove();
                await set(sessionRef, {
                    authUid: uid,
                    senderId: visitorProfile.senderId,
                    name: visitorProfile.name,
                    role: visitorProfile.role,
                    unit: visitorProfile.unit,
                    pageTitle: visitorProfile.pageTitle,
                    pagePath: visitorProfile.pagePath,
                    openedAt: serverTimestamp(),
                    lastSeenAt: serverTimestamp()
                });
            } catch (error) {
                console.error('Failed to register presence.', error);
                setStatus('Gagal mencatat visitor online.', true);
            }
        });

        const refreshPresence = function () {
            update(sessionRef, {
                pageTitle: document.title || visitorProfile.pageTitle,
                pagePath: window.location.pathname + window.location.search,
                lastSeenAt: serverTimestamp()
            }).catch(function () {
                return null;
            });
        };

        window.addEventListener('beforeunload', function () {
            remove(sessionRef).catch(function () {
                return null;
            });
        });

        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                refreshPresence();
            }
        });

        window.setInterval(refreshPresence, 30000);
    }

    function subscribePresence() {
        onValue(ref(database, presencePath), function (snapshot) {
            const visitors = snapshot.val() || {};
            const entries = Object.entries(visitors)
                .map(function ([key, value]) {
                    return {
                        id: key,
                        name: sanitizeText(value?.name || 'Visitor'),
                        role: sanitizeText(value?.role || ''),
                        pageTitle: sanitizeText(value?.pageTitle || ''),
                        pagePath: sanitizeText(value?.pagePath || ''),
                        openedAt: Number(value?.openedAt || 0)
                    };
                })
                .sort(function (left, right) {
                    return (right.openedAt || 0) - (left.openedAt || 0);
                });

            updateOnlineCount(entries.length);
            renderViewers(entries.slice(0, 8));
        });
    }

    function subscribeMessages() {
        onValue(query(ref(database, roomPath), limitToLast(maxMessages)), function (snapshot) {
            const messages = [];

            snapshot.forEach(function (childSnapshot) {
                const value = childSnapshot.val() || {};
                messages.push({
                    id: childSnapshot.key,
                    authUid: String(value.authUid || ''),
                    senderId: sanitizeText(value.senderId || ''),
                    senderName: sanitizeText(value.senderName || 'Visitor'),
                    senderRole: sanitizeText(value.senderRole || ''),
                    pageTitle: sanitizeText(value.pageTitle || ''),
                    pagePath: sanitizeText(value.pagePath || ''),
                    text: sanitizeText(value.text || ''),
                    createdAt: Number(value.createdAt || 0)
                });
            });

            renderMessages(messages);
        });
    }

    function renderMessages(messages) {
        if (!messagesEl) {
            return;
        }

        if (!messages.length) {
            messagesEl.innerHTML = '<div class="ems-live-chat-empty">Belum ada pesan.</div>';
            return;
        }

        const html = messages.map(function (message) {
            const isSelf = authUid && message.authUid === authUid;
            const bubbleClass = isSelf ? 'ems-live-chat-message is-self' : 'ems-live-chat-message';
            const timeLabel = message.createdAt ? formatTime(message.createdAt) : 'baru saja';
            const pageLabel = message.pageTitle || message.pagePath || '';
            const roleLabel = message.senderRole ? ' • ' + escapeHtml(message.senderRole) : '';

            return '' +
                '<div class="' + bubbleClass + '">' +
                    '<div class="ems-live-chat-bubble">' +
                        '<div class="ems-live-chat-message-head">' +
                            '<div class="ems-live-chat-message-name">' + escapeHtml(message.senderName) + roleLabel + '</div>' +
                            '<div class="ems-live-chat-message-time">' + escapeHtml(timeLabel) + '</div>' +
                        '</div>' +
                        '<div class="ems-live-chat-message-body">' + escapeHtml(message.text) + '</div>' +
                        (pageLabel ? '<div class="ems-live-chat-message-page">Halaman: ' + escapeHtml(pageLabel) + '</div>' : '') +
                    '</div>' +
                '</div>';
        }).join('');

        messagesEl.innerHTML = html;

        if (scrolledToBottom || root.classList.contains('is-open')) {
            scrollMessagesToBottom();
        }
    }

    function renderViewers(viewers) {
        if (!viewersListEl || !viewersMetaEl) {
            return;
        }

        if (!viewers.length) {
            viewersMetaEl.textContent = 'Belum ada user online.';
            viewersListEl.innerHTML = '';
            return;
        }

        viewersMetaEl.textContent = viewers.length + ' user sedang membuka website.';
        viewersListEl.innerHTML = viewers.map(function (viewer) {
            const role = viewer.role ? ' • ' + escapeHtml(viewer.role) : '';
            const page = viewer.pageTitle || viewer.pagePath || 'Halaman aktif';
            return '' +
                '<div class="ems-live-chat-viewer-chip" title="' + escapeHtml(page) + '">' +
                    '<span class="ems-live-chat-viewer-dot"></span>' +
                    '<span>' + escapeHtml(viewer.name) + role + '</span>' +
                '</div>';
        }).join('');
    }

    function updateOnlineCount(count) {
        onlineCountEls.forEach(function (element) {
            element.textContent = String(count);
        });

        if (onlineLabelEl) {
            onlineLabelEl.textContent = count + ' online';
        }
    }

    function setStatus(message, isError) {
        if (!onlineStatusEl) {
            return;
        }

        onlineStatusEl.textContent = message;
        onlineStatusEl.className = isError ? 'ems-live-chat-system' : 'ems-live-chat-empty';
    }

    function scrollMessagesToBottom() {
        if (!messagesEl) {
            return;
        }

        window.requestAnimationFrame(function () {
            messagesEl.scrollTop = messagesEl.scrollHeight;
        });
    }

    function buildVisitorProfile(localGuestId, localSessionId) {
        const loggedInName = String(config.viewer?.name || '').trim();
        const role = String(config.viewer?.role || '').trim();
        const unit = String(config.viewer?.unit || '').trim();
        const pageTitle = document.title || String(config.viewer?.pageTitle || '').trim() || 'Dashboard';
        const pagePath = window.location.pathname + window.location.search;
        const fallbackGuest = 'Guest-' + localGuestId.slice(-4).toUpperCase();

        return {
            name: loggedInName || fallbackGuest,
            role: role,
            unit: unit,
            pageTitle: pageTitle,
            pagePath: pagePath,
            senderId: String(config.viewer?.userId || '').trim() || ('guest:' + localGuestId),
            sessionId: localSessionId
        };
    }

    function getOrCreateGuestId() {
        const key = 'emsLiveChatGuestId';
        const existing = window.localStorage.getItem(key);
        if (existing) {
            return existing;
        }

        const created = (window.crypto && typeof window.crypto.randomUUID === 'function')
            ? window.crypto.randomUUID().replace(/-/g, '')
            : ('guest-' + Date.now() + '-' + Math.random().toString(16).slice(2));
        window.localStorage.setItem(key, created);
        return created;
    }

    function formatTime(timestamp) {
        try {
            return new Intl.DateTimeFormat('id-ID', {
                hour: '2-digit',
                minute: '2-digit'
            }).format(new Date(timestamp));
        } catch (error) {
            return '';
        }
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
})();
