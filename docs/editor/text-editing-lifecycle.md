# Text inline editing lifecycle

## Flow

1. Double-click a **text** or **placeholder** element → `openInlineEdit(item)`.
2. `inlineOpen` becomes true; a `<textarea>` is shown for the matching `inlineTargetId`.
3. `inlineValue` is bound with `x-model` until **Ctrl+Enter** (`confirmInlineEdit`), **Escape** (`cancelInlineEdit`), or click-away via `clearSelectionIfCanvas` → `confirmInlineEdit`.
4. On confirm, `content` on the element is updated from `inlineValue`; on cancel, it is restored from `inlineSnapshot`.

## Non-destructive styling goal

The textarea uses **`inlineEditorTextareaCss(item)`**, which starts from the same `textContentCss(item)` string as the static `<span>` (font, size, padding, alignment, colors, line-height, etc.), then adds only structural resets (`position`, `margin`, `border`, `resize`, `outline`, `appearance`) so the control does not introduce a second typography system (no hard-coded `text-sm`, no white card background).

## Reactivity / DOM

Alpine still swaps `x-if` between the read-only hit target and the `<textarea>`. That is one mount/unmount per edit session; metrics are recorded in `window.ekoTextEditDiagnostics` (`recordOpen` / `recordClose`) to compare bounding boxes before and after when debugging.

## Diagnostics

```js
window.ekoTextEditDiagnostics.lastSession
```

Com o editor aberto e o duplo-clique activo (textarea visível):

```js
window.ekoTextEditDiagnostics.compareMetrics(document)
```

Compara `font-family`, `font-size`, `line-height`, `letter-spacing`, `white-space`, paddings, `text-align`, peso/estilo, e sinaliza `layout_shift_suspected`, `wrapping_drift`, `line_count_mismatch`, `font_metric_drift`.

Fields (best-effort):

- `editing_node_recreated` — textarea branch toggled.
- `width_before` / `height_before` / `width_after` / `height_after`
- `layout_shift_detected` — simple threshold on outer element rect.
- `width_before_after` — grouped numbers.
- `font_swap_detected` / `repaint_count` — reserved for future instrumentation (not continuously sampled to avoid overhead).

## Anti-patterns

1. **Divergent font metrics between span and textarea** (different `font-size`, `padding`, or `line-height`).
2. **Writing into `item.content` on every keystroke** — would trigger deep `elements` watchers and Interact rebinding; keep edits in `inlineValue` until commit.
3. **Removing `ignoreFrom: '.eko-sampa-editor__inline-field'`** on Interact — would fight text selection.
