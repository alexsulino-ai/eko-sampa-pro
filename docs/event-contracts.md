# Eko Sampa — Event contracts

Formal payloads for `window.ekoSampaEventBus`. **Do not emit ad-hoc shapes** without updating this file.

Bus API: `on(event, fn)`, `off(event, fn)`, `emit(event, payload)`.

---

## UI / feedback

### `eko:toast`

**Origin:** any module  
**Listeners:** `eko-toast.js` (shows toast)

```ts
{
  type: 'success' | 'error' | 'warning' | 'info' | 'loading';
  message: string;
  title?: string;
  duration?: number; // ms; 0 = sticky
  id?: string;       // replace existing toast with same id
}
```

Preferred shortcut: `window.ekoSampaToast.show({ ... })`.

---

### `eko:api:error`

**Origin:** REST failures (services factory, future CRUD modules)  
**Listeners:** toast bridge

```ts
{
  message: string;
  requestId?: string;
  path?: string;
}
```

---

## Modal

### `eko:modal:saved`

**Origin:** `ekoSampaModalService.confirm()` after successful `onSave`  
**Listeners:** toast (generic success)

```ts
{
  id: number;    // modal stack entry id
  title: string;
}
```

---

## Services / dynamic fields

### `eko:service:fields-changed`

**Origin:** `ekoServicesFactory.saveField()` after server confirm  
**Listeners:** future order preview refresh, template placeholder cache

```ts
{
  serviceId: number;
  fieldId?: number;
  requestId?: string;
  action?: 'create' | 'update' | 'delete' | 'reorder';
}
```

**Note:** caller shows contextual toast; listeners update `ekoSampaStore` key `service:{id}:fields`.

---

## Store (not EventBus)

`window.ekoSampaStore` — thin cache only.

| Key | Value |
|-----|--------|
| `service:{serviceId}:fields` | `array` of field rows from API |

Do **not** push unrelated entities on the bus.

---

## REST validation

### `GET /services/{id}/fields/check-slug?slug=&exclude=`

Used by `ekoSampaValidationEngine` async rule `slugUniqueRemote`.

```ts
{ slug: string; available: boolean; conflict_field_id: number | null }
```

---

## Versioning

When changing payload shape, bump `payload_version` in docs and support both shapes for one release if breaking.

---

## Anti-patterns

- Storing entity caches on the bus
- Emitting inside `emit()` handlers (infinite loops)
- Stringly-typed events without documentation here
