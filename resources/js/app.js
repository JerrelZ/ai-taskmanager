// Flux's editor (Tiptap v3) ships without an image node, so images already in a
// ticket description get stripped the moment it loads into the editor (read
// mode renders raw HTML, so they only vanish while editing). Registering the
// Image extension adds the node to the schema, keeping images visible and
// editable. allowBase64 covers inline data-URI images (e.g. pasted/emailed).
//
// Flux dispatches `flux:editor` synchronously right before `new Editor(...)`, so
// the extension must be available synchronously — hence a static import (it can
// not wait on a dynamic import without losing the registration window).
import Image from '@tiptap/extension-image';

// @tiptap/extension-image is PINNED to 3.0.9 on purpose: newer versions ship a
// custom addNodeView() whose node-view API doesn't match the older Tiptap that
// Flux bundles, which crashes the editor ("addNodeView is not a function") and
// drops everything after the first image. 3.0.9 renders images via plain
// renderHTML (no node view), so it works regardless of Flux's Tiptap version.
// Do not bump without testing the editor against Flux's bundled Tiptap.
//
// inline: true is essential too — descriptions store images as `<p><img></p>`,
// and a block image can't live inside a paragraph (inline content only), so a
// block image node would be dropped on parse. An inline node renders in place.
document.addEventListener('flux:editor', (e) => {
    e.detail.registerExtensions([Image.configure({ inline: true, allowBase64: true })]);

    // Paste an image (e.g. a screenshot) straight into the editor: intercept the
    // paste, upload it as a real task attachment, then embed it inline using the
    // returned URL. Falls through to the default paste for non-image clipboards.
    e.detail.init(({ editor }) => {
        editor.on('create', () => {
            const dom = editor.view.dom;
            if (dom.dataset.pasteImagesBound) {
                return;
            }
            dom.dataset.pasteImagesBound = '1';
            dom.addEventListener('paste', (event) => handleEditorImagePaste(event, editor), true);
        });
    });
});

function handleEditorImagePaste(event, editor) {
    const file = Array.from(event.clipboardData?.items || [])
        .find((item) => item.kind === 'file' && item.type.startsWith('image/'))
        ?.getAsFile();

    if (! file) {
        return; // not an image paste — let the editor handle text/links normally
    }

    event.preventDefault();
    event.stopPropagation();

    // Livewire.find() already returns the component's $wire proxy.
    const host = editor.view.dom.closest('[wire\\:id]');
    const wire = host && window.Livewire?.find(host.getAttribute('wire:id'));

    if (! wire) {
        return;
    }

    wire.upload('pastedImage', file, () => {
        wire.attachPastedImage().then((url) => {
            if (url) {
                editor.chain().focus().setImage({ src: url }).run();
            }
        });
    });
}

