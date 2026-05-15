/**
 * Global toast queue + EventBus integration.
 *
 * @package Eko_Sampa
 */
(function () {
    'use strict';

    var VARIANTS = {
        success: { icon: '✓', className: 'eko-toast--success' },
        error: { icon: '✕', className: 'eko-toast--error' },
        warning: { icon: '!', className: 'eko-toast--warning' },
        info: { icon: 'i', className: 'eko-toast--info' },
        loading: { icon: '…', className: 'eko-toast--loading' },
    };

    var toastService = {
        _queue: [],
        _host: null,
        _maxVisible: 4,
        _defaultDuration: 4200,

        _ensureHost: function () {
            if (this._host && document.body.contains(this._host)) {
                return this._host;
            }
            var el = document.getElementById('eko-toast-host');
            if (!el) {
                el = document.createElement('div');
                el.id = 'eko-toast-host';
                el.className = 'eko-toast-host eko-layer-toast';
                el.setAttribute('aria-live', 'polite');
                el.setAttribute('aria-relevant', 'additions');
                document.body.appendChild(el);
            }
            this._host = el;
            return el;
        },

        /**
         * @param {object} opts
         * @param {string} [opts.type] success|error|warning|info|loading
         * @param {string} opts.message
         * @param {string} [opts.title]
         * @param {number} [opts.duration] ms; 0 = sticky (loading)
         * @param {string} [opts.id] dedupe id
         * @returns {string} toast id
         */
        show: function (opts) {
            opts = opts || {};
            var type = VARIANTS[opts.type] ? opts.type : 'info';
            var id = opts.id != null ? String(opts.id) : 't_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8);
            if (opts.id) {
                this.dismiss(String(opts.id));
            }
            var duration =
                opts.duration != null
                    ? Number(opts.duration)
                    : type === 'loading'
                      ? 0
                      : this._defaultDuration;
            var entry = {
                id: id,
                type: type,
                title: opts.title != null ? String(opts.title) : '',
                message: opts.message != null ? String(opts.message) : '',
                duration: duration,
                createdAt: Date.now(),
            };
            this._queue.push(entry);
            this._render();
            if (duration > 0) {
                var self = this;
                setTimeout(function () {
                    self.dismiss(id);
                }, duration);
            }
            if (window.ekoSampaEventBus) {
                window.ekoSampaEventBus.emit('eko:toast:shown', { id: id, type: type, message: entry.message });
            }
            return id;
        },

        dismiss: function (id) {
            var sid = String(id);
            this._queue = this._queue.filter(function (t) {
                return t.id !== sid;
            });
            var node = document.getElementById('eko-toast-item-' + sid);
            if (node && node.parentNode) {
                node.parentNode.removeChild(node);
            }
            this._trimVisible();
        },

        _trimVisible: function () {
            var host = this._ensureHost();
            while (host.children.length > this._maxVisible) {
                host.removeChild(host.firstChild);
            }
        },

        _render: function () {
            var host = this._ensureHost();
            var self = this;
            var visible = this._queue.slice(-this._maxVisible);
            visible.forEach(function (entry) {
                var nodeId = 'eko-toast-item-' + entry.id;
                if (document.getElementById(nodeId)) {
                    return;
                }
                var v = VARIANTS[entry.type] || VARIANTS.info;
                var el = document.createElement('div');
                el.id = nodeId;
                el.className = 'eko-toast ' + v.className;
                el.setAttribute('role', entry.type === 'error' ? 'alert' : 'status');
                var html =
                    '<div class="eko-toast__icon" aria-hidden="true">' +
                    v.icon +
                    '</div><div class="eko-toast__body">';
                if (entry.title) {
                    html += '<p class="eko-toast__title">' + self._esc(entry.title) + '</p>';
                }
                html += '<p class="eko-toast__message">' + self._esc(entry.message) + '</p></div>';
                html +=
                    '<button type="button" class="eko-toast__close" aria-label="Close">&times;</button>';
                el.innerHTML = html;
                el.querySelector('.eko-toast__close').addEventListener('click', function () {
                    self.dismiss(entry.id);
                });
                host.appendChild(el);
            });
            this._trimVisible();
        },

        _esc: function (s) {
            return String(s)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        },
    };

    window.ekoSampaToast = toastService;

    window.ekoSampaCan = function (ability) {
        var caps = window.ekoSampaRest && window.ekoSampaRest.capabilities;
        if (!caps || typeof caps !== 'object') {
            return true;
        }
        if (ability == null || String(ability) === '') {
            return true;
        }
        return !!caps[String(ability)];
    };

    function wireBus() {
        if (!window.ekoSampaEventBus) {
            return;
        }
        var bus = window.ekoSampaEventBus;
        bus.on('eko:toast', function (payload) {
            if (payload && typeof payload === 'object') {
                toastService.show(payload);
            }
        });
        bus.on('eko:modal:saved', function (payload) {
            toastService.show({
                type: 'success',
                message: payload && payload.title ? String(payload.title) + ' saved.' : 'Saved.',
                duration: 3000,
            });
        });
        bus.on('eko:api:error', function (payload) {
            var msg = payload && payload.message ? String(payload.message) : 'Request failed.';
            toastService.show({ type: 'error', message: msg, duration: 6000 });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', wireBus);
    } else {
        wireBus();
    }
})();
