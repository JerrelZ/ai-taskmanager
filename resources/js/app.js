document.addEventListener('alpine:init', () => {
    window.Alpine.data('mentionAutocomplete', (names) => ({
        names,
        open: false,
        matches: [],
        active: 0,

        get input() {
            return this.$el.querySelector('textarea');
        },

        onInput() {
            const el = this.input;
            if (!el) return;

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
            if (!this.open) return;

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
            const el = this.input;
            if (!el) return;

            const pos = el.selectionStart;
            const before = el.value.slice(0, pos).replace(/@([\w]*)$/, '@' + name + ' ');
            const after = el.value.slice(pos);

            el.value = before + after;
            el.dispatchEvent(new Event('input', { bubbles: true }));

            const caret = before.length;
            el.setSelectionRange(caret, caret);
            el.focus();
            this.open = false;
        },
    }));
});