// Curated emoji set for the chat composer picker. Kept inline so the picker
// needs no extra runtime dependency.
const EMOJI_CATEGORIES = [
    {
        label: 'Smileys',
        icon: '😀',
        emojis: ['😀', '😃', '😄', '😁', '😆', '😅', '😂', '🤣', '🥲', '😊', '😇', '🙂', '🙃', '😉', '😌', '😍', '🥰', '😘', '😗', '😋', '😛', '😜', '🤪', '😝', '🤗', '🤭', '🤫', '🤔', '🤐', '😐', '😑', '😶', '😏', '😒', '🙄', '😬', '😮‍💨', '😌', '😔', '😪', '😴', '😷', '🤒', '🤕', '🤢', '🤮', '🥵', '🥶', '😵', '🤯', '🥳', '😎', '🤓', '🧐', '😕', '😟', '🙁', '😮', '😯', '😲', '😳', '🥺', '😦', '😧', '😨', '😰', '😥', '😢', '😭', '😱', '😖', '😣', '😞', '😓', '😩', '😫', '🥱', '😤', '😡', '😠', '🤬'],
    },
    {
        label: 'Gebaren',
        icon: '👍',
        emojis: ['👍', '👎', '👌', '🤌', '🤏', '✌️', '🤞', '🤟', '🤘', '🤙', '👈', '👉', '👆', '👇', '☝️', '✋', '🤚', '🖐️', '🖖', '👋', '🤝', '🙏', '✍️', '💪', '🦾', '👏', '🙌', '👐', '🤲', '🫶', '🫰', '🤛', '🤜', '✊', '👊', '🫵', '💅', '🤳'],
    },
    {
        label: 'Hartjes',
        icon: '❤️',
        emojis: ['❤️', '🧡', '💛', '💚', '💙', '💜', '🤎', '🖤', '🤍', '💔', '❣️', '💕', '💞', '💓', '💗', '💖', '💘', '💝', '💟', '❤️‍🔥', '❤️‍🩹', '💋', '💯', '💢', '💥', '💫', '⭐', '🌟', '✨', '🔥', '🎉', '🎊', '🥂', '🍾'],
    },
    {
        label: 'Dieren',
        icon: '🐶',
        emojis: ['🐶', '🐱', '🐭', '🐹', '🐰', '🦊', '🐻', '🐼', '🐨', '🐯', '🦁', '🐮', '🐷', '🐸', '🐵', '🐔', '🐧', '🐦', '🐤', '🦆', '🦉', '🐝', '🦋', '🐌', '🐢', '🐍', '🐙', '🦀', '🐬', '🐳', '🐋', '🦈', '🐊', '🦮', '🐕', '🐈', '🌵', '🌲', '🌴', '🌷', '🌹', '🌻', '🌼', '🍀', '🍁'],
    },
    {
        label: 'Eten',
        icon: '🍕',
        emojis: ['🍏', '🍎', '🍐', '🍊', '🍋', '🍌', '🍉', '🍇', '🍓', '🫐', '🍒', '🍑', '🥭', '🍍', '🥥', '🥝', '🍅', '🥑', '🥦', '🌽', '🥕', '🍔', '🍟', '🍕', '🌭', '🥪', '🌮', '🌯', '🥗', '🍝', '🍜', '🍣', '🍱', '🍤', '🍦', '🍩', '🍪', '🎂', '🍰', '🧁', '🍫', '🍬', '🍭', '☕', '🍵', '🍺', '🍻', '🥂', '🍷', '🥤'],
    },
    {
        label: 'Activiteit',
        icon: '⚽',
        emojis: ['⚽', '🏀', '🏈', '⚾', '🎾', '🏐', '🏉', '🎱', '🏓', '🏸', '🥅', '⛳', '🏒', '🏑', '🥍', '🏏', '🥊', '🥋', '🎯', '⛸️', '🎿', '🏂', '🏋️', '🤸', '⛹️', '🚴', '🏆', '🥇', '🥈', '🥉', '🏅', '🎖️', '🎮', '🎲', '🎸', '🎺', '🎻', '🎹', '🥁', '🎤', '🎧', '🎬', '🎨'],
    },
    {
        label: 'Reizen',
        icon: '✈️',
        emojis: ['🚗', '🚕', '🚙', '🚌', '🚎', '🏎️', '🚓', '🚑', '🚒', '🚐', '🚚', '🚛', '🚜', '🛵', '🏍️', '🚲', '✈️', '🚀', '🛸', '🚁', '⛵', '🚤', '🛳️', '⚓', '🚂', '🚆', '🚇', '🚊', '🗺️', '🏔️', '🏖️', '🏝️', '🏙️', '🌃', '🌉', '🎡', '🎢', '🏰', '⛺', '🏠'],
    },
    {
        label: 'Symbolen',
        icon: '✅',
        emojis: ['✅', '❌', '❎', '✔️', '☑️', '⚠️', '🚫', '❗', '❓', '❕', '❔', '💤', '💬', '💭', '🗯️', '♻️', '🔔', '🔕', '🔒', '🔓', '⏰', '⌛', '⏳', '📌', '📍', '🔗', '📎', '✂️', '🔍', '🔑', '💡', '📣', '📢', '✉️', '📧', '📅', '📆', '📊', '📈', '💰', '💳', '🎁', '🏁', '🚩', '🆗', '🆕', '🆒', '🔝', '🔜'],
    },
];

/**
 * Find up to six conversation members whose name matches the @mention being
 * typed at the caret. Returns an empty list when the caret isn't in a mention.
 */
