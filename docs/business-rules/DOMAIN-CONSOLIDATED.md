# Regras de negócio — visão consolidada

> Este documento **resume** domínios já detalhados em `docs/business-rules/*.md` e `docs/orders/*`. Não duplica cada campo SQL; aponta para os ficheiros canónicos.

## Templates

- CRUD + editor visual + thumbnail + duplicate + diagnostics.
- Ver: [templates.md](templates.md), [../templates/duplicate-lifecycle.md](../templates/duplicate-lifecycle.md), [../templates/preview-image-resolution.md](../templates/preview-image-resolution.md).

## Orders (OS)

- Fluxo oficial de produção / impressão ligada a cliente/serviço/template.
- Ver: [orders.md](orders.md), [../orders/flows.md](../orders/flows.md), [../frontend/create-order.md](../frontend/create-order.md).

## Quick Print

- Job efémero, **sem** OS; snapshot cliente obrigatório.
- Ver: [../quick-print/README.md](../quick-print/README.md).

## Placeholders

- No editor são elementos `placeholder`; substituição contextual em fluxos de order/template render é tratada nos pipelines respectivos (`replaceTokensInText` no renderer, etc.).

## Persistência e save

- Editor: save incremental com fingerprint; Quick Print pede save se `hasUnsavedChanges` ao abrir.
- Templates: REST + storage manager para ficheiros (thumbnails).

## Permissões

- Ver [../security/permission-matrix.md](../security/permission-matrix.md) e `quick_print_permission()` em `includes/class-rest-api.php` (filtro `eko_sampa_quick_print_enabled`).

## Preview / thumbnail regeneration

- Thumbnail após alterações relevantes deve passar pelo export cliente quando possível (live root).
- Fallback servidor documentado na pipeline de templates.

## Fallback behavior

- Thumbnail: cliente → servidor (`thumbnail/generate`).
- Quick Print: sem fallback de “reconstruir arte” no servidor — falha de validação = erro ao cliente.
