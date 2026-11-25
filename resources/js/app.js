// Alpine ships with Livewire, so components are registered on its init event instead of importing it.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('searchBox', () => ({
        open: false,
        active: -1,
        wide: window.matchMedia('(min-width: 1024px)').matches,
        pointer: null,

        init() {
            const query = window.matchMedia('(min-width: 1024px)');
            const id = this.$root.getAttribute('wire:id');

            query.addEventListener('change', (event) => {
                this.wide = event.matches;
            });

            // A morph puts the server's aria-selected back, so the keyboard selection starts over with each new list.
            window.Livewire.hook('morphed', ({ component }) => {
                if (component.id === id && this.$root.isConnected) {
                    this.active = -1;
                    this.highlight();
                }
            });
        },

        // The rows belong to the query the listbox was rendered for. While the input has moved on they are stale,
        // so the keyboard cannot select them.
        options() {
            const listbox = this.$root.querySelector('[role="listbox"]');

            if (!listbox || listbox.dataset.query !== this.$refs.input.value.trim()) {
                return [];
            }

            return Array.from(listbox.querySelectorAll('[data-option]'));
        },

        openBox() {
            this.open = true;
        },

        close() {
            this.open = false;
            this.active = -1;
            this.highlight();
        },

        move(step) {
            const count = this.options().length;

            if (count === 0) {
                return;
            }

            this.openBox();

            if (this.active === -1) {
                this.active = step > 0 ? 0 : count - 1;
            } else {
                this.active = (this.active + step + count) % count;
            }

            this.highlight(true);
        },

        // Mouse movement only: a row scrolling under a still pointer must not steal the keyboard selection.
        hover(event) {
            if (this.pointer && this.pointer[0] === event.clientX && this.pointer[1] === event.clientY) {
                return;
            }

            this.pointer = [event.clientX, event.clientY];

            const index = this.options().indexOf(event.target.closest('[data-option]'));

            if (index !== -1 && index !== this.active) {
                this.active = index;
                this.highlight();
            }
        },

        highlight(scroll = false) {
            this.$root.querySelectorAll('[data-option]').forEach((option, index) => {
                option.setAttribute('aria-selected', index === this.active ? 'true' : 'false');

                if (scroll && index === this.active) {
                    option.scrollIntoView({ block: 'nearest' });
                }
            });
        },

        // Enter opens the highlighted row. With none highlighted it goes where the query points: the card or set a
        // code names, otherwise the results page for the text.
        openActive(event) {
            if (event.isComposing) {
                return;
            }

            const options = this.options();

            if (this.active >= 0 && options[this.active]) {
                event.preventDefault();
                options[this.active].click();

                return;
            }

            const term = this.$refs.input.value.trim();

            if (term === '') {
                return;
            }

            event.preventDefault();

            const listbox = this.$root.querySelector('[role="listbox"]');
            const jump = listbox && listbox.dataset.query === term ? listbox.dataset.enter : '';

            this.$wire.remember(term).finally(() => {
                window.location.href = jump || `${this.$refs.input.dataset.searchUrl}?q=${encodeURIComponent(term)}`;
            });
        },

        // Rows are real links. Remember the search first, then follow the link, so the request is not cancelled by the navigation.
        follow(event) {
            const link = event.target.closest('a[data-option]');

            if (!link) {
                return;
            }

            const term = this.$refs.input.value;

            if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) {
                this.$wire.remember(term);

                return;
            }

            event.preventDefault();
            this.$wire.remember(term).finally(() => {
                window.location.href = link.href;
            });
        },

        focusOnSlash(event) {
            const target = event.target;
            const typing = target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName);

            if (event.key === '/' && !typing && !event.metaKey && !event.ctrlKey && !event.altKey) {
                event.preventDefault();
                this.$refs.input.focus();
            }
        },
    }));
});

// The filter panel is a sidebar on a desktop and a bottom sheet on a phone. A choice applies as it is made, so
// the sheet's button can say how many results it holds; "cancel" puts back what was there when the sheet opened.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('filterSheet', () => ({
        open: false,
        wide: window.matchMedia('(min-width: 1024px)').matches,
        snapshot: null,

        init() {
            const query = window.matchMedia('(min-width: 1024px)');

            query.addEventListener('change', (event) => {
                this.wide = event.matches;

                if (event.matches) {
                    this.open = false;
                    this.snapshot = null;
                }
            });
        },

        show() {
            const parent = this.$wire.$parent;

            this.snapshot = Object.fromEntries(
                JSON.parse(this.$root.dataset.keys).map((key) => [key, JSON.parse(JSON.stringify(parent.$get(key) ?? null))]),
            );
            this.open = true;
            // The sheet takes focus itself, not its first field: a phone keyboard must not open over the filters.
            this.$nextTick(() => this.$refs.dialog.focus());
        },

        confirm() {
            this.open = false;
            this.snapshot = null;
        },

        cancel() {
            if (!this.open || this.wide) {
                return;
            }

            this.$wire.$parent.restore(this.snapshot ?? {});
            this.confirm();
        },
    }));
});

// A button that copies a fixed text and says so for two seconds. The text is a data attribute, never user input.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('copyButton', () => ({
        copied: false,
        timer: null,

        async copy() {
            try {
                await navigator.clipboard.writeText(this.$root.dataset.copy);
                this.copied = true;
                clearTimeout(this.timer);
                this.timer = setTimeout(() => {
                    this.copied = false;
                }, 2000);
            } catch (error) {
                this.copied = false;
            }
        },
    }));
});

// "Save QR": where the browser can share files (iOS, Android) the PNG goes to the share sheet, so it can be saved to
// Photos; anywhere else the link downloads it as the attachment it is.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('saveQr', () => ({
        async save(event) {
            // Only phones need the share sheet (it is how a QR reaches Photos for a bank app); a desktop browser may
            // expose navigator.share and then never settle it, so there the link simply downloads.
            if (!navigator.share || !navigator.canShare || !window.matchMedia('(pointer: coarse)').matches) {
                return;
            }

            event.preventDefault();
            const url = this.$root.href;

            try {
                const response = await fetch(url, { credentials: 'same-origin' });
                const file = new File([await response.blob()], 'promptpay.png', { type: 'image/png' });

                if (!response.ok) {
                    // The QR is gone (paid, cancelled, slip sent elsewhere): stay on the page and let it catch up.
                    this.$wire?.$refresh();

                    return;
                }

                if (!navigator.canShare({ files: [file] })) {
                    window.location.href = url;

                    return;
                }

                await navigator.share({ files: [file] });
            } catch (error) {
                if (error.name !== 'AbortError') {
                    this.$wire?.$refresh();
                }
            }
        },
    }));
});
