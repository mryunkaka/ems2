<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/roxy_chatbot.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

ems_roxy_ensure_tables($pdo);

$pageTitle = 'Roxy | Farmasi EMS';
$user = $_SESSION['user_rh'] ?? [];
$userId = (int) ($user['id'] ?? 0);
$isManagerPlus = ems_is_manager_plus_role((string) ($user['role'] ?? ''));

$groqSettings = ems_groq_get_user_settings($pdo, $userId);
$aiSettings = ems_ai_ds_get_user_settings($pdo, $userId);
$hasGroqKey = $groqSettings !== null && trim((string) ($groqSettings['groq_api_key'] ?? '')) !== '';
$hasGeminiKey = $aiSettings !== null && trim((string) ($aiSettings['gemini_api_key'] ?? '')) !== '';
$hasAiProvider = $hasGroqKey || $hasGeminiKey;

$csrfToken = generateCsrfToken();

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<style>
.roxy-layout { display:grid; grid-template-columns: 280px 1fr; gap:16px; height:calc(100vh - 220px); min-height:520px; }
@media (max-width: 900px) { .roxy-layout { grid-template-columns: 1fr; height:auto; } }
.roxy-conv-list { overflow-y:auto; }
.roxy-conv-row { display:flex; align-items:stretch; gap:4px; margin-bottom:4px; }
.roxy-conv-item { display:block; width:100%; flex:1; text-align:left; padding:10px 12px; border-radius:10px; border:1px solid transparent; background:transparent; cursor:pointer; font-size:13px; color:#334155; margin-bottom:0; }
.roxy-conv-item:hover { background:#f1f5f9; }
.roxy-conv-item.active { background:#e0f2fe; border-color:#7dd3fc; color:#0c4a6e; font-weight:600; }
.roxy-conv-delete { width:28px; flex:0 0 28px; border:1px solid transparent; border-radius:8px; background:transparent; color:#94a3b8; cursor:pointer; }
.roxy-conv-delete:hover { background:#fee2e2; border-color:#fecaca; color:#b91c1c; }
.roxy-conv-item .roxy-conv-time { display:block; font-size:11px; color:#94a3b8; margin-top:2px; }
.roxy-chat-col { display:flex; flex-direction:column; min-height:0; }
.roxy-avatar-bar { display:flex; align-items:center; gap:10px; padding:10px 14px; border-bottom:1px solid #e2e8f0; }
.roxy-avatar-icon { width:38px; height:38px; border-radius:999px; display:flex; align-items:center; justify-content:center; flex-shrink:0; transition:background-color .2s, color .2s; }
.roxy-chat-window { flex:1; overflow-y:auto; padding:14px; display:flex; flex-direction:column; gap:10px; background:#f8fafc; }
.roxy-bubble-wrap { display:flex; flex-direction:column; max-width:80%; }
.roxy-bubble-wrap.user { align-self:flex-end; align-items:flex-end; }
.roxy-bubble-wrap.bot { align-self:flex-start; align-items:flex-start; }
.roxy-bubble-label { font-size:10px; font-weight:700; color:#64748b; margin-bottom:3px; text-transform:uppercase; letter-spacing:.03em; }
.roxy-bubble { padding:10px 14px; border-radius:14px; font-size:13px; line-height:1.6; word-break:break-word; }
.roxy-bubble-wrap.user .roxy-bubble { background:#0ea5e9; color:#fff; border-bottom-right-radius:4px; }
.roxy-bubble-wrap.bot .roxy-bubble { background:#fff; color:#1e293b; border:1px solid #e2e8f0; border-bottom-left-radius:4px; }
.roxy-rich-line { min-height:1.25em; }
.roxy-rich-heading { margin:8px 0 4px; font-weight:800; color:#0f172a; }
.roxy-rich-list { display:flex; gap:6px; margin:2px 0; }
.roxy-rich-list-marker { flex:0 0 auto; font-weight:700; color:#0284c7; }
.roxy-rich-label { font-weight:700; color:#0f172a; }
.roxy-rich-separator { height:8px; }
.roxy-bubble-wrap.user .roxy-rich-label,
.roxy-bubble-wrap.user .roxy-rich-heading { color:#fff; }
.roxy-bubble-source { font-size:10px; color:#94a3b8; margin-top:3px; }
.roxy-input-row { display:flex; gap:8px; padding:12px; border-top:1px solid #e2e8f0; }
.roxy-input-row textarea { flex:1; resize:none; }
.roxy-typing { font-size:12px; color:#94a3b8; font-style:italic; padding:0 14px 6px; }
</style>

<section class="content">
    <div class="page page-shell">
        <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:12px; flex-wrap:wrap;">
            <div>
                <h1 class="page-title">Roxy</h1>
                <p class="page-subtitle">Asisten AI internal — tanya apa saja soal cara pakai fitur di aplikasi ini atau SOP roleplay medis Roxwood Hospital.</p>
            </div>
            <?php if ($isManagerPlus): ?>
                <a href="/dashboard/ai_assistant_monitoring.php" class="btn-secondary"><?= ems_icon('users', 'h-4 w-4') ?> Monitoring Roxy</a>
            <?php endif; ?>
        </div>

        <?php if (!$hasAiProvider): ?>
            <div class="alert alert-warning mt-3">
                Atur API key Gemini atau Groq di
                <a href="/dashboard/ai_settings_personal.php" class="underline font-semibold">Setting AI Saya</a> agar Roxy bisa dipakai.
            </div>
        <?php elseif (!$hasGroqKey && $hasGeminiKey): ?>
            <div class="alert alert-info mt-3">
                Groq belum diatur. Roxy memakai Gemini pribadi sebagai jalur cadangan.
            </div>
        <?php endif; ?>

        <div class="roxy-layout mt-4">
            <div class="card mb-0" style="display:flex; flex-direction:column;">
                <div class="card-header flex items-center justify-between">
                    <span>Riwayat</span>
                    <div class="flex items-center gap-2">
                        <button type="button" id="roxyDeleteAllBtn" class="btn-secondary btn-sm" title="Hapus semua riwayat" aria-label="Hapus semua riwayat">
                            <?= ems_icon('trash', 'h-4 w-4') ?>
                        </button>
                        <button type="button" id="roxyNewChatBtn" class="btn-secondary btn-sm" title="Percakapan baru" aria-label="Percakapan baru">
                            <?= ems_icon('plus', 'h-4 w-4') ?>
                        </button>
                    </div>
                </div>
                <div id="roxyConvList" class="roxy-conv-list card-section" style="flex:1;">
                    <p class="meta-text-xs">Memuat...</p>
                </div>
            </div>

            <div class="card mb-0 roxy-chat-col">
                <div class="roxy-avatar-bar">
                    <div id="roxyAvatarIcon" class="roxy-avatar-icon" style="background:#e2e8f0; color:#475569;"><?= ems_icon('chat-bubble-left-right', 'h-5 w-5') ?></div>
                    <div>
                        <div style="font-weight:700; font-size:14px;">Roxy</div>
                        <div id="roxyStatusLabel" class="meta-text-xs">Siap membantu</div>
                    </div>
                </div>
                <div id="roxyChatWindow" class="roxy-chat-window">
                    <div class="roxy-bubble-wrap bot">
                        <div class="roxy-bubble-label">Roxy</div>
                        <div class="roxy-bubble">Halo! Aku Roxy, asisten AI internal Roxwood Hospital. Ada yang bisa aku bantu soal aplikasi ini atau SOP medis?</div>
                    </div>
                </div>
                <div id="roxyTyping" class="roxy-typing hidden">Roxy sedang mengetik...</div>
                <div class="roxy-input-row">
                    <textarea id="roxyInput" rows="1" placeholder="Tulis pertanyaan untuk Roxy..." <?= !$hasAiProvider ? 'disabled' : '' ?>></textarea>
                    <button type="button" id="roxySendBtn" class="btn-primary" <?= !$hasAiProvider ? 'disabled' : '' ?>>
                        <?= ems_icon('paper-airplane', 'h-4 w-4') ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
(function () {
    var CSRF_TOKEN = <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE) ?>;
    var currentConversationId = 0;

    var els = {
        convList: document.getElementById('roxyConvList'),
        newChatBtn: document.getElementById('roxyNewChatBtn'),
        deleteAllBtn: document.getElementById('roxyDeleteAllBtn'),
        chatWindow: document.getElementById('roxyChatWindow'),
        input: document.getElementById('roxyInput'),
        sendBtn: document.getElementById('roxySendBtn'),
        typing: document.getElementById('roxyTyping'),
        avatarIcon: document.getElementById('roxyAvatarIcon'),
        statusLabel: document.getElementById('roxyStatusLabel'),
    };

    var EXPRESSION_STYLE = {
        netral: { bg: '#e2e8f0', color: '#475569', label: 'Siap membantu' },
        thinking: { bg: '#fef3c7', color: '#92400e', label: 'Sedang berpikir' },
        happy: { bg: '#dcfce7', color: '#166534', label: 'Senang membantu' },
        empathetic: { bg: '#fce7f3', color: '#9d174d', label: 'Memahami situasimu' },
        alert: { bg: '#fee2e2', color: '#991b1b', label: 'Perlu perhatian' },
        confused: { bg: '#dbeafe', color: '#1e40af', label: 'Butuh klarifikasi' },
    };
    var EXPRESSION_ICON = {
        netral: 'chat-bubble-left-right',
        thinking: 'clock',
        happy: 'sparkles',
        empathetic: 'shield-check',
        alert: 'exclamation-triangle',
        confused: 'information-circle',
    };
    var ICON_PATHS = <?= json_encode([
        'chat-bubble-left-right' => ems_icon('chat-bubble-left-right', 'h-5 w-5'),
        'clock' => ems_icon('clock', 'h-5 w-5'),
        'sparkles' => ems_icon('sparkles', 'h-5 w-5'),
        'shield-check' => ems_icon('shield-check', 'h-5 w-5'),
        'exclamation-triangle' => ems_icon('exclamation-triangle', 'h-5 w-5'),
        'information-circle' => ems_icon('information-circle', 'h-5 w-5'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    function setExpression(expr) {
        var style = EXPRESSION_STYLE[expr] || EXPRESSION_STYLE.netral;
        var icon = EXPRESSION_ICON[expr] || EXPRESSION_ICON.netral;
        els.avatarIcon.style.background = style.bg;
        els.avatarIcon.style.color = style.color;
        els.avatarIcon.innerHTML = ICON_PATHS[icon] || ICON_PATHS['chat-bubble-left-right'];
        els.statusLabel.textContent = style.label;
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function renderInlineMarkdown(value) {
        var html = escapeHtml(value);
        html = html.replace(/`([^`\n]+)`/g, '<code>$1</code>');
        html = html.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
        html = html.replace(/__([^_\n]+)__/g, '<strong>$1</strong>');
        html = html.replace(/(^|[\s(])\*([^*\n]+)\*(?=[\s).,!?:;]|$)/g, '$1<em>$2</em>');
        html = html.replace(/(^|[\s(])_([^_\n]+)_(?=[\s).,!?:;]|$)/g, '$1<em>$2</em>');
        return html;
    }

    function renderAnswer(text) {
        var fragment = document.createDocumentFragment();
        String(text || '').replace(/\r\n?/g, '\n').split('\n').forEach(function (line) {
            var trimmed = line.trim();
            if (/^---+$/.test(trimmed)) {
                var separator = document.createElement('div');
                separator.className = 'roxy-rich-separator';
                fragment.appendChild(separator);
                return;
            }

            var heading = trimmed.match(/^(#{1,3})\s+(.+)$/);
            var list = trimmed.match(/^(?:[-*•]|(\d+)[.)])\s+(.+)$/);
            var node = document.createElement('div');
            node.className = 'roxy-rich-line';

            if (heading) {
                node.className += ' roxy-rich-heading';
                node.innerHTML = renderInlineMarkdown(heading[2]);
            } else if (list) {
                node.className += ' roxy-rich-list';
                var marker = document.createElement('span');
                marker.className = 'roxy-rich-list-marker';
                marker.textContent = list[1] ? list[1] + '.' : '•';
                var item = document.createElement('span');
                item.innerHTML = renderInlineMarkdown(list[2]);
                node.appendChild(marker);
                node.appendChild(item);
            } else if (trimmed === '') {
                node.innerHTML = '&nbsp;';
            } else {
                node.innerHTML = renderInlineMarkdown(line);
            }
            fragment.appendChild(node);
        });
        return fragment;
    }

    function appendBubble(sender, text, sourceLabel) {
        var wrap = document.createElement('div');
        wrap.className = 'roxy-bubble-wrap ' + (sender === 'user' ? 'user' : 'bot');
        var label = document.createElement('div');
        label.className = 'roxy-bubble-label';
        label.textContent = sender === 'user' ? 'Anda' : 'Roxy';
        var bubble = document.createElement('div');
        bubble.className = 'roxy-bubble';
        bubble.appendChild(renderAnswer(text));
        wrap.appendChild(label);
        wrap.appendChild(bubble);
        if (sourceLabel) {
            var src = document.createElement('div');
            src.className = 'roxy-bubble-source';
            src.textContent = sourceLabel;
            wrap.appendChild(src);
        }
        els.chatWindow.appendChild(wrap);
        els.chatWindow.scrollTop = els.chatWindow.scrollHeight;
    }

    function answerSourceLabel(source, usedDeepResearch) {
        if (usedDeepResearch || source === 'gemini_personal') return 'Hasil riset mendalam (Gemini pribadi)';
        return '';
    }

    function loadConversationList(selectId) {
        fetch('/ajax/roxy_conversations.php?action=list')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) return;
                if (!data.conversations.length) {
                    els.convList.innerHTML = '<p class="meta-text-xs">Belum ada percakapan.</p>';
                    return;
                }
                var html = '';
                data.conversations.forEach(function (c) {
                    var active = (selectId && c.id == selectId) ? ' active' : '';
                    html += '<div class="roxy-conv-row">' +
                        '<button type="button" class="roxy-conv-item' + active + '" data-id="' + c.id + '">' +
                            escapeHtml(c.title || 'Percakapan') +
                            '<span class="roxy-conv-time">' + escapeHtml(c.last_message_at || '') + '</span>' +
                        '</button>' +
                        '<button type="button" class="roxy-conv-delete" data-id="' + c.id + '" title="Hapus percakapan" aria-label="Hapus percakapan">&times;</button>' +
                    '</div>';
                });
                els.convList.innerHTML = html;
                els.convList.querySelectorAll('.roxy-conv-item').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        openConversation(parseInt(btn.dataset.id, 10));
                    });
                });
                els.convList.querySelectorAll('.roxy-conv-delete').forEach(function (btn) {
                    btn.addEventListener('click', function (event) {
                        event.stopPropagation();
                        deleteConversation(parseInt(btn.dataset.id, 10));
                    });
                });
            })
            .catch(function () {
                els.convList.innerHTML = '<p class="meta-text-xs">Gagal memuat riwayat.</p>';
            });
    }

    function openConversation(id) {
        currentConversationId = id;
        els.chatWindow.innerHTML = '';
        fetch('/ajax/roxy_conversations.php?action=messages&conversation_id=' + id)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) return;
                data.messages.forEach(function (m) {
                    appendBubble(m.sender, m.content);
                });
                var last = data.messages.length ? data.messages[data.messages.length - 1] : null;
                if (last && last.sender === 'bot' && last.expression_tag) {
                    setExpression(last.expression_tag);
                }
            });
        loadConversationList(id);
    }

    function resetChatWindow() {
        currentConversationId = 0;
        els.chatWindow.innerHTML = '';
        appendBubble('bot', 'Halo! Aku Roxy, asisten AI internal Roxwood Hospital. Ada yang bisa aku bantu soal aplikasi ini atau SOP medis?');
        setExpression('netral');
    }

    function deleteConversation(id) {
        if (!id || !window.confirm('Hapus percakapan ini beserta seluruh pesannya?')) return;

        var body = new URLSearchParams();
        body.set('action', 'delete');
        body.set('conversation_id', String(id));
        body.set('csrf_token', CSRF_TOKEN);

        fetch('/ajax/roxy_conversations.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        })
            .then(readJsonResponse)
            .then(function (data) {
                if (!data.success) {
                    window.alert(data.message || 'Riwayat gagal dihapus.');
                    return;
                }
                if (currentConversationId === id) resetChatWindow();
                loadConversationList(0);
            })
            .catch(function () { window.alert('Koneksi gagal. Riwayat belum dihapus.'); });
    }

    function deleteAllConversations() {
        if (!window.confirm('Hapus semua riwayat chat Roxy beserta seluruh pesannya?')) return;

        var body = new URLSearchParams();
        body.set('action', 'delete_all');
        body.set('csrf_token', CSRF_TOKEN);

        fetch('/ajax/roxy_conversations.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        })
            .then(readJsonResponse)
            .then(function (data) {
                if (!data.success) {
                    window.alert(data.message || 'Semua riwayat gagal dihapus.');
                    return;
                }
                resetChatWindow();
                loadConversationList(0);
            })
            .catch(function () { window.alert('Koneksi gagal. Riwayat belum dihapus.'); });
    }

    els.newChatBtn.addEventListener('click', function () {
        resetChatWindow();
        loadConversationList(0);
        els.input.focus();
    });

    els.deleteAllBtn.addEventListener('click', deleteAllConversations);

    function readJsonResponse(response) {
        return response.text().then(function (raw) {
            var data = null;
            try {
                data = JSON.parse(raw);
            } catch (error) {
                data = {
                    success: false,
                    message: response.status >= 500
                        ? 'Server Roxy mengalami gangguan. Coba lagi beberapa saat.'
                        : 'Respons Roxy tidak valid (HTTP ' + response.status + ').',
                };
            }
            data.http_status = response.status;
            return data;
        });
    }

    function sendMessage() {
        var text = els.input.value.trim();
        if (!text || els.sendBtn.disabled) return;

        appendBubble('user', text);
        els.input.value = '';
        els.sendBtn.disabled = true;
        els.typing.classList.remove('hidden');
        setExpression('thinking');

        var body = new URLSearchParams();
        body.set('csrf_token', CSRF_TOKEN);
        body.set('conversation_id', String(currentConversationId));
        body.set('message', text);

        fetch('/actions/roxy_chat_action.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        })
            .then(readJsonResponse)
            .then(function (data) {
                els.typing.classList.add('hidden');
                els.sendBtn.disabled = false;
                if (!data.success) {
                    setExpression('alert');
                    appendBubble('bot', data.message || 'Roxy gagal menjawab, coba lagi.');
                    return;
                }
                currentConversationId = data.conversation_id;
                setExpression(data.expression || 'netral');
                appendBubble('bot', data.answer, answerSourceLabel(data.answer_source, data.used_deep_research));
                if (data.gemini_key_missing) {
                    appendBubble('bot', 'Catatan: pertanyaan ini sebenarnya butuh riset lebih dalam, tapi kamu belum atur API key Gemini pribadi. Atur di Setting AI Saya kalau mau jawaban yang lebih mendalam untuk pertanyaan semacam ini.');
                }
                loadConversationList(currentConversationId);
            })
            .catch(function () {
                els.typing.classList.add('hidden');
                els.sendBtn.disabled = false;
                setExpression('alert');
                appendBubble('bot', 'Koneksi ke Roxy gagal. Coba lagi.');
            });
    }

    els.sendBtn.addEventListener('click', sendMessage);
    els.input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    loadConversationList(0);
})();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
