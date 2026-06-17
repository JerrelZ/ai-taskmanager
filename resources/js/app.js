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

document.addEventListener('alpine:init', () => {
    /**
     * WhatsApp-style chat composer: auto-growing textarea, Enter-to-send on
     * devices with a real pointer (Shift+Enter for a newline), an emoji picker
     * and inline @mention autocomplete against the conversation members.
     */
    window.Alpine.data('chatComposer', (names) => ({
        names,
        open: false,
        matches: [],
        active: 0,
        emojiOpen: false,
        activeCategory: 0,
        emojiCategories: EMOJI_CATEGORIES,

        get input() {
            return this.$refs.input;
        },

        init() {
            this.autosize();
        },

        onInput() {
            this.autosize();
            this.detectMention();
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
            const el = this.input;
            if (!el) {
                return;
            }

            const upto = el.value.slice(0, el.selectionStart);
            const match = upto.match(/@([\w]*)$/);

            if (!match) {
                this.open = false;
                return;
            }

            const query = match[1].toLowerCase();
            this.matches = this.names
                .filter((name) => name.toLowerCase().includes(query))
                .slice(0, 6);
            this.active = 0;
            this.open = this.matches.length > 0;
        },

        onKeydown(event) {
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
            const el = this.input;
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

        // Called after a message is sent: clear the grown height and refocus.
        reset() {
            this.open = false;
            this.emojiOpen = false;
            this.$nextTick(() => {
                this.autosize();
                this.input?.focus();
            });
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
     * Message thread: stays pinned to the newest message, but leaves the
     * scroll position alone while the user is reading older history.
     */
    window.Alpine.data('chatThread', () => ({
        pinned: true,

        init() {
            this.scrollToBottom();

            this.observer = new MutationObserver(() => {
                if (this.pinned) {
                    this.scrollToBottom();
                }
            });
            this.observer.observe(this.$el, { childList: true, subtree: true });

            this.$el.addEventListener('scroll', () => {
                const distance = this.$el.scrollHeight - this.$el.scrollTop - this.$el.clientHeight;
                this.pinned = distance < 120;
            });
        },

        destroy() {
            this.observer?.disconnect();
        },

        scrollToBottom() {
            this.$nextTick(() => {
                this.$el.scrollTop = this.$el.scrollHeight;
            });
        },
    }));
});