function mentionMatches(el, names) {
    if (!el) {
        return [];
    }

    const upto = el.value.slice(0, el.selectionStart);
    const match = upto.match(/@([\w]*)$/);

    if (!match) {
        return [];
    }

    const query = match[1].toLowerCase();

    return names
        .filter((name) => name.toLowerCase().includes(query))
        .slice(0, 6);
}

/**
 * Replace the half-typed @mention at the caret with the chosen name and keep
 * the caret positioned right after it.
 */
function insertMention(el, name) {
    if (!el) {
        return;
    }

    const pos = el.selectionStart;
    const before = el.value.slice(0, pos).replace(/@([\w]*)$/, '@' + name + ' ');
    const after = el.value.slice(pos);

    el.value = before + after;
    el.dispatchEvent(new Event('input', { bubbles: true }));

    const caret = before.length;
    el.setSelectionRange(caret, caret);
    el.focus();
}

/**
 * Reflect the unread message count in the document title — e.g. "(3) Berichten"
 * — so it's visible from another browser tab. The count is server-rendered into
 * a meta tag (fresh on every Livewire navigation) and can also be pushed live by
 * the messages component via an `unread-messages-changed` event.
 */
function unreadMessageCount() {
    const meta = document.querySelector('meta[name="unread-messages"]');

    return meta ? parseInt(meta.content, 10) || 0 : 0;
}

function applyUnreadTitleBadge() {
    const count = unreadMessageCount();
    const base = document.title.replace(/^\(\d+\)\s*/, '');

    document.title = count > 0 ? `(${count}) ${base}` : base;
}

document.addEventListener('DOMContentLoaded', applyUnreadTitleBadge);
document.addEventListener('livewire:navigated', applyUnreadTitleBadge);
window.addEventListener('unread-messages-changed', (event) => {
    const meta = document.querySelector('meta[name="unread-messages"]');

    if (meta && typeof event.detail?.count === 'number') {
        meta.content = String(event.detail.count);
    }

    applyUnreadTitleBadge();
});

// Capture the install prompt as early as possible so the in-app "Installeer
// app" button can fire it later. The event only fires on installable visits
// (served over HTTPS, not already installed) on supporting browsers.
let deferredInstallPrompt = null;

window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    deferredInstallPrompt = event;
    window.dispatchEvent(new CustomEvent('pwa-installable', { detail: { installable: true } }));
});

window.addEventListener('appinstalled', () => {
    deferredInstallPrompt = null;
    window.dispatchEvent(new CustomEvent('pwa-installable', { detail: { installable: false } }));
});

