<?php
require_once __DIR__ . '/../config/helpers.php';

$footerLoginUrl = ems_url('/auth/login.php');
if (isset($pdo) && function_exists('ems_effective_unit')) {
    $footerUnit = ems_effective_unit($pdo, $_SESSION['user_rh'] ?? []);
    $footerLoginUrl = ems_url('/auth/login.php?unit=' . urlencode($footerUnit));
}

$realtimeChatConfig = require __DIR__ . '/../config/realtime_chat.php';
$realtimeChatViewer = [
    'userId' => (string)($_SESSION['user_rh']['id'] ?? ''),
    'name' => (string)(($_SESSION['user_rh']['full_name'] ?? $_SESSION['user_rh']['name'] ?? '')),
    'role' => (string)($_SESSION['user_rh']['role'] ?? ''),
    'unit' => (string)($_SESSION['user_rh']['unit_code'] ?? ''),
    'pageTitle' => (string)($pageTitle ?? ''),
];
?>
</main>
</div>

<div id="globalUploadOverlay" class="global-upload-overlay hidden" aria-hidden="true">
    <div class="global-upload-overlay-box">
        <div class="global-upload-spinner" aria-hidden="true"></div>
        <div class="global-upload-title" id="globalUploadOverlayTitle">Upload sedang diproses</div>
        <div class="global-upload-copy" id="globalUploadOverlayCopy">Mohon tunggu. File besar mungkin memerlukan waktu lebih lama untuk diproses dan dikirim.</div>
    </div>
</div>

<style>
    .global-upload-overlay {
        position: fixed;
        inset: 0;
        z-index: 999999;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 24px;
        background: rgba(15, 23, 42, 0.72);
        backdrop-filter: blur(6px);
    }

    .global-upload-overlay.hidden {
        display: none;
    }

    .global-upload-overlay-box {
        width: min(100%, 420px);
        border-radius: 24px;
        background: #ffffff;
        padding: 24px;
        text-align: center;
        box-shadow: 0 24px 60px rgba(15, 23, 42, 0.25);
    }

    .global-upload-spinner {
        width: 52px;
        height: 52px;
        margin: 0 auto 16px;
        border-radius: 999px;
        border: 4px solid #dbeafe;
        border-top-color: #0284c7;
        animation: ems-upload-spin 0.9s linear infinite;
    }

    .global-upload-title {
        font-size: 18px;
        font-weight: 800;
        color: #0f172a;
    }

    .global-upload-copy {
        margin-top: 8px;
        font-size: 13px;
        line-height: 1.6;
        color: #475569;
    }

    @keyframes ems-upload-spin {
        to {
            transform: rotate(360deg);
        }
    }
</style>

