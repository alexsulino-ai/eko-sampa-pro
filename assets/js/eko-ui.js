/**
 * Eko Sampa shared UI: modal service + Alpine mixin (load before frontend-app.js, before Alpine).
 *
 * @package Eko_Sampa
 */
(function () {
    'use strict';

    /**
     * Global modal stack (one visible layer; extensible for nested modals later).
     */
    var modalService = {
        _stack: [],
        _listeners: [],

        current: function () {
            if (!this._stack.length) {
                return null;
            }
            return this._stack[this._stack.length - 1];
        },

        subscribe: function (fn) {
            if (typeof fn !== 'function') {
                return function () {};
            }
            this._listeners.push(fn);
            try {
                fn(this.current());
            } catch (e) {
                void e;
            }
            var self = this;
            return function () {
                self._listeners = self._listeners.filter(function (f) {
                    return f !== fn;
                });
            };
        },

        _notify: function () {
            var state = this.current();
            this._listeners.forEach(function (fn) {
                try {
                    fn(state);
                } catch (e) {
                    void e;
                }
            });
        },

        _lockBody: function (on) {
            var root = document.documentElement;
            if (!root) {
                return;
            }
            if (on) {
                root.classList.add('eko-modal-open');
            } else if (!this._stack.length) {
                root.classList.remove('eko-modal-open');
            }
        },

        /**
         * @param {object} opts
         * @param {string} [opts.title]
         * @param {string} [opts.saveLabel]
         * @param {string} [opts.cancelLabel]
         * @param {boolean} [opts.closeOnBackdrop]
         * @param {boolean} [opts.closeOnEscape]
         * @param {function(): (void|Promise<void>)} [opts.onSave]
         * @param {function(): void} [opts.onCancel]
         * @param {function(): void} [opts.onClose]
         * @returns {number}
         */
        open: function (opts) {
            opts = opts || {};
            var entry = {
                id: Date.now() + Math.random(),
                title: opts.title != null ? String(opts.title) : '',
                saveLabel: opts.saveLabel != null ? String(opts.saveLabel) : 'Save',
                cancelLabel: opts.cancelLabel != null ? String(opts.cancelLabel) : 'Cancel',
                closeOnBackdrop: opts.closeOnBackdrop !== false,
                closeOnEscape: opts.closeOnEscape !== false,
                saving: false,
                error: null,
                onSave: typeof opts.onSave === 'function' ? opts.onSave : null,
                onCancel: typeof opts.onCancel === 'function' ? opts.onCancel : null,
                onClose: typeof opts.onClose === 'function' ? opts.onClose : null,
            };
            this._stack.push(entry);
            this._lockBody(true);
            this._notify();
            return entry.id;
        },

        close: function () {
            var top = this._stack.pop();
            if (top && typeof top.onClose === 'function') {
                try {
                    top.onClose();
                } catch (e) {
                    void e;
                }
            }
            this._lockBody(false);
            this._notify();
        },

        cancel: function () {
            var top = this.current();
            if (top && typeof top.onCancel === 'function') {
                try {
                    top.onCancel();
                } catch (e) {
                    void e;
                }
            }
            this.close();
        },

        setSaving: function (value) {
            var top = this.current();
            if (!top) {
                return;
            }
            top.saving = !!value;
            this._notify();
        },

        setError: function (message) {
            var top = this.current();
            if (!top) {
                return;
            }
            top.error = message != null && String(message) !== '' ? String(message) : null;
            this._notify();
        },

        confirm: async function () {
            var top = this.current();
            if (!top || typeof top.onSave !== 'function') {
                return;
            }
            this.setSaving(true);
            this.setError(null);
            try {
                await top.onSave();
                this.close();
            } catch (e) {
                this.setError(e && e.message ? e.message : String(e));
            } finally {
                this.setSaving(false);
            }
        },
    };

    window.ekoSampaModalService = modalService;

    /**
     * Alpine mixin: subscribe to modal service + helpers.
     */
    window.ekoModalMixin = function () {
        return {
            modalLayer: { active: null },
            _ekoModalUnsub: null,

            initEkoModalLayer: function () {
                if (this._ekoModalUnsub) {
                    return;
                }
                var self = this;
                this._ekoModalUnsub = window.ekoSampaModalService.subscribe(function (state) {
                    self.modalLayer.active = state;
                });
            },

            openEkoModal: function (opts) {
                this.initEkoModalLayer();
                return window.ekoSampaModalService.open(opts);
            },

            closeEkoModal: function () {
                window.ekoSampaModalService.close();
            },

            cancelEkoModal: function () {
                window.ekoSampaModalService.cancel();
            },

            confirmEkoModal: function () {
                return window.ekoSampaModalService.confirm();
            },

            onEkoModalBackdropClick: function () {
                var m = this.modalLayer.active;
                if (m && m.closeOnBackdrop && !m.saving) {
                    this.cancelEkoModal();
                }
            },

            onEkoModalEscape: function () {
                var m = this.modalLayer.active;
                if (m && m.closeOnEscape && !m.saving) {
                    this.cancelEkoModal();
                }
            },
        };
    };
})();