document.addEventListener('alpine:init', () => {
    /**
     * Drives the sidebar "Installeer app" button. It only reveals itself once
     * the browser has offered an install prompt and hides again once installed
     * or when the app is already running in standalone (installed) mode.
     */
    window.Alpine.data('installPrompt', () => ({
        installable: deferredInstallPrompt !== null,

        init() {
            if (window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone) {
                this.installable = false;

                return;
            }

            window.addEventListener('pwa-installable', (event) => {
                this.installable = event.detail.installable;
            });
        },

        async install() {
            if (!deferredInstallPrompt) {
                return;
            }

            deferredInstallPrompt.prompt();
            await deferredInstallPrompt.userChoice;
            deferredInstallPrompt = null;
            this.installable = false;
        },
    }));

    /**
     * WhatsApp-style chat composer: auto-growing textarea, Enter-to-send on
     * devices with a real pointer (Shift+Enter for a newline), an emoji picker
     * and inline @mention autocomplete against the conversation members.
     */
    window.Alpine.data('chatComposer', (names, draftKey = null, conversationId = null, userName = null) => ({
        names,
        draftKey,
        conversationId,
        userName,
        lastWhisperAt: 0,
        open: false,
        matches: [],
        active: 0,
        cmdOpen: false,
        cmdMatches: [],
        cmdActive: 0,
        commands: [
            { name: 'ticket', hint: 'Maak een ticket van de tekst erna' },
            { name: 'task', hint: 'Alias voor /ticket' },
        ],
        emojiOpen: false,
        activeCategory: 0,
        emojiCategories: EMOJI_CATEGORIES,

        get input() {
            return this.$refs.input;
        },

        init() {
            this.loadDraft();
            this.autosize();
        },

        onInput() {
            this.autosize();
            this.detectMention();
            this.detectCommand();
            this.saveDraft();
            this.whisperTyping();
        },

        // Tell other participants we're typing, throttled so we whisper at most
        // once every 1.5s. Best-effort: silently does nothing without Echo.
        whisperTyping() {
            if (!this.conversationId || !window.Echo) {
                return;
            }

            const now = Date.now();

            if (now - this.lastWhisperAt < 1500) {
                return;
            }

            this.lastWhisperAt = now;

            try {
                window.Echo.private(`conversation.${this.conversationId}`).whisper('typing', { name: this.userName });
            } catch (error) {
                // Echo not connected; ignore.
            }
        },

        // Suggest slash commands while the message is just "/word" at the start.
        detectCommand() {
            const value = this.input?.value ?? '';
            const match = value.match(/^\/(\w*)$/);

            if (!match) {
                this.cmdOpen = false;

                return;
            }

            const query = match[1].toLowerCase();

            this.cmdMatches = this.commands.filter((command) => command.name.startsWith(query));
            this.cmdActive = 0;
            this.cmdOpen = this.cmdMatches.length > 0;
        },

        chooseCommand(name) {
            const el = this.input;

            if (!el) {
                return;
            }

            el.value = `/${name} `;
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.focus();
            this.cmdOpen = false;
            this.autosize();
        },

        // Per-conversation draft persistence: a half-typed message survives
        // switching conversations or a page reload, and clears once sent.
        loadDraft() {
            const el = this.input;

            if (!this.draftKey || !el) {
                return;
            }

            el.value = localStorage.getItem(this.draftKey) ?? '';
            el.dispatchEvent(new Event('input', { bubbles: true }));
        },

        saveDraft() {
            if (!this.draftKey) {
                return;
            }

            const value = this.input?.value ?? '';

            if (value === '') {
                localStorage.removeItem(this.draftKey);
            } else {
                localStorage.setItem(this.draftKey, value);
            }
        },

        clearDraft() {
            if (this.draftKey) {
                localStorage.removeItem(this.draftKey);
            }
        },

        // Paste an image/screenshot straight into the composer: hand any
        // clipboard files to the same upload the paper-clip and drag-drop use.
        onPaste(event) {
            const files = Array.from(event.clipboardData?.files ?? []);

            if (files.length === 0) {
                return;
            }

            event.preventDefault();
            this.$wire.$uploadMultiple('newChatAttachments', files);
        },

        autosize() {
            const el = this.input;
            if (!el) {
                return;
            }

            el.style.height = 'auto';
            el.style.height = Math.min(el.scrollHeight, 160) + 'px';
        },

        detectMention() {
            this.matches = mentionMatches(this.input, this.names);
            this.active = 0;
            this.open = this.matches.length > 0;
        },

        onKeydown(event) {
            if (this.cmdOpen) {
                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    this.cmdActive = (this.cmdActive + 1) % this.cmdMatches.length;
                    return;
                }
                if (event.key === 'ArrowUp') {
                    event.preventDefault();
                    this.cmdActive = (this.cmdActive - 1 + this.cmdMatches.length) % this.cmdMatches.length;
                    return;
                }
                if (event.key === 'Enter' || event.key === 'Tab') {
                    event.preventDefault();
                    this.chooseCommand(this.cmdMatches[this.cmdActive].name);
                    return;
                }
                if (event.key === 'Escape') {
                    this.cmdOpen = false;
                    return;
                }
            }

            if (this.open) {
                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    this.active = (this.active + 1) % this.matches.length;
                    return;
                }
                if (event.key === 'ArrowUp') {
                    event.preventDefault();
                    this.active = (this.active - 1 + this.matches.length) % this.matches.length;
                    return;
                }
                if (event.key === 'Enter') {
                    event.preventDefault();
                    this.choose(this.matches[this.active]);
                    return;
                }
                if (event.key === 'Escape') {
                    this.open = false;
                    return;
                }
            }

            // Enter sends on desktop; on touch keyboards Enter adds a newline.
            if (event.key === 'Enter' && !event.shiftKey && this.sendsOnEnter()) {
                event.preventDefault();
                this.$refs.form.requestSubmit();
            }
        },

        sendsOnEnter() {
            return window.matchMedia('(pointer: fine)').matches;
        },

        choose(name) {
            insertMention(this.input, name);
            this.open = false;
            this.autosize();
        },

        toggleEmoji() {
            this.emojiOpen = !this.emojiOpen;
            if (this.emojiOpen) {
                this.open = false;
            }
        },

        insertEmoji(emoji) {
            const el = this.input;
            if (!el) {
                return;
            }

            const start = el.selectionStart;
            const end = el.selectionEnd;

            el.value = el.value.slice(0, start) + emoji + el.value.slice(end);
            el.dispatchEvent(new Event('input', { bubbles: true }));

            const caret = start + emoji.length;
            el.setSelectionRange(caret, caret);
            el.focus();
            this.autosize();
        },

        // Called after a message is sent: drop the saved draft, clear the grown
        // height and refocus.
        reset() {
            this.open = false;
            this.cmdOpen = false;
            this.emojiOpen = false;
            this.clearDraft();
            this.$nextTick(() => {
                this.autosize();
                this.input?.focus();
            });
        },
    }));

    /**
     * Standalone @mention autocomplete for a single textarea (e.g. the task
     * comment box). Reuses the chat composer's detection and insertion so both
     * inputs behave identically, including on touch devices.
     */
    window.Alpine.data('mentionField', (names) => ({
        names,
        open: false,
        matches: [],
        active: 0,

        get input() {
            return this.$refs.input;
        },

        onInput() {
            this.matches = mentionMatches(this.input, this.names);
            this.active = 0;
            this.open = this.matches.length > 0;
        },

        onKeydown(event) {
            if (!this.open) {
                return;
            }

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                this.active = (this.active + 1) % this.matches.length;
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                this.active = (this.active - 1 + this.matches.length) % this.matches.length;
            } else if (event.key === 'Enter') {
                event.preventDefault();
                this.choose(this.matches[this.active]);
            } else if (event.key === 'Escape') {
                this.open = false;
            }
        },

        choose(name) {
            insertMention(this.input, name);
            this.open = false;
        },
    }));

    /**
     * Web-push opt-in for the current device: registers a Push API subscription
     * with the service worker and syncs it to the server, or removes it again.
     */
    window.Alpine.data('pushToggle', (config) => ({
        publicKey: config.publicKey || '',
        storeUrl: config.storeUrl,
        destroyUrl: config.destroyUrl,
        csrf: config.csrf,
        supported: false,
        subscribed: false,
        busy: false,
        denied: false,
        error: false,

        async init() {
            this.supported = 'serviceWorker' in navigator
                && 'PushManager' in window
                && 'Notification' in window;

            if (!this.supported) {
                return;
            }

            this.denied = Notification.permission === 'denied';

            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.getSubscription();
            this.subscribed = subscription !== null;
        },

        async enable() {
            if (this.busy || !this.supported) {
                return;
            }

            this.busy = true;
            this.error = false;

            try {
                if (!this.publicKey) {
                    throw new Error('Missing VAPID public key; push is not configured on the server.');
                }

                const permission = await Notification.requestPermission();
                this.denied = permission === 'denied';

                if (permission !== 'granted') {
                    return;
                }

                const registration = await navigator.serviceWorker.ready;
                const subscription = await registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: this.urlBase64ToUint8Array(this.publicKey),
                });

                const payload = subscription.toJSON();

                const response = await fetch(this.storeUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf },
                    body: JSON.stringify({ endpoint: payload.endpoint, keys: payload.keys }),
                });

                if (!response.ok) {
                    throw new Error(`Storing the push subscription failed (HTTP ${response.status}).`);
                }

                this.subscribed = true;
            } catch (error) {
                console.error('Enabling push notifications failed:', error);
                this.error = true;
                this.subscribed = false;
            } finally {
                this.busy = false;
            }
        },

        async disable() {
            if (this.busy || !this.supported) {
                return;
            }

            this.busy = true;

            try {
                const registration = await navigator.serviceWorker.ready;
                const subscription = await registration.pushManager.getSubscription();

                if (subscription) {
                    await fetch(this.destroyUrl, {
                        method: 'DELETE',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf },
                        body: JSON.stringify({ endpoint: subscription.endpoint }),
                    });

                    await subscription.unsubscribe();
                }

                this.subscribed = false;
            } finally {
                this.busy = false;
            }
        },

        urlBase64ToUint8Array(base64String) {
            const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
            const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
            const raw = window.atob(base64);
            const output = new Uint8Array(raw.length);
            for (let i = 0; i < raw.length; ++i) {
                output[i] = raw.charCodeAt(i);
            }
            return output;
        },
    }));

    /**
     * Drag-and-drop attachments onto the open chat. Reacts to file drags only
     * (clicks fall through to messages and buttons untouched) and hands the
     * dropped files to the composer's `newChatAttachments` upload so they land
     * in the same pending tray as the paper-clip picker. A depth counter keeps
     * the overlay stable while dragging across nested child elements.
     */
    window.Alpine.data('chatDropzone', () => ({
        dragging: false,
        depth: 0,

        onDragenter(event) {
            if (!this.hasFiles(event) || this.$wire.conversationId === null) {
                return;
            }

            this.depth++;
            this.dragging = true;
        },

        onDragleave() {
            this.depth = Math.max(0, this.depth - 1);

            if (this.depth === 0) {
                this.dragging = false;
            }
        },

        onDrop(event) {
            this.depth = 0;
            this.dragging = false;

            if (this.$wire.conversationId === null) {
                return;
            }

            const files = Array.from(event.dataTransfer?.files ?? []);

            if (files.length === 0) {
                return;
            }

            this.$wire.$uploadMultiple('newChatAttachments', files);
        },

        hasFiles(event) {
            return Array.from(event.dataTransfer?.types ?? []).includes('Files');
        },
    }));

    /**
     * Message thread: stays pinned to the newest message, but leaves the
     * scroll position alone while the user is reading older history.
     */
    window.Alpine.data('chatThread', (conversationId = null) => ({
        pinned: true,
        hasNew: false,
        loadingOlder: false,
        typingName: null,
        typingTimeout: null,

        init() {
            this.scrollToBottom();
            this.listenForTyping(conversationId);

            this.observer = new MutationObserver(() => {
                // While prepending an older page, loadOlder() restores the
                // scroll position itself — don't treat it as new activity.
                if (this.loadingOlder) {
                    return;
                }

                if (this.pinned) {
                    this.scrollToBottom();
                } else {
                    // New content arrived while the user is reading history;
                    // flag it so the jump-to-bottom button can announce it.
                    this.hasNew = true;
                }
            });
            this.observer.observe(this.$el, { childList: true, subtree: true });

            this.$el.addEventListener('scroll', () => {
                const distance = this.$el.scrollHeight - this.$el.scrollTop - this.$el.clientHeight;
                this.pinned = distance < 120;

                if (this.pinned) {
                    this.hasNew = false;
                }
            });
        },

        destroy() {
            this.observer?.disconnect();
            clearTimeout(this.typingTimeout);
        },

        // Show a "… is typing" hint when another participant whispers on the
        // conversation channel. No-ops cleanly when Reverb/Echo isn't connected.
        listenForTyping(conversationId) {
            if (!conversationId || !window.Echo) {
                return;
            }

            try {
                window.Echo.private(`conversation.${conversationId}`).listenForWhisper('typing', (event) => {
                    this.typingName = event?.name ?? null;
                    clearTimeout(this.typingTimeout);
                    this.typingTimeout = setTimeout(() => {
                        this.typingName = null;
                    }, 3000);

                    if (this.pinned) {
                        this.scrollToBottom();
                    }
                });
            } catch (error) {
                // Echo not available; typing hints are a best-effort enhancement.
            }
        },

        // Load an older page and keep the viewport anchored: the messages the
        // user is reading stay put while content grows above them.
        loadOlder() {
            if (this.loadingOlder) {
                return;
            }

            this.loadingOlder = true;
            const previousHeight = this.$el.scrollHeight;

            this.$wire.loadOlder().then(() => {
                this.$nextTick(() => {
                    this.$el.scrollTop = this.$el.scrollHeight - previousHeight;
                    this.loadingOlder = false;
                });
            });
        },

        scrollToBottom() {
            this.$nextTick(() => {
                this.$el.scrollTop = this.$el.scrollHeight;
            });
        },
    }));
});

/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel. Echo and event broadcasting
 * allow your team to quickly build robust real-time web applications.
 */

import './echo';