<?php if (!empty($realtimeChatConfig['enabled'])): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(ems_asset('/assets/css/realtime-chat-widget.css'), ENT_QUOTES, 'UTF-8') ?>">

    <div id="emsLiveChat" class="ems-live-chat" aria-live="polite">
        <div id="emsLiveChatPanel" class="ems-live-chat-panel">
            <div class="ems-live-chat-panel-head">
                <div class="ems-live-chat-panel-heading">
                    <div class="ems-live-chat-panel-title">Live Chat</div>
                    <button id="emsLiveChatViewersButton" class="ems-live-chat-ghost-btn" type="button">
                        <span class="ems-live-chat-ghost-dot"></span>
                        <span id="emsLiveChatOnlineLabel" class="ems-live-chat-panel-subtitle">0 online</span>
                    </button>
                </div>
                <button id="emsLiveChatClose" class="ems-live-chat-close" type="button" aria-label="Tutup live chat">
                    <span class="ems-live-chat-close-mark" aria-hidden="true">x</span>
                </button>
            </div>

            <div id="emsLiveChatMessages" class="ems-live-chat-messages">
                <div id="emsLiveChatStatus" class="ems-live-chat-empty">Menghubungkan live chat...</div>
            </div>

            <form id="emsLiveChatForm" class="ems-live-chat-form">
                <div id="emsLiveChatEditBar" class="ems-live-chat-edit-bar hidden">
                    <div class="ems-live-chat-edit-copy">
                        <strong>Mengedit pesan</strong>
                        <span id="emsLiveChatEditPreview"></span>
                    </div>
                    <button id="emsLiveChatEditCancel" class="ems-live-chat-edit-cancel" type="button">Batal</button>
                </div>
                <div class="ems-live-chat-composer">
                    <textarea id="emsLiveChatInput" class="ems-live-chat-input" maxlength="500" placeholder="Tulis pesan..."></textarea>
                    <button id="emsLiveChatSend" class="ems-live-chat-send" type="submit" aria-label="Kirim pesan">
                        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
                            <path d="M3 20l18-8L3 4v6l12 2-12 2z" fill="currentColor"/>
                        </svg>
                    </button>
                </div>
                <div class="ems-live-chat-note">
                    <span>Maks. 500 karakter</span>
                    <span>Live</span>
                </div>
            </form>
        </div>

        <div id="emsLiveChatViewersModal" class="ems-live-chat-viewers-modal hidden">
            <div class="ems-live-chat-viewers-dialog">
                <div class="ems-live-chat-viewers-head">
                    <div>
                        <div class="ems-live-chat-viewers-title">Sedang Online</div>
                        <div id="emsLiveChatViewersMeta" class="ems-live-chat-viewers-subtitle">Menghubungkan...</div>
                    </div>
                    <button id="emsLiveChatViewersClose" class="ems-live-chat-close" type="button" aria-label="Tutup daftar online">
                        <span class="ems-live-chat-close-mark" aria-hidden="true">x</span>
                    </button>
                </div>
                <div id="emsLiveChatViewers" class="ems-live-chat-viewers"></div>
            </div>
        </div>

        <button id="emsLiveChatToggle" class="ems-live-chat-toggle" type="button" aria-label="Buka live chat">
            <span class="ems-live-chat-toggle-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="20" height="20">
                    <path d="M6 9h12M6 13h8m-8 8l-2-4H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2h-9l-5 4Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <span class="ems-live-chat-toggle-dot"></span>
            </span>
            <span class="ems-live-chat-toggle-copy">
                <span class="ems-live-chat-toggle-title">Live Chat</span>
                <span class="ems-live-chat-toggle-meta">
                    <span class="ems-live-chat-online-pill" data-ems-live-chat-online-count>0</span>
                    <span>Online</span>
                </span>
            </span>
        </button>
    </div>
<?php endif; ?>

