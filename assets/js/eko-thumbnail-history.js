/**
 * Ring buffer of recent thumbnail pipeline events (debug).
 */
(function (global) {
    'use strict';

    const CFG = global.EkoThumbnailConfig || { HISTORY_MAX_ENTRIES: 20 };
    const LC = global.EkoThumbnailLifecycle || {};

    const History = {
        _entries: [],
        _max: CFG.HISTORY_MAX_ENTRIES || 20,
        _byTemplate: {},

        push(entry) {
            const row = Object.assign(
                {
                    at: new Date().toISOString(),
                },
                entry || {}
            );
            this._entries.push(row);
            if (this._entries.length > this._max) {
                this._entries.shift();
            }
            const tid = parseInt(String(row.templateId || 0), 10);
            if (tid) {
                this._byTemplate[tid] = row;
            }
            global.EkoThumbnailHistory = this._entries;
            global.EkoThumbnailLast = row;
            try {
                document.dispatchEvent(new CustomEvent('eko-sampa:thumbnail-history', { detail: row }));
            } catch (e) {
                void e;
            }
        },

        setLifecycle(templateId, state, extra) {
            const tid = parseInt(String(templateId), 10);
            if (!tid) {
                return;
            }
            this.push(
                Object.assign(
                    {
                        templateId: tid,
                        lifecycle: state,
                    },
                    extra || {}
                )
            );
        },

        lastFor(templateId) {
            return this._byTemplate[parseInt(String(templateId), 10)] || null;
        },

        getAll() {
            return this._entries.slice();
        },
    };

    global.EkoThumbnailHistoryStore = History;
    global.EkoThumbnailHistory = [];
})(typeof window !== 'undefined' ? window : global);
