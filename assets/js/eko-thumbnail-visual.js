/**
 * Visual checksum — only layout/elements (not nome, categoria, etc.).
 */
(function (global) {
    'use strict';

    function normalizeStyles(styles) {
        const st = styles && typeof styles === 'object' ? styles : {};
        const keys = [
            'fontSize',
            'fontFamily',
            'fontWeight',
            'fontStyle',
            'color',
            'backgroundColor',
            'textAlign',
            'opacity',
            'borderRadius',
            'borderWidth',
            'borderColor',
            'borderStyle',
            'rotate',
            'objectFit',
        ];
        const out = {};
        keys.forEach((k) => {
            if (st[k] != null) {
                out[k] = st[k];
            }
        });
        return out;
    }

    function normalizeAssetRef(src) {
        const s = String(src || '').trim();
        if (!s) {
            return '';
        }
        if (s.indexOf('data:image') === 0) {
            return 'data:' + simpleHash(s).slice(0, 12);
        }
        try {
            const u = new URL(s, global.location && global.location.href ? global.location.href : undefined);
            if (u.pathname && u.pathname.indexOf('/wp-content/uploads/') !== -1) {
                return 'upload:' + u.pathname.split('/wp-content/uploads/').pop();
            }
        } catch (e) {
            void e;
        }
        return s;
    }

    function normalizeElement(el) {
        if (!el || typeof el !== 'object') {
            return null;
        }
        const type = String(el.type || 'text');
        const item = {
            type: type,
            x: Math.round(Number(el.x || 0) * 100) / 100,
            y: Math.round(Number(el.y || 0) * 100) / 100,
            width: Math.round(Number(el.width || 0) * 100) / 100,
            height: Math.round(Number(el.height || 0) * 100) / 100,
            content: String(el.content != null ? el.content : ''),
        };
        if (type === 'image') {
            item.src = normalizeAssetRef(el.src != null ? el.src : el.content);
        }
        if (el.styles && typeof el.styles === 'object') {
            item.styles = normalizeStyles(el.styles);
        }
        return item;
    }

    function simpleHash(str) {
        let h = 2166136261;
        const s = String(str);
        for (let i = 0; i < s.length; i++) {
            h ^= s.charCodeAt(i);
            h = Math.imul(h, 16777619);
        }
        return (h >>> 0).toString(16).padStart(8, '0');
    }

    function visualChecksum(payload) {
        const p = payload && typeof payload === 'object' ? payload : {};
        const elements = (Array.isArray(p.elements) ? p.elements : [])
            .map(normalizeElement)
            .filter(Boolean)
            .sort((a, b) => {
                if (a.y !== b.y) {
                    return a.y - b.y;
                }
                return a.x - b.x;
            });
        const canonical = {
            width_mm: Math.max(1, parseInt(String(p.width_mm || 210), 10) || 210),
            height_mm: Math.max(1, parseInt(String(p.height_mm || 297), 10) || 297),
            elements: elements,
        };
        try {
            const json = JSON.stringify(canonical);
            return (simpleHash(json) + simpleHash(json + ':eko')).slice(0, 16);
        } catch (e) {
            return (simpleHash(String(Date.now())) + simpleHash('fallback')).slice(0, 16);
        }
    }

    function payloadFromTemplateRow(row) {
        const r = row && typeof row === 'object' ? row : {};
        let jd = r.json_data;
        if (typeof jd === 'string') {
            try {
                jd = JSON.parse(jd);
            } catch (e) {
                jd = {};
            }
        }
        if (!jd || typeof jd !== 'object') {
            jd = {};
        }
        let elements = [];
        if (Array.isArray(jd)) {
            elements = jd;
        } else if (Array.isArray(jd.elements)) {
            elements = jd.elements;
        }
        const payload = {
            width_mm: r.width_mm != null ? Number(r.width_mm) : 210,
            height_mm: r.height_mm != null ? Number(r.height_mm) : 297,
            elements: elements,
        };
        payload.visual_hash = visualChecksum(payload);
        return payload;
    }

    global.EkoThumbnailVisual = {
        visualChecksum: visualChecksum,
        payloadFromTemplateRow: payloadFromTemplateRow,
        simpleHash: simpleHash,
    };
})(typeof window !== 'undefined' ? window : global);