<?php
// Widget bubble Roxy (docs/AI_ASSISTANT_MODULE.md §4a) — bersebelahan
// (ditumpuk di atas) bubble Live Chat di atas, untuk obrolan cepat tanpa
// pindah halaman. Isinya murni personal (percakapan user yang sedang
// login saja) — reuse endpoint yang sama dengan halaman chat penuh
// (dashboard/ai_assistant.php), bukan implementasi terpisah.
$roxyWidgetEnabled = isset($pdo) && !empty($_SESSION['user_rh']['id']);
$roxyHasGroqKey = false;
$roxyHasGeminiKey = false;
if ($roxyWidgetEnabled) {
    require_once __DIR__ . '/../config/groq_settings.php';
    require_once __DIR__ . '/../config/ai_diagnosis_surgery.php';
    $roxyUserId = (int) $_SESSION['user_rh']['id'];
    $roxyWidgetSettings = ems_groq_get_user_settings($pdo, $roxyUserId);
    $roxyGeminiSettings = ems_ai_ds_get_user_settings($pdo, $roxyUserId);
    $roxyHasGroqKey = $roxyWidgetSettings !== null && trim((string) ($roxyWidgetSettings['groq_api_key'] ?? '')) !== '';
    $roxyHasGeminiKey = ems_ai_ds_has_text_provider($roxyGeminiSettings);
}
$roxyHasAiProvider = $roxyHasGroqKey || $roxyHasGeminiKey;
?>
<?php if ($roxyWidgetEnabled): ?>
    <div id="roxyWidget" class="roxy-widget" aria-live="polite">
        <div id="roxyWidgetPanel" class="roxy-widget-panel hidden">
            <div class="roxy-widget-head">
                <div class="roxy-widget-head-title">
                    <div id="roxyWidgetAvatar" class="roxy-widget-avatar">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12c0-4.97 4.03-9 9-9 2.397 0 4.575.938 6.19 2.468A8.962 8.962 0 0 1 20.25 12a8.962 8.962 0 0 1-2.81 6.532A8.962 8.962 0 0 1 11.25 21a8.962 8.962 0 0 1-6.364-2.636L2.25 21l1.636-4.636A8.962 8.962 0 0 1 2.25 12Z" /></svg>
                    </div>
                    <div>
                        <div class="roxy-widget-title">Roxy</div>
                        <div id="roxyWidgetStatus" class="roxy-widget-subtitle">Siap membantu</div>
                    </div>
                </div>
                <div class="roxy-widget-head-actions">
                    <a href="<?= htmlspecialchars(ems_url('/dashboard/ai_assistant.php'), ENT_QUOTES, 'UTF-8') ?>" class="roxy-widget-iconbtn" title="Buka halaman penuh">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H19.5m0 0v6m0-6-7.5 7.5" /><path stroke-linecap="round" stroke-linejoin="round" d="M6 7.5H5.25A2.25 2.25 0 0 0 3 9.75v9A2.25 2.25 0 0 0 5.25 21h9a2.25 2.25 0 0 0 2.25-2.25V18" /></svg>
                    </a>
                    <button type="button" id="roxyWidgetClose" class="roxy-widget-iconbtn" aria-label="Tutup Roxy">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                    </button>
                </div>
            </div>

            <?php if (!$roxyHasAiProvider): ?>
                <div class="roxy-widget-warning">
                    Atur provider AI pribadi di
                    <a href="<?= htmlspecialchars(ems_url('/dashboard/ai_settings_personal.php'), ENT_QUOTES, 'UTF-8') ?>">Setting AI Saya</a>.
                </div>
            <?php elseif (!$roxyHasGroqKey && $roxyHasGeminiKey): ?>
                <div class="roxy-widget-info">
                    Groq belum diatur. Roxy memakai provider AI pribadi.
                </div>
            <?php endif; ?>

            <div class="roxy-widget-messages-label">Percakapan</div>

            <div id="roxyWidgetMessages" class="roxy-widget-messages"></div>
            <div id="roxyWidgetTyping" class="roxy-widget-typing hidden">Roxy sedang mengetik...</div>

            <form id="roxyWidgetForm" class="roxy-widget-form">
                <textarea id="roxyWidgetInput" rows="1" maxlength="4000" placeholder="Tulis pertanyaan untuk Roxy..." <?= !$roxyHasAiProvider ? 'disabled' : '' ?>></textarea>
                <button type="submit" id="roxyWidgetSend" class="roxy-widget-send" aria-label="Kirim" <?= !$roxyHasAiProvider ? 'disabled' : '' ?>>
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" /></svg>
                </button>
            </form>
        </div>

        <button type="button" id="roxyWidgetToggle" class="roxy-widget-toggle" aria-label="Buka Roxy">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12c0-4.97 4.03-9 9-9 2.397 0 4.575.938 6.19 2.468A8.962 8.962 0 0 1 20.25 12a8.962 8.962 0 0 1-2.81 6.532A8.962 8.962 0 0 1 11.25 21a8.962 8.962 0 0 1-6.364-2.636L2.25 21l1.636-4.636A8.962 8.962 0 0 1 2.25 12Z" /></svg>
            <span class="roxy-widget-toggle-label">Roxy</span>
        </button>
    </div>

    <style>
        .roxy-widget { position: fixed; right: 16px; bottom: 90px; z-index: 99985; display: flex; flex-direction: column; align-items: flex-end; gap: 10px; font-family: inherit; }
        .roxy-widget-toggle { display: flex; align-items: center; gap: 8px; padding: 10px 16px; border-radius: 999px; border: none; background: #0ea5e9; color: #fff; box-shadow: 0 10px 25px rgba(14,165,233,.35); cursor: pointer; font-size: 13px; font-weight: 700; }
        .roxy-widget-toggle:hover { background: #0284c7; }
        .roxy-widget-panel { width: 320px; max-width: calc(100vw - 32px); height: 440px; max-height: calc(100vh - 140px); background: #fff; border-radius: 16px; box-shadow: 0 20px 45px rgba(15,23,42,.25); display: flex; flex-direction: column; overflow: hidden; border: 1px solid #e2e8f0; }
        .roxy-widget-panel.hidden { display: none; }
        .roxy-widget-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 10px 12px; border-bottom: 1px solid #e2e8f0; background: #f8fafc; }
        .roxy-widget-head-title { display: flex; align-items: center; gap: 8px; }
        .roxy-widget-avatar { width: 32px; height: 32px; border-radius: 999px; display: flex; align-items: center; justify-content: center; background: #e2e8f0; color: #475569; flex-shrink: 0; transition: background-color .2s, color .2s; }
        .roxy-widget-title { font-size: 13px; font-weight: 700; color: #0f172a; }
        .roxy-widget-subtitle { font-size: 11px; color: #64748b; }
        .roxy-widget-head-actions { display: flex; align-items: center; gap: 4px; }
        .roxy-widget-iconbtn { display: flex; align-items: center; justify-content: center; width: 26px; height: 26px; padding: 0; margin: 0; box-sizing: border-box; border-radius: 8px; border: none; background: transparent; color: #64748b; cursor: pointer; appearance: none; -webkit-appearance: none; line-height: 1; text-decoration: none; flex-shrink: 0; }
        .roxy-widget-iconbtn:hover { background: #e2e8f0; }
        .roxy-widget-iconbtn svg { display: block; flex-shrink: 0; pointer-events: none; }
        .roxy-widget-warning, .roxy-widget-info { font-size: 11px; padding: 8px 12px; border-bottom: 1px solid; }
        .roxy-widget-warning { color: #92400e; background: #fef3c7; border-color: #fde68a; }
        .roxy-widget-info { color: #075985; background: #e0f2fe; border-color: #bae6fd; }
        .roxy-widget-warning a { font-weight: 700; text-decoration: underline; }
        .roxy-widget-messages-label { padding: 7px 12px 5px; background: #f8fafc; color: #64748b; font-size: 10px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
        .roxy-widget-messages { flex: 1; overflow-y: auto; padding: 12px; display: flex; flex-direction: column; gap: 12px; background: #f8fafc; }
        .roxy-widget-bubble-wrap { display: flex; flex-direction: column; max-width: 85%; }
        .roxy-widget-bubble-wrap.user { align-self: flex-end; align-items: flex-end; }
        .roxy-widget-bubble-wrap.bot { align-self: flex-start; align-items: flex-start; }
        .roxy-widget-bubble { padding: 10px 13px; border-radius: 13px; font-size: 12.5px; line-height: 1.65; white-space: pre-wrap; word-break: break-word; }
        .roxy-widget-bubble strong, .roxy-widget-bubble b { font-weight: 800; }
        .roxy-widget-rich-line { min-height: 1.3em; }
        .roxy-widget-rich-heading { margin: 5px 0 3px; font-weight: 800; color: #0f172a; }
        .roxy-widget-rich-list { display: flex; gap: 6px; margin: 2px 0; }
        .roxy-widget-rich-list-marker { flex: 0 0 auto; font-weight: 800; color: #0284c7; }
        .roxy-widget-rich-separator { height: 7px; }
        .roxy-widget-bubble-wrap.user .roxy-widget-rich-heading { color: #fff; }
        .roxy-widget-bubble-wrap.user .roxy-widget-rich-list-marker { color: #e0f2fe; }
        .roxy-widget-bubble code { padding: 1px 4px; border-radius: 4px; background: #f1f5f9; font-size: .92em; }
        .roxy-widget-bubble-wrap.user .roxy-widget-bubble code { background: rgba(255,255,255,.2); }
        .roxy-widget-bubble-wrap.user .roxy-widget-bubble { background: #0ea5e9; color: #fff; border-bottom-right-radius: 3px; }
        .roxy-widget-bubble-wrap.bot .roxy-widget-bubble { background: #fff; color: #1e293b; border: 1px solid #e2e8f0; border-bottom-left-radius: 3px; }
        .roxy-widget-typing { font-size: 11px; color: #94a3b8; font-style: italic; padding: 0 12px 6px; background: #f8fafc; }
        .roxy-widget-form { display: flex; gap: 6px; padding: 10px; border-top: 1px solid #e2e8f0; }
        .roxy-widget-form textarea { flex: 1; resize: none; font-size: 12.5px; padding: 8px 10px; border-radius: 10px; border: 1px solid #cbd5e1; max-height: 80px; }
        .roxy-widget-send { display: flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 10px; border: none; background: #0ea5e9; color: #fff; cursor: pointer; flex-shrink: 0; }
        .roxy-widget-send:disabled, .roxy-widget-form textarea:disabled { opacity: .5; cursor: not-allowed; }
        .roxy-widget-send:hover:not(:disabled) { background: #0284c7; }
        @media (max-width: 480px) {
            .roxy-widget { right: 10px; bottom: 78px; }
            .roxy-widget-panel { width: calc(100vw - 20px); }
        }
    </style>

    <script>
    (function () {
        var CSRF_TOKEN = String(window.EMS_CSRF_TOKEN || '');
        var HAS_AI_PROVIDER = <?= $roxyHasAiProvider ? 'true' : 'false' ?>;
        var conversationId = 0;
        var loaded = false;

        var toggle = document.getElementById('roxyWidgetToggle');
        var panel = document.getElementById('roxyWidgetPanel');
        var closeBtn = document.getElementById('roxyWidgetClose');
        var messagesEl = document.getElementById('roxyWidgetMessages');
        var typingEl = document.getElementById('roxyWidgetTyping');
        var form = document.getElementById('roxyWidgetForm');
        var input = document.getElementById('roxyWidgetInput');
        var sendBtn = document.getElementById('roxyWidgetSend');
        var avatarEl = document.getElementById('roxyWidgetAvatar');
        var statusEl = document.getElementById('roxyWidgetStatus');

        var EXPRESSION_STYLE = {
            netral: { bg: '#e2e8f0', color: '#475569', label: 'Siap membantu' },
            thinking: { bg: '#fef3c7', color: '#92400e', label: 'Sedang berpikir' },
            happy: { bg: '#dcfce7', color: '#166534', label: 'Senang membantu' },
            empathetic: { bg: '#fce7f3', color: '#9d174d', label: 'Memahami situasimu' },
            alert: { bg: '#fee2e2', color: '#991b1b', label: 'Perlu perhatian' },
            confused: { bg: '#dbeafe', color: '#1e40af', label: 'Butuh klarifikasi' },
        };

        function setExpression(expr) {
            var s = EXPRESSION_STYLE[expr] || EXPRESSION_STYLE.netral;
            avatarEl.style.background = s.bg;
            avatarEl.style.color = s.color;
            statusEl.textContent = s.label;
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
            return html;
        }

        function renderBubbleText(text) {
            var fragment = document.createDocumentFragment();
            String(text || '').replace(/\r\n?/g, '\n').split('\n').forEach(function (line) {
                var trimmed = line.trim();
                var node = document.createElement('div');
                node.className = 'roxy-widget-rich-line';

                if (/^---+$/.test(trimmed)) {
                    node.className += ' roxy-widget-rich-separator';
                } else {
                    var heading = trimmed.match(/^(#{1,3})\s+(.+)$/);
                    var list = trimmed.match(/^(?:[-*•]|(\d+)[.)])\s+(.+)$/);
                    if (heading) {
                        node.className += ' roxy-widget-rich-heading';
                        node.innerHTML = renderInlineMarkdown(heading[2]);
                    } else if (list) {
                        node.className += ' roxy-widget-rich-list';
                        var marker = document.createElement('span');
                        marker.className = 'roxy-widget-rich-list-marker';
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
                }
                fragment.appendChild(node);
            });
            return fragment;
        }

        function appendBubble(sender, text) {
            var wrap = document.createElement('div');
            wrap.className = 'roxy-widget-bubble-wrap ' + (sender === 'user' ? 'user' : 'bot');
            var bubble = document.createElement('div');
            bubble.className = 'roxy-widget-bubble';
            bubble.appendChild(renderBubbleText(text));
            wrap.appendChild(bubble);
            messagesEl.appendChild(wrap);
            messagesEl.scrollTop = messagesEl.scrollHeight;
        }

        function greet() {
            appendBubble('bot', 'Halo! Aku Roxy. Ada yang bisa aku bantu soal aplikasi ini atau SOP medis?');
        }

        function loadLatestConversation() {
            fetch('<?= htmlspecialchars(ems_url('/ajax/roxy_conversations.php'), ENT_QUOTES, 'UTF-8') ?>?action=list')
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.success || !data.conversations.length) {
                        greet();
                        return;
                    }
                    conversationId = data.conversations[0].id;
                    return fetch('<?= htmlspecialchars(ems_url('/ajax/roxy_conversations.php'), ENT_QUOTES, 'UTF-8') ?>?action=messages&conversation_id=' + conversationId)
                        .then(function (r) { return r.json(); })
                        .then(function (msgData) {
                            if (!msgData.success || !msgData.messages.length) {
                                greet();
                                return;
                            }
                            msgData.messages.forEach(function (m) { appendBubble(m.sender, m.content); });
                            var last = msgData.messages[msgData.messages.length - 1];
                            if (last.sender === 'bot' && last.expression_tag) setExpression(last.expression_tag);
                        });
                })
                .catch(function () { greet(); });
        }

        toggle.addEventListener('click', function () {
            panel.classList.remove('hidden');
            toggle.classList.add('hidden');
            if (!loaded) {
                loaded = true;
                loadLatestConversation();
            }
            if (HAS_AI_PROVIDER) input.focus();
        });

        closeBtn.addEventListener('click', function () {
            panel.classList.add('hidden');
            toggle.classList.remove('hidden');
        });

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
            var text = input.value.trim();
            if (!text || sendBtn.disabled) return;

            appendBubble('user', text);
            input.value = '';
            sendBtn.disabled = true;
            typingEl.classList.remove('hidden');
            setExpression('thinking');

            var body = new URLSearchParams();
            body.set('csrf_token', CSRF_TOKEN);
            body.set('conversation_id', String(conversationId));
            body.set('message', text);

            fetch('<?= htmlspecialchars(ems_url('/actions/roxy_chat_action.php'), ENT_QUOTES, 'UTF-8') ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
            })
                .then(readJsonResponse)
                .then(function (data) {
                    typingEl.classList.add('hidden');
                    sendBtn.disabled = false;
                    if (!data.success) {
                        setExpression('alert');
                        appendBubble('bot', data.message || 'Roxy gagal menjawab, coba lagi.');
                        return;
                    }
                    conversationId = data.conversation_id;
                    setExpression(data.expression || 'netral');
                    appendBubble('bot', data.answer);
                })
                .catch(function () {
                    typingEl.classList.add('hidden');
                    sendBtn.disabled = false;
                    setExpression('alert');
                    appendBubble('bot', 'Koneksi ke Roxy gagal. Coba lagi.');
                });
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            sendMessage();
        });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });
    })();
    </script>
<?php endif; ?>

<script src="<?= htmlspecialchars(ems_asset('/assets/js/app.js?refresh=20260501-setting-akun-fast'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars(ems_asset('/assets/design/js/app-shell.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars(ems_asset('/assets/vendor/photoswipe/photoswipe.umd.min.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars(ems_asset('/assets/vendor/photoswipe/photoswipe-lightbox.umd.min.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars(ems_asset('/assets/design/js/photoswipe-init.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars(ems_asset('/assets/vendor/jquery/jquery.min.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script>
if (!window.jQuery) {
    document.write('<script src="<?= htmlspecialchars(ems_asset('/assets/vendor/jquery/jquery.min.js?refresh=20260501'), ENT_QUOTES, 'UTF-8') ?>"><\/script>');
}
</script>
<script src="<?= htmlspecialchars(ems_asset('/assets/vendor/datatables/dataTables.min.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script>
if (!window.jQuery || !window.jQuery.fn || !window.jQuery.fn.dataTable) {
    document.write('<script src="<?= htmlspecialchars(ems_asset('/assets/vendor/datatables/dataTables.min.js?refresh=20260501'), ENT_QUOTES, 'UTF-8') ?>"><\/script>');
}
</script>
<script src="<?= htmlspecialchars(ems_asset('/assets/vendor/jszip/jszip.min.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script>
if (!window.JSZip) {
    document.write('<script src="<?= htmlspecialchars(ems_asset('/assets/vendor/jszip/jszip.min.js?refresh=20260501'), ENT_QUOTES, 'UTF-8') ?>"><\/script>');
}
</script>
<script src="<?= htmlspecialchars(ems_asset('/assets/vendor/datatables/dataTables.buttons.min.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script>
if (!window.jQuery || !window.jQuery.fn || !window.jQuery.fn.dataTable || !window.jQuery.fn.dataTable.Buttons) {
    document.write('<script src="<?= htmlspecialchars(ems_asset('/assets/vendor/datatables/dataTables.buttons.min.js?refresh=20260501'), ENT_QUOTES, 'UTF-8') ?>"><\/script>');
}
</script>
<script src="<?= htmlspecialchars(ems_asset('/assets/vendor/datatables/buttons.html5.min.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script>
if (!window.jQuery || !window.jQuery.fn || !window.jQuery.fn.dataTable || !window.jQuery.fn.dataTable.ext || !window.jQuery.fn.dataTable.ext.buttons) {
    document.write('<script src="<?= htmlspecialchars(ems_asset('/assets/vendor/datatables/buttons.html5.min.js?refresh=20260501'), ENT_QUOTES, 'UTF-8') ?>"><\/script>');
}
</script>
<script src="<?= htmlspecialchars(ems_asset('/assets/vendor/chartjs/chart.umd.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script>
if (!window.Chart) {
    document.write('<script src="<?= htmlspecialchars(ems_asset('/assets/vendor/chartjs/chart.umd.js?refresh=20260501'), ENT_QUOTES, 'UTF-8') ?>"><\/script>');
}
</script>

<!-- Sidebar Toggle Script -->
<script>
// Close sidebar on window resize to desktop
window.addEventListener('resize', function() {
    if (window.innerWidth >= 768) {
        document.body.classList.remove('sidebar-open');
    }
});
</script>

<script>
    (function setupGlobalUploadOverlay() {
        const overlay = document.getElementById('globalUploadOverlay');
        if (!overlay) {
            return;
        }

        const titleEl = document.getElementById('globalUploadOverlayTitle');
        const copyEl = document.getElementById('globalUploadOverlayCopy');
        const defaultTitle = titleEl ? titleEl.textContent : '';
        const defaultCopy = copyEl ? copyEl.textContent : '';

        function showOverlay(title, copy) {
            if (titleEl) {
                titleEl.textContent = title || defaultTitle;
            }
            if (copyEl) {
                copyEl.textContent = copy || defaultCopy;
            }
            overlay.classList.remove('hidden');
            overlay.setAttribute('aria-hidden', 'false');
            document.body.classList.add('modal-open');
        }

        function hideOverlay() {
            overlay.classList.add('hidden');
            overlay.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('modal-open');
        }

        window.emsShowUploadOverlay = showOverlay;
        window.emsHideUploadOverlay = hideOverlay;

        window.addEventListener('pageshow', hideOverlay);
        window.addEventListener('pagehide', hideOverlay);

        document.addEventListener('submit', function(event) {
            const form = event.target;
            if (!(form instanceof HTMLFormElement)) {
                return;
            }

            const hasFileInput = form.matches('[enctype="multipart/form-data"]') || !!form.querySelector('input[type="file"]');
            if (!hasFileInput) {
                return;
            }

            const fileInputs = Array.from(form.querySelectorAll('input[type="file"]'));
            const hasSelectedFiles = fileInputs.some(function(input) {
                return input.files && input.files.length > 0;
            });

            if (!hasSelectedFiles) {
                return;
            }

            const submitButtons = Array.from(form.querySelectorAll('button[type="submit"], input[type="submit"]'));
            submitButtons.forEach(function(button) {
                button.disabled = true;
            });

            window.requestAnimationFrame(function() {
                if (event.defaultPrevented) {
                    submitButtons.forEach(function(button) {
                        button.disabled = false;
                    });
                    return;
                }

                showOverlay();
            });
        }, true);
    })();
</script>

<script>
    (function realtimeSessionCheck() {
	        let timer = null;
	        let failCount = 0;
	        let inFlight = false;
            let pauseUntil = 0;

	        async function safeParseJSONResponse(res) {
	            if (!res || !res.ok) return null;

	            const contentType = String(res.headers.get('content-type') || '').toLowerCase();
	            const raw = await res.text();
	            if (!raw) return null;

	            if (contentType.includes('application/json')) {
	                try {
	                    return JSON.parse(raw);
	                } catch (e) {
	                    return null;
	                }
	            }

	            const trimmed = raw.trim();
	            if (!trimmed || trimmed.startsWith('<')) return null;

	            try {
	                return JSON.parse(trimmed);
	            } catch (e) {
	                return null;
	            }
	        }

	        async function safeFetchJSON(url, options = {}, timeoutMs = 6000) {
	            if (navigator.onLine === false) return null;

	            const controller = new AbortController();
	            const timeoutId = setTimeout(() => controller.abort(), timeoutMs);

	            try {
	                const res = await fetch(url, {
	                    credentials: 'same-origin',
	                    cache: 'no-store',
	                    ...options,
	                    signal: controller.signal
	                });
	                return await safeParseJSONResponse(res);
	            } catch (e) {
	                return null;
	            } finally {
	                clearTimeout(timeoutId);
	            }
	        }

	        function schedule(ms) {
	            if (timer) clearTimeout(timer);
	            timer = setTimeout(runOnce, ms);
	        }

	        async function runOnce() {
	            const pauseRemaining = Math.max(0, pauseUntil - Date.now());
	            if (pauseRemaining > 0) {
	                schedule(pauseRemaining);
	                return;
	            }

	            if (document.hidden) {
	                schedule(60000);
	                return;
	            }

	            if (inFlight) return;
	            inFlight = true;

	            const beforeFail = failCount;
	            try {
		                const data = await safeFetchJSON(window.emsUrl('/auth/check_session.php'));
		                if (!data) {
		                    failCount++;
                            pauseUntil = Date.now() + 300000;
		                    if (beforeFail === 0) {
		                        window.emsLogOnce('session-check-backoff', 'Session check sementara gagal dimuat, polling dibackoff.');
		                    }
		                    return;
		                }

	                failCount = 0;
                    pauseUntil = 0;

	                if (!data.valid) {
	                    document.body.innerHTML = `
	                        <div style="
	                            position:fixed;inset:0;
	                            display:flex;
	                            align-items:center;
	                            justify-content:center;
	                            background:rgba(0,0,0,.6);
	                            color:#fff;
	                            font-size:18px;
	                            z-index:99999">
	                            <div>
	                                <p>Akun Anda login di device lain</p>
	                                <p>Anda akan logout...</p>
	                            </div>
	                        </div>`;
		                    setTimeout(() => {
		                        window.location.href = <?= json_encode($footerLoginUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
		                    }, 1500);
	                }
	            } finally {
	                inFlight = false;
	                const base = 30000;
	                const backoff = Math.min(300000, 60000 * Math.pow(2, Math.min(Math.max(0, failCount - 1), 3)));
	                schedule(failCount ? backoff : base);
	            }
	        }

	        runOnce();
	        document.addEventListener('visibilitychange', () => {
	            if (!document.hidden) {
                    const pauseRemaining = Math.max(0, pauseUntil - Date.now());
                    if (pauseRemaining > 0) {
                        schedule(pauseRemaining);
                        return;
                    }
	                runOnce();
	            }
	        });
	        window.addEventListener('online', () => {
	            failCount = 0;
	            runOnce();
	        });
	    })();
	</script>

<script>
    setInterval(function() {
        if (document.hidden) {
            return;
        }

        var elements = document.querySelectorAll('.realtime-duration');
        if (!elements.length) {
            return;
        }

        for (var index = 0; index < elements.length; index++) {
            var el = elements[index];
            var timestamp = parseInt(el.getAttribute('data-start-timestamp'));

            if (!timestamp) {
                continue;
            }

            var now = Math.floor(Date.now() / 1000);
            var elapsed = now - timestamp;

            if (elapsed < 0) elapsed = 0;

            var hours = Math.floor(elapsed / 3600);
            var minutes = Math.floor((elapsed % 3600) / 60);
            var seconds = elapsed % 60;

            var display =
                (hours < 10 ? '0' : '') + hours + ':' +
                (minutes < 10 ? '0' : '') + minutes + ':' +
                (seconds < 10 ? '0' : '') + seconds;

            el.textContent = display;
        }
    }, 1000);
</script>

<?php if (!empty($realtimeChatConfig['enabled'])): ?>
    <script>
        window.EMS_REALTIME_CHAT_CONFIG = <?= json_encode([
            'enabled' => true,
            'firebase' => $realtimeChatConfig['firebase'],
            'paths' => $realtimeChatConfig['paths'],
            'ui' => $realtimeChatConfig['ui'],
            'viewer' => $realtimeChatViewer,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <script>
        window.EMS_REALTIME_MUSIC_CONFIG = <?= json_encode([
            'enabled' => true,
            'firebase' => $realtimeChatConfig['firebase'],
            'paths' => [
                'queue' => $realtimeChatConfig['paths']['musicQueue'] ?? 'ems_live_music/global_room/queue',
                'state' => $realtimeChatConfig['paths']['musicState'] ?? 'ems_live_music/global_room/state',
            ],
            'ui' => [
                'maxQueueItems' => $realtimeChatConfig['ui']['maxQueueItems'] ?? 25,
            ],
            'viewer' => $realtimeChatViewer,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <script type="module" src="<?= htmlspecialchars(ems_asset('/assets/js/realtime-chat-widget.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
    <script type="module" src="<?= htmlspecialchars(ems_asset('/assets/js/realtime-music-widget.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<?php endif; ?>

</body>

</html>
