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
