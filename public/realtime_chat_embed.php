<?php

require_once __DIR__ . '/../config/helpers.php';

function ems_public_render_realtime_chat(array $viewer): void
{
    $realtimeChatConfig = require __DIR__ . '/../config/realtime_chat.php';
    if (empty($realtimeChatConfig['enabled'])) {
        return;
    }
    ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(ems_asset('/assets/css/realtime-chat-widget.css'), ENT_QUOTES, 'UTF-8') ?>">

    <div id="emsLiveChat" class="ems-live-chat is-open" aria-live="polite">
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

    <script>
        window.EMS_REALTIME_CHAT_CONFIG = <?= json_encode([
            'enabled' => true,
            'firebase' => $realtimeChatConfig['firebase'],
            'paths' => $realtimeChatConfig['paths'],
            'ui' => $realtimeChatConfig['ui'],
            'viewer' => $viewer,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <script type="module" src="<?= htmlspecialchars(ems_asset('/assets/js/realtime-chat-widget.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
    <?php
}
