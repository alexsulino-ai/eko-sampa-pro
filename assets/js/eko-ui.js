/**
 * Eko Sampa shared UI infrastructure (before frontend-app.js, before Alpine).
 *
 * @package Eko_Sampa
 */
(function () {
    'use strict';

    /** @type {Record<string, number>} */
    var LAYERS = {
        base: 0,
        sticky: 30,
        dropdown: 40,
        tooltip: 50,
        toast: 60,
        modal: 100,
        modalNested: 110,
        popover: 120,
    };

    window.ekoSampaLayers = {
        values: LAYERS,
        z: function (name, stackOffset) {
            var base = LAYERS[name] != null ? LAYERS[name] : LAYERS.modal;
            var off = stackOffset != null ? Number(stackOffset) : 0;
            return base + (isNaN(off) ? 0 : off);
        },
        cssVar: function (name) {
            var map = {
                modal: '--eko-z-modal',
                modalNested: '--eko-z-modal-nested',
                dropdown: '--eko-z-dropdown',
                tooltip: '--eko-z-tooltip',
                toast: '--eko-z-toast',
            };
            return map[name] || '--eko-z-modal';
        },
    };

    /**
     * Lightweight pub/sub for cross-module UI (modal saved, fields updated, etc.).
     */
    var eventBus = {
        _handlers: Object.create(null),
        on: function (event, fn) {
            if (!event || typeof fn !== 'function') {
                return function () {};
            }
            if (!this._handlers[event]) {
                this._handlers[event] = [];
            }
            this._handlers[event].push(fn);
            var self = this;
            return function () {
                self.off(event, fn);
            };
        },
        off: function (event, fn) {
            if (!this._handlers[event]) {
                return;
            }
            if (!fn) {
                delete this._handlers[event];
                return;
            }
            this._handlers[event] = this._handlers[event].filter(function (f) {
                return f !== fn;
            });
        },
        emit: function (event, payload) {
            var list = this._handlers[event];
            if (!list || !list.length) {
                return;
            }
            list.slice().forEach(function (fn) {
                try {
                    fn(payload);
                } catch (e) {
                    void e;
                }
            });
        },
    };
    window.ekoSampaEventBus = eventBus;

    var FOCUSABLE =
        'a[href], area[href], input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), button:not([disabled]), [tabindex]:not([tabindex="-1"])';

    function getFocusable(container) {
        if (!container) {
            return [];
        }
        return Array.prototype.filter.call(container.querySelectorAll(FOCUSABLE), function (el) {
            return el.offsetParent !== null || el === document.activeElement;
        });
    }

    function createFocusTrap(getContainer) {
        var previous = null;
        function onKeydown(e) {
            if (e.key !== 'Tab') {
                return;
            }
            var root = typeof getContainer === 'function' ? getContainer() : getContainer;
            if (!root) {
                return;
            }
            var nodes = getFocusable(root);
            if (!nodes.length) {
                e.preventDefault();
                return;
            }
            var first = nodes[0];
            var last = nodes[nodes.length - 1];
            if (e.shiftKey) {
                if (document.activeElement === first) {
                    e.preventDefault();
                    last.focus();
                }
            } else if (document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        }
        return {
            activate: function () {
                previous = document.activeElement;
                document.addEventListener('keydown', onKeydown, true);
            },
            deactivate: function () {
                document.removeEventListener('keydown', onKeydown, true);
                if (previous && typeof previous.focus === 'function') {
                    try {
                        previous.focus();
                    } catch (err) {
                        void err;
                    }
                }
                previous = null;
            },
            focusFirst: function () {
                var root = typeof getContainer === 'function' ? getContainer() : getContainer;
                var nodes = getFocusable(root);
                if (nodes.length) {
                    nodes[0].focus();
                } else if (root && root.focus) {
                    root.focus();
                }
            },
        };
    }

    var scrollLockCount = 0;
    var savedBodyPadding = '';

    function lockBodyScroll(lock) {
        var html = document.documentElement;
        var body = document.body;
        if (!html || !body) {
            return;
        }
        if (lock) {
            scrollLockCount++;
            if (scrollLockCount > 1) {
                return;
            }
            var gap = window.innerWidth - html.clientWidth;
            savedBodyPadding = body.style.paddingRight || '';
            if (gap > 0) {
                body.style.paddingRight = gap + 'px';
            }
            html.classList.add('eko-modal-open');
            return;
        }
        scrollLockCount = Math.max(0, scrollLockCount - 1);
        if (scrollLockCount > 0) {
            return;
        }
        html.classList.remove('eko-modal-open');
        body.style.paddingRight = savedBodyPadding;
        savedBodyPadding = '';
    }

  var dirtyUnloadBound = false;
    function bindDirtyUnload() {
        if (dirtyUnloadBound) {
            return;
        }
        dirtyUnloadBound = true;
        window.addEventListener('beforeunload', function (e) {
            if (!modalService.isAnyDirty()) {
                return;
            }
            e.preventDefault();
            e.returnValue = '';
        });
    }

    var modalService = {
        _stack: [],
        _listeners: [],
        _trap: null,
        _panelGetter: null,

        setPanelGetter: function (fn) {
            this._panelGetter = typeof fn === 'function' ? fn : null;
        },

        isAnyDirty: function () {
            return this._stack.some(function (entry) {
                return entry && entry.dirty;
            });
        },

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

        _layerZ: function () {
            var depth = this._stack.length;
            return window.ekoSampaLayers.z(depth > 1 ? 'modalNested' : 'modal', depth > 1 ? (depth - 1) * 2 : 0);
        },

        _activateTrap: function () {
            var self = this;
            if (!this._trap) {
                this._trap = createFocusTrap(function () {
                    return self._panelGetter ? self._panelGetter() : document.querySelector('.eko-modal-panel');
                });
            }
            this._trap.activate();
            requestAnimationFrame(function () {
                self._trap.focusFirst();
            });
        },

        _deactivateTrap: function () {
            if (this._trap) {
                this._trap.deactivate();
            }
        },

        markDirty: function (dirty) {
            var top = this.current();
            if (!top) {
                return;
            }
            if (typeof top.isDirtyFn === 'function') {
                top.dirty = !!top.isDirtyFn();
            } else {
                top.dirty = !!dirty;
            }
        },

        /**
         * @param {object} opts
         */
        open: function (opts) {
            opts = opts || {};
            bindDirtyUnload();
            var entry = {
                id: Date.now() + Math.random(),
                title: opts.title != null ? String(opts.title) : '',
                saveLabel: opts.saveLabel != null ? String(opts.saveLabel) : 'Save',
                cancelLabel: opts.cancelLabel != null ? String(opts.cancelLabel) : 'Cancel',
                closeOnBackdrop: opts.closeOnBackdrop !== false,
                closeOnEscape: opts.closeOnEscape !== false,
                saving: false,
                error: null,
                dirty: false,
                isDirtyFn: typeof opts.isDirty === 'function' ? opts.isDirty : null,
                onSave: typeof opts.onSave === 'function' ? opts.onSave : null,
                onCancel: typeof opts.onCancel === 'function' ? opts.onCancel : null,
                onClose: typeof opts.onClose === 'function' ? opts.onClose : null,
                zIndex: this._layerZ(),
            };
            this._stack.push(entry);
            lockBodyScroll(true);
            this._notify();
            var self = this;
            requestAnimationFrame(function () {
                self._activateTrap();
            });
            return entry.id;
        },

        _requestClose: function () {
            var top = this.current();
            if (!top) {
                return true;
            }
            if (top.saving) {
                return false;
            }
            if (typeof top.isDirtyFn === 'function' && top.isDirtyFn()) {
                if (!window.confirm('You have unsaved changes. Discard them?')) {
                    return false;
                }
            }
            return true;
        },

        close: function (force) {
            if (!force && !this._requestClose()) {
                return;
            }
            var top = this._stack.pop();
            if (top && typeof top.onClose === 'function') {
                try {
                    top.onClose();
                } catch (e) {
                    void e;
                }
            }
            if (!this._stack.length) {
                this._deactivateTrap();
                lockBodyScroll(false);
            } else {
                var self = this;
                requestAnimationFrame(function () {
                    self._activateTrap();
                });
            }
            this._notify();
        },

        cancel: function () {
            if (!this._requestClose()) {
                return;
            }
            var top = this.current();
            if (top && typeof top.onCancel === 'function') {
                try {
                    top.onCancel();
                } catch (e) {
                    void e;
                }
            }
            this.close(true);
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
            if (!top || typeof top.onSave !== 'function' || top.saving) {
                return;
            }
            this.setSaving(true);
            this.setError(null);
            try {
                await top.onSave();
                top.dirty = false;
                this.close(true);
                eventBus.emit('eko:modal:saved', { id: top.id, title: top.title });
            } catch (e) {
                this.setError(e && e.message ? e.message : String(e));
            } finally {
                this.setSaving(false);
            }
        },
    };

    window.ekoSampaModalService = modalService;

    /**
     * Field Definition (schema) vs Field Instance (draft while editing).
     */
    window.ekoSampaFieldSchema = {
        emptyDraft: function (serviceId, sortOrder) {
            var sid = parseInt(String(serviceId || 0), 10) || 0;
            return {
                meta: { id: 0, service_id: sid, sort_order: sortOrder != null ? Number(sortOrder) : 0 },
                definition: {
                    label: '',
                    slug: '',
                    type: 'text',
                    required: 0,
                    options_json: null,
                    default_value: '',
                    placeholder: '',
                    show_in_template: 1,
                    validation: window.ekoSampaFieldValidation
                        ? window.ekoSampaFieldValidation.emptyValidationState()
                        : {},
                    _validationLegacyRaw: null,
                },
            };
        },

        fromApiRow: function (row, serviceId) {
            if (!row || typeof row !== 'object') {
                return this.emptyDraft(serviceId, 0);
            }
            var req = row.required == null ? 0 : parseInt(String(row.required), 10) ? 1 : 0;
            var sit = row.show_in_template == null ? 1 : parseInt(String(row.show_in_template), 10) ? 1 : 0;
            var opt = row.options_json;
            if (opt != null && opt !== '' && typeof opt === 'object') {
                try {
                    opt = JSON.stringify(opt);
                } catch (e) {
                    opt = '';
                }
            } else if (opt != null && typeof opt !== 'string') {
                opt = String(opt);
            }
            var vr = window.ekoSampaFieldValidation
                ? window.ekoSampaFieldValidation.parseFieldRow(row)
                : { validation: {}, _validationLegacyRaw: null };
            return {
                meta: {
                    id: row.id != null ? Number(row.id) : 0,
                    service_id: serviceId != null ? Number(serviceId) : Number(row.service_id || 0),
                    sort_order: row.sort_order != null ? Number(row.sort_order) : 0,
                },
                definition: {
                    label: row.label != null ? String(row.label) : '',
                    slug: row.slug != null ? String(row.slug) : '',
                    type: row.type != null ? String(row.type) : 'text',
                    required: req,
                    options_json: opt != null && opt !== '' ? opt : row.type === 'select' ? '[]' : null,
                    default_value: row.default_value != null ? String(row.default_value) : '',
                    placeholder: row.placeholder != null ? String(row.placeholder) : '',
                    show_in_template: sit,
                    validation: vr.validation,
                    _validationLegacyRaw: vr._validationLegacyRaw,
                },
            };
        },

        snapshot: function (draft) {
            try {
                return JSON.stringify(draft);
            } catch (e) {
                return '';
            }
        },

        isDirty: function (baseline, draft) {
            if (!baseline) {
                return false;
            }
            return this.snapshot(draft) !== baseline;
        },

        /** Flat shape for forms that still bind fieldForm (legacy compat). */
        toFlat: function (draft) {
            if (!draft || !draft.definition) {
                return {};
            }
            return Object.assign({}, draft.definition, draft.meta || {});
        },

        fromFlat: function (flat) {
            if (!flat || typeof flat !== 'object') {
                return this.emptyDraft(0, 0);
            }
            return {
                meta: {
                    id: flat.id != null ? Number(flat.id) : 0,
                    service_id: flat.service_id != null ? Number(flat.service_id) : 0,
                    sort_order: flat.sort_order != null ? Number(flat.sort_order) : 0,
                },
                definition: {
                    label: flat.label != null ? String(flat.label) : '',
                    slug: flat.slug != null ? String(flat.slug) : '',
                    type: flat.type != null ? String(flat.type) : 'text',
                    required: flat.required != null ? flat.required : 0,
                    options_json: flat.options_json,
                    default_value: flat.default_value != null ? String(flat.default_value) : '',
                    placeholder: flat.placeholder != null ? String(flat.placeholder) : '',
                    show_in_template: flat.show_in_template != null ? flat.show_in_template : 1,
                    validation: flat.validation || {},
                    _validationLegacyRaw: flat._validationLegacyRaw != null ? flat._validationLegacyRaw : null,
                },
            };
        },
    };

    window.ekoUiStatesMixin = function () {
        return {
            uiLoading: false,
            uiError: null,
            setUiLoading: function (v) {
                this.uiLoading = !!v;
            },
            setUiError: function (msg) {
                this.uiError = msg != null && String(msg) !== '' ? String(msg) : null;
            },
            clearUiError: function () {
                this.uiError = null;
            },
        };
    };

    window.ekoModalMixin = function () {
        return {
            modalLayer: { active: null },
            _ekoModalUnsub: null,

            initEkoModalLayer: function () {
                if (this._ekoModalUnsub) {
                    return;
                }
                var self = this;
                window.ekoSampaModalService.setPanelGetter(function () {
                    return self.$refs && self.$refs.ekoModalPanel ? self.$refs.ekoModalPanel : null;
                });
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

            modalZStyle: function () {
                var m = this.modalLayer.active;
                if (!m || m.zIndex == null) {
                    return '';
                }
                return 'z-index:' + m.zIndex;
            },
        };
    };
})();
