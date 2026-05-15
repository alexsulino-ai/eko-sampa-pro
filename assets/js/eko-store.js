/**
 * Thin entity cache (NOT on EventBus). Use for read-through snapshots only.
 *
 * @package Eko_Sampa
 */
(function () {
    'use strict';

    var store = {
        _data: Object.create(null),
        _subs: Object.create(null),

        get: function (key) {
            return this._data[String(key)];
        },

        set: function (key, value) {
            var k = String(key);
            this._data[k] = value;
            this._notify(k, value);
            return value;
        },

        patch: function (key, partial) {
            var k = String(key);
            var cur = this._data[k];
            var next =
                cur && typeof cur === 'object' && partial && typeof partial === 'object'
                    ? Object.assign(Array.isArray(cur) ? cur.slice() : Object.assign({}, cur), partial)
                    : partial;
            return this.set(k, next);
        },

        subscribe: function (key, fn) {
            var k = String(key);
            if (typeof fn !== 'function') {
                return function () {};
            }
            if (!this._subs[k]) {
                this._subs[k] = [];
            }
            this._subs[k].push(fn);
            var self = this;
            return function () {
                self._subs[k] = (self._subs[k] || []).filter(function (f) {
                    return f !== fn;
                });
            };
        },

        _notify: function (key, value) {
            var list = this._subs[key];
            if (!list) {
                return;
            }
            list.slice().forEach(function (fn) {
                try {
                    fn(value, key);
                } catch (e) {
                    void e;
                }
            });
        },
    };

    window.ekoSampaStore = store;
})();
