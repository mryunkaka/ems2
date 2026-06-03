import { getApp, getApps, initializeApp } from 'https://www.gstatic.com/firebasejs/12.7.0/firebase-app.js';
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
    const editBarEl = document.getElementById('emsLiveChatEditBar');
    const editPreviewEl = document.getElementById('emsLiveChatEditPreview');
    const editCancelButton = document.getElementById('emsLiveChatEditCancel');

    const app = getApps().length ? getApp() : initializeApp(config.firebase);
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
    let editingMessageId = '';
    let editingOriginalText = '';
    let replyingMessage = null;

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

    editCancelButton?.addEventListener('click', function () {
        resetEditingState(true);
        resetReplyingState(false);
    });

    messagesEl?.addEventListener('scroll', function () {
        const threshold = 40;
        scrolledToBottom = messagesEl.scrollTop + messagesEl.clientHeight >= messagesEl.scrollHeight - threshold;
    });

    messagesEl?.addEventListener('click', async function (event) {
        const button = event.target instanceof Element
            ? event.target.closest('[data-ems-chat-action]')
            : null;

        if (!button) {
            return;
        }

        const action = String(button.getAttribute('data-ems-chat-action') || '').trim();
        const messageId = String(button.getAttribute('data-message-id') || '').trim();
        const messageText = String(button.getAttribute('data-message-text') || '');
        const messageSenderId = String(button.getAttribute('data-message-sender-id') || '').trim();
        const messageSenderName = String(button.getAttribute('data-message-sender-name') || '').trim();
        const messageSenderRole = String(button.getAttribute('data-message-sender-role') || '').trim();

        if (!messageId) {
            return;
        }

        if (action === 'edit') {
            editingMessageId = messageId;
            editingOriginalText = messageText;
            resetReplyingState(false);

            if (input) {
                input.value = messageText;
                input.focus();
                input.setSelectionRange(messageText.length, messageText.length);
            }

            renderEditingState();
            return;
        }

        if (action === 'reply') {
            resetEditingState(false);
            replyingMessage = {
                id: messageId,
                text: messageText,
                senderId: messageSenderId,
                senderName: messageSenderName,
                senderRole: messageSenderRole
            };

            if (input) {
                input.focus();
            }

            renderReplyingState();
            return;
        }

        if (action === 'delete' && canDeleteMessages()) {
            if (!window.confirm('Hapus pesan live chat ini?')) {
                return;
            }

            try {
                await remove(ref(database, roomPath + '/' + messageId));
            } catch (error) {
                console.error('Failed to delete realtime chat message.', error);
                setStatus('Gagal menghapus pesan.', true);
            }
        }
    });

    form?.addEventListener('submit', async function (event) {
        event.preventDefault();

        const text = String(input?.value || '').trim();
        if (!text || !authUid) {
            return;
        }

        sendButton.disabled = true;

        try {
            if (editingMessageId) {
                await update(ref(database, roomPath + '/' + editingMessageId), {
                    text: text.slice(0, 500),
                    editedAt: serverTimestamp()
                });
                resetEditingState(false);
            } else {
                const replyPayload = buildReplyPayload();
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
                    ...replyPayload,
                    createdAt: serverTimestamp()
                });

                input.value = '';
                resetReplyingState(false);
            }

            scrolledToBottom = true;
            scrollMessagesToBottom();
        } catch (error) {
            console.error('Failed to send realtime chat message.', error);
            setStatus(editingMessageId ? 'Gagal mengedit pesan.' : 'Gagal kirim pesan realtime.', true);
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

            const uniqueViewers = dedupeViewers(entries);
            updateOnlineCount(uniqueViewers.length);
            renderViewers(uniqueViewers.slice(0, 8));
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
                    senderUnit: sanitizeText(value.senderUnit || ''),
                    pageTitle: sanitizeText(value.pageTitle || ''),
                    pagePath: sanitizeText(value.pagePath || ''),
                    text: sanitizeText(value.text || ''),
                    replyToMessageId: sanitizeText(value.replyToMessageId || ''),
                    replyToSenderId: sanitizeText(value.replyToSenderId || ''),
                    replyToSenderName: sanitizeText(value.replyToSenderName || ''),
                    replyToText: sanitizeText(value.replyToText || ''),
                    recipientSenderId: sanitizeText(value.recipientSenderId || ''),
                    recipientName: sanitizeText(value.recipientName || ''),
                    createdAt: Number(value.createdAt || 0),
                    editedAt: Number(value.editedAt || 0)
                });
            });

            if (editingMessageId && !messages.some(function (message) { return message.id === editingMessageId; })) {
                resetEditingState(false);
            }

            renderMessages(filterMessagesForViewer(messages));
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

        const html = messages.map(function (message, index) {
            const isSelf = authUid && message.authUid === authUid;
            const bubbleClass = isSelf ? 'ems-live-chat-message is-self' : 'ems-live-chat-message';
            const timeLabel = message.createdAt ? formatClockTime(message.createdAt) : '';
            const editedLabel = message.editedAt ? '<span class="ems-live-chat-message-status">diedit</span>' : '';
            const roleMarkup = isApplicantRole(message.senderRole)
                ? '<span class="ems-live-chat-message-role">' + escapeHtml(message.senderRole) + '</span>'
                : '';
            const nameMarkup = isSelf
                ? ''
                : '<div class="ems-live-chat-message-head"><div class="ems-live-chat-message-name">' + escapeHtml(message.senderName) + '</div>' + roleMarkup + '</div>';
            const replyMarkup = message.replyToMessageId
                ? '<div class="ems-live-chat-reply-preview"><strong>' + escapeHtml(message.replyToSenderName || 'Pesan') + '</strong><span>' + escapeHtml(message.replyToText || '') + '</span></div>'
                : '';
            const actionMarkup = buildMessageActions(message, isSelf);
            const separatorMarkup = shouldRenderDateSeparator(messages, index)
                ? '<div class="ems-live-chat-date-separator"><span>' + escapeHtml(formatDateSeparator(message.createdAt)) + '</span></div>'
                : '';

            return separatorMarkup +
                '<div class="' + bubbleClass + '">' +
                    '<div class="ems-live-chat-bubble">' +
                        nameMarkup +
                        replyMarkup +
                        '<div class="ems-live-chat-message-content">' +
                            '<div class="ems-live-chat-message-body">' + escapeHtml(message.text) + '</div>' +
                            '<div class="ems-live-chat-message-meta">' + editedLabel + '<span class="ems-live-chat-message-time">' + escapeHtml(timeLabel) + '</span></div>' +
                        '</div>' +
                        actionMarkup +
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
            const page = viewer.pageTitle || viewer.pagePath || 'Halaman aktif';
            return '' +
                '<div class="ems-live-chat-viewer-chip" title="' + escapeHtml(page) + '">' +
                    '<span class="ems-live-chat-viewer-dot"></span>' +
                    '<span>' + escapeHtml(viewer.name) + '</span>' +
                '</div>';
        }).join('');
    }

    function dedupeViewers(entries) {
        const uniqueMap = new Map();

        entries.forEach(function (entry) {
            const key = buildViewerKey(entry);
            const existing = uniqueMap.get(key);

            if (!existing || (entry.openedAt || 0) > (existing.openedAt || 0)) {
                uniqueMap.set(key, entry);
            }
        });

        return Array.from(uniqueMap.values()).sort(function (left, right) {
            return (right.openedAt || 0) - (left.openedAt || 0);
        });
    }

    function buildViewerKey(entry) {
        const normalizedName = sanitizeText((entry && entry.name) || '').toLowerCase();
        const normalizedRole = sanitizeText((entry && entry.role) || '').toLowerCase();
        return normalizedName + '::' + normalizedRole;
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

    function resetEditingState(clearInput) {
        editingMessageId = '';
        editingOriginalText = '';

        if (clearInput && input) {
            input.value = '';
        }

        renderEditingState();
    }

    function renderEditingState() {
        if (!editBarEl || !editPreviewEl || !sendButton) {
            return;
        }

        if (!editingMessageId) {
            editBarEl.classList.add('hidden');
            editBarEl.classList.remove('is-replying');
            editBarEl.querySelector('strong').textContent = 'Mengedit pesan';
            editPreviewEl.textContent = '';
            sendButton.setAttribute('aria-label', 'Kirim pesan');
            return;
        }

        editBarEl.classList.remove('is-replying');
        editBarEl.querySelector('strong').textContent = 'Mengedit pesan';
        editBarEl.classList.remove('hidden');
        editPreviewEl.textContent = editingOriginalText;
        sendButton.setAttribute('aria-label', 'Simpan edit pesan');
    }

    function resetReplyingState(focusInput) {
        replyingMessage = null;
        renderReplyingState();

        if (focusInput && input) {
            input.focus();
        }
    }

    function renderReplyingState() {
        if (!editBarEl || !editPreviewEl || !sendButton) {
            return;
        }

        if (!replyingMessage || editingMessageId) {
            if (!editingMessageId) {
                editBarEl.classList.add('hidden');
                editPreviewEl.textContent = '';
                sendButton.setAttribute('aria-label', 'Kirim pesan');
            }
            return;
        }

        editBarEl.classList.remove('hidden');
        editBarEl.classList.add('is-replying');
        editBarEl.querySelector('strong').textContent = 'Membalas ' + (replyingMessage.senderName || 'pesan');
        editPreviewEl.textContent = replyingMessage.text || '';
        sendButton.setAttribute('aria-label', 'Kirim balasan');
    }

    function buildReplyPayload() {
        if (!replyingMessage) {
            return {};
        }

        const payload = {
            replyToMessageId: replyingMessage.id,
            replyToSenderId: replyingMessage.senderId,
            replyToSenderName: replyingMessage.senderName,
            replyToText: replyingMessage.text.slice(0, 140)
        };

        if (isApplicantIdentity(replyingMessage.senderId, replyingMessage.senderRole)) {
            payload.recipientSenderId = replyingMessage.senderId;
            payload.recipientName = replyingMessage.senderName;
        }

        return payload;
    }

    function buildMessageActions(message, isSelf) {
        const attrs = ' data-message-id="' + escapeHtml(message.id) + '"' +
            ' data-message-text="' + escapeHtml(message.text) + '"' +
            ' data-message-sender-id="' + escapeHtml(message.senderId) + '"' +
            ' data-message-sender-name="' + escapeHtml(message.senderName) + '"' +
            ' data-message-sender-role="' + escapeHtml(message.senderRole) + '"';
        const actions = [];

        if (isSelf) {
            actions.push('<button class="ems-live-chat-message-action" type="button" data-ems-chat-action="edit"' + attrs + '>Edit</button>');
        }

        actions.push('<button class="ems-live-chat-message-action" type="button" data-ems-chat-action="reply"' + attrs + '>Balas</button>');

        if (canDeleteMessages()) {
            actions.push('<button class="ems-live-chat-message-action is-danger" type="button" data-ems-chat-action="delete"' + attrs + '>Hapus</button>');
        }

        return '<div class="ems-live-chat-message-actions">' + actions.join('') + '</div>';
    }

    function filterMessagesForViewer(messages) {
        if (!isCurrentViewerApplicant()) {
            return messages;
        }

        return messages.filter(function (message) {
            return message.senderId === visitorProfile.senderId
                || message.recipientSenderId === visitorProfile.senderId
                || message.replyToSenderId === visitorProfile.senderId;
        });
    }

    function isCurrentViewerApplicant() {
        return isApplicantIdentity(visitorProfile.senderId, visitorProfile.role);
    }

    function isApplicantIdentity(senderId, role) {
        return String(senderId || '').indexOf('applicant:') === 0 || isApplicantRole(role);
    }

    function isApplicantRole(role) {
        return sanitizeText(role).toLowerCase() === 'calon pelamar';
    }

    function canDeleteMessages() {
        const role = sanitizeText(visitorProfile.role).toLowerCase();
        const name = sanitizeText(visitorProfile.name).toLowerCase();

        return role === 'director'
            || role === 'vice director'
            || role === 'programmer roxwood'
            || name === 'programmer roxwood';
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

    function formatClockTime(timestamp) {
        try {
            return new Intl.DateTimeFormat('id-ID', {
                hour: '2-digit',
                minute: '2-digit',
                hour12: false
            }).format(new Date(timestamp));
        } catch (error) {
            return '';
        }
    }

    function formatDateSeparator(timestamp) {
        if (!timestamp) {
            return 'Hari ini';
        }

        const messageDate = new Date(timestamp);
        const today = new Date();
        const todayStart = new Date(today.getFullYear(), today.getMonth(), today.getDate());
        const messageStart = new Date(messageDate.getFullYear(), messageDate.getMonth(), messageDate.getDate());
        const diffDays = Math.round((todayStart.getTime() - messageStart.getTime()) / 86400000);

        if (diffDays <= 0) {
            return 'Hari ini';
        }

        if (diffDays === 1) {
            return 'Kemarin';
        }

        if (diffDays < 7) {
            return new Intl.DateTimeFormat('id-ID', {
                weekday: 'long'
            }).format(messageDate);
        }

        return new Intl.DateTimeFormat('id-ID', {
            day: 'numeric',
            month: 'long',
            year: 'numeric'
        }).format(messageDate);
    }

    function shouldRenderDateSeparator(messages, index) {
        const current = messages[index];
        const previous = index > 0 ? messages[index - 1] : null;

        if (!current) {
            return false;
        }

        if (!previous) {
            return true;
        }

        if (!current.createdAt || !previous.createdAt) {
            return false;
        }

        return !isSameCalendarDay(current.createdAt, previous.createdAt);
    }

    function isSameCalendarDay(leftTimestamp, rightTimestamp) {
        const left = new Date(leftTimestamp);
        const right = new Date(rightTimestamp);

        return left.getFullYear() === right.getFullYear()
            && left.getMonth() === right.getMonth()
            && left.getDate() === right.getDate();
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
