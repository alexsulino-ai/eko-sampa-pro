# Visual box model (canvas elements)

## Pipeline

Each logical element is a positioned **outer box** (`x`, `y`, `width`, `height` in layout px) with an inner **frame** (full size of the box) that carries opacity, border, border-radius, box-shadow, and rotation. Content (text span, image, or flat rectangle fill) sits inside the frame.

The same structure is used in:

- `EkoCanvasRenderer.buildElementHtml` (JS: editor thumbnails, client export, any DOM mount using the renderer)
- `Eko_Sampa_Template_Renderer` (PHP: print / preview HTML)

## `overflow` on the frame

- **Image** and **rectangle**: the frame uses `overflow: hidden` so `border-radius` clips bitmaps and fills predictably.
- **Text** and **placeholder**: the frame uses `overflow: visible` so borders, shadows, and padded text are not clipped by the frame’s own box when the inner text span scrolls (`overflow: auto` in the editor, `hidden` when `forPrint` in the renderer).

This avoids “mysterious” clipping when combining padding, background, border-radius, and shadows on text. It does **not** remove clipping from the **canvas surface** (`overflow: hidden` on `.eko-sampa-canvas`), which still trims content outside the page, as before.

## `box-sizing`

Outer and inner boxes use **`border-box`** everywhere so `width`/`height` in JSON match the draggable rectangle in the editor and the exported layout.

## Transforms

Rotation is applied on the **frame** with `transform-origin: center center`. The editor stage still uses `transform: scale(...)` for zoom; that is outside the canvas surface and was not changed.

## Diagnostics

```js
window.ekoVisualBoxDiagnostics.collect(document)
```

Returns per-element `overflow_chain`, `border_box_metrics`, `visual_bounds`, `transformed_bounds`, `paint_bounds` (currently all derived from `getBoundingClientRect()` on the outer node and inner `span`/`textarea`/`img` where present), plus `clipping_parent` when an ancestor in the chain uses `overflow: hidden`.

## Anti-patterns

1. **`overflow: visible` on every frame type** — breaks predictable clipping for images and rotated fills.
2. **Global CSS overrides** on `.eko-sampa-canvas__*` for “quick fixes” — keep behavior in the shared renderer + PHP mirror.
3. **Moving border-radius to a different wrapper than border/shadow** without updating both JS and PHP — they must stay in lockstep.
