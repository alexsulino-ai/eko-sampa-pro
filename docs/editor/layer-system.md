# Layer system (visual editor, thumbnail, print)

## Single source of truth

Stacking order is **`json_data.elements` array order** (unchanged schema):

- Index **0** paints **behind** (back of the stack).
- The **last** index paints **in front**.

Reordering the list in the sidebar (Sortable), using **Bring forward** / **Send backward**, or duplicating elements only mutates this array. Nothing else defines layer order.

## How `z-index` is derived

The shared renderer (`assets/js/eko-canvas-renderer.js`) and the PHP print path (`includes/class-template-renderer.php`) use the **same numeric contract**:

- `STACK_Z_BASE = 10`
- `STACK_Z_STRIDE = 1` (formula: `z = STACK_Z_BASE + index`)
- Stack value: `z-index: 10 + index` for the outer `.eko-sampa-canvas__element` / `.eko-sampa-editor__element`.

This is **not** stored in JSON; it is computed at render time from the element index so DOM, thumbnail export, and PHP print stay aligned.

## Editor chrome (no `z-index` boost)

The editor keeps **the same** `stackZ(index)` as export/print at all times (including during drag/resize). Selection and hover use **inset `box-shadow`** merged into the inner frame style (`editorElementFrameStyle`), not Tailwind `ring` / `shadow-*` on the host. Interact residue (`transform`, stray `width`/`height`/`left`/`top`) is cleared in `clearInteractHostStyles()` on every move/end and before rebinding.

## DOM ↔ JSON ↔ renderer

1. Alpine `x-for="(item, idx) in elements"` on the **canvas** emits one `.eko-sampa-editor__element` per array entry in storage order (index 0 = back, last = front).
2. The **Layers** sidebar lists `[...elements].reverse()` so the **top row is the frontmost** layer (same mental model as most design tools). Sortable `onEnd` maps DOM indices back into the canonical array with `fromArr = n - 1 - oldIndex`, `toArr = n - 1 - newIndex`.
3. `:style="elementPositionStyle(item, idx)"` sets `position` + `z-index` from the **`x-for` index** only (no z boost on drag). Chrome de seleção está no frame (`editorElementFrameStyle`).
4. `EkoCanvasRenderer.buildCanvasInnerHtml` iterates `elements` in the same order and passes the index into `elementPositionStyle(item, index)`.
5. PHP `Eko_Sampa_Template_Renderer::render()` loops `elements` with a running `$stack_i` passed into `render_element`.

## `normalizeLayerOrder()`

`normalizeLayerOrder()` is a **stability hook** after reorder operations. Today it does not reshape data because the array itself is already canonical; it exists so all reorder paths (`Sortable`, bring forward/back) share one named step and future invariants stay in one place.

## Diagnostics

With the editor open, in the browser console:

```js
window.ekoLayerDiagnostics.collect(document)
```

Returns `layer_index` (from `data-layer-index`), `dom_order`, `computed_z_index`, `stacking_context_detected` on the root `.eko-sampa-editor__element`, `duplicated_z_index` across nodes, and a short `overflow_chain_head`.

```js
window.ekoLayerDiagnostics.validate(document)
// strict: pass Alpine $data subset
window.ekoLayerDiagnostics.validate(document, { selectedId, draggingId, previewOnly: false })
```

Contrato formal (offsets, ranges, quem escreve z-index): [z-index-contract.md](z-index-contract.md).

## Anti-patterns (do not reintroduce)

1. **Tailwind `z-10` / `hover:z-20` / `z-30` on canvas elements** — they fight the array order and make stacking feel random.
2. **A second parallel “layers” model** in JSON or localStorage — array order is enough; duplicate sources drift.
3. **Hard-coded unrelated z-index values** (modal layers, toast stacks) on canvas nodes — keep canvas stacking derived from `STACK_Z_*` + index only in the editor script.
