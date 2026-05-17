/**
 * Pure 2D layout math for the visual editor (rotation around box center).
 * DOM-free: use for snapping, future polygon hit-tests, and local resize deltas.
 * Interact stays on an axis-aligned host; CSS rotation is applied on `.eko-sampa-editor__rotate-wrap`.
 *
 * @see assets/js/editor-canvas.js — editorRotateWrapStyle
 */
(function (global) {
    'use strict';

    function degToRad(deg) {
        return (Number(deg) * Math.PI) / 180;
    }

    /**
     * @param {number} px
     * @param {number} py
     * @param {number} cx
     * @param {number} cy
     * @param {number} rad
     * @returns {{x:number,y:number}}
     */
    function rotatePoint(px, py, cx, cy, rad) {
        const cos = Math.cos(rad);
        const sin = Math.sin(rad);
        const dx = px - cx;
        const dy = py - cy;
        return {
            x: cx + dx * cos - dy * sin,
            y: cy + dx * sin + dy * cos,
        };
    }

    /**
     * Layout box (x,y,w,h) in canvas space; rotation in degrees around center (same as CSS transform-origin:center).
     *
     * @returns {number[][]} [ [x,y], ... ] order: topLeft, topRight, bottomRight, bottomLeft
     */
    function getRotatedCorners(x, y, w, h, deg) {
        const rad = degToRad(deg);
        const cx = x + w / 2;
        const cy = y + h / 2;
        const corners = [
            [x, y],
            [x + w, y],
            [x + w, y + h],
            [x, y + h],
        ];
        return corners.map(function (pt) {
            const r = rotatePoint(pt[0], pt[1], cx, cy, rad);
            return [r.x, r.y];
        });
    }

    /**
     * Axis-aligned bounding box of the rotated rectangle (canvas space).
     *
     * @returns {{x:number,y:number,width:number,height:number,corners:number[][]}}
     */
    function getRotatedBoundingBox(x, y, w, h, deg) {
        const corners = getRotatedCorners(x, y, w, h, deg);
        let minX = Infinity;
        let minY = Infinity;
        let maxX = -Infinity;
        let maxY = -Infinity;
        for (let i = 0; i < corners.length; i++) {
            const px = corners[i][0];
            const py = corners[i][1];
            minX = Math.min(minX, px);
            minY = Math.min(minY, py);
            maxX = Math.max(maxX, px);
            maxY = Math.max(maxY, py);
        }
        return {
            x: minX,
            y: minY,
            width: maxX - minX,
            height: maxY - minY,
            corners: corners,
        };
    }

    /**
     * Local space: origin top-left of unrotated box, +x right, +y down.
     *
     * @returns {{x:number,y:number}}
     */
    function worldToLocal(wx, wy, x, y, w, h, deg) {
        const rad = degToRad(deg);
        const cx = x + w / 2;
        const cy = y + h / 2;
        const dx = wx - cx;
        const dy = wy - cy;
        const inv = -rad;
        const cos = Math.cos(inv);
        const sin = Math.sin(inv);
        const lx = dx * cos - dy * sin;
        const ly = dx * sin + dy * cos;
        return { x: lx + w / 2, y: ly + h / 2 };
    }

    /**
     * @param {number} lx
     * @param {number} ly
     * @returns {{x:number,y:number}}
     */
    function localToWorld(lx, ly, x, y, w, h, deg) {
        const rad = degToRad(deg);
        const cx = x + w / 2;
        const cy = y + h / 2;
        const dx = lx - w / 2;
        const dy = ly - h / 2;
        return {
            x: cx + dx * Math.cos(rad) - dy * Math.sin(rad),
            y: cy + dx * Math.sin(rad) + dy * Math.cos(rad),
        };
    }

    /** @param {object} item element model { x, y, width, height, styles.rotate } */
    function rotationDegFromItem(item) {
        if (!item || typeof item !== 'object') {
            return 0;
        }
        const st = item.styles && typeof item.styles === 'object' ? item.styles : {};
        const r = Number(st.rotate);
        return Number.isFinite(r) ? r : 0;
    }

    global.EkoEditorTransformMath = {
        degToRad: degToRad,
        rotatePoint: rotatePoint,
        getRotatedCorners: getRotatedCorners,
        getRotatedBoundingBox: getRotatedBoundingBox,
        worldToLocal: worldToLocal,
        localToWorld: localToWorld,
        rotationDegFromItem: rotationDegFromItem,
    };
})(typeof window !== 'undefined' ? window : global);
