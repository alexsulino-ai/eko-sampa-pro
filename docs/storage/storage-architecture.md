# Arquitetura de armazenamento (Eko Sampa)

Este documento descreve a **evolução incremental** do storage (ficheiros) alinhada ao código: `Eko_Sampa_Storage_Manager`, thumbnails, galeria, snapshots de pedidos concluídos e integração REST.

## Princípios

1. **Sem big bang**: diretórios legados (`eko-sampa/templates/`, `eko-sampa/galeria/user-{id}/`) mantêm-se válidos.
2. **Writes preferenciais** para layout novo sob `eko-sampa/users/user-{id}/…`.
3. **Leitura com fallback**: novo path primeiro, depois legado (thumbnails; galeria faz merge na listagem).
4. **Snapshots de OS concluída** são **filesystem-first** (sem nova tabela obrigatória): pasta `completed-orders/order-{id}/`.
5. **Pedidos `completed` imutáveis na API** (`Order::update` bloqueia); revisão = `POST /orders/{id}/duplicate-revision` (só a partir de `completed`).

## Layout de diretórios

| Área | Path relativo a `wp-content/uploads/` |
|------|----------------------------------------|
| Raiz Eko | `eko-sampa/` |
| Legado thumbnails | `eko-sampa/templates/{template_id}.jpg` |
| Novo thumbnail | `eko-sampa/users/user-{uid}/templates/{template_id}.jpg` |
| Legado galeria | `eko-sampa/galeria/user-{uid}/` |
| Nova galeria | `eko-sampa/users/user-{uid}/gallery/` |
| Snapshot OS concluída | `eko-sampa/users/user-{uid}/completed-orders/order-{oid}/` |

## Classes

- **`Eko_Sampa_Storage_Manager`** — `upload_dirs()`, paths por utilizador, `ensure_dir`, `safe_copy`, `safe_unlink`, `delete_tree_under_eko`, `build_storage_integrity_report()`, `uploads_url_to_abs`.
- **`Eko_Sampa_Template_Thumbnail`** — delega paths ao storage manager; migração silenciosa opcional: filtro `eko_sampa_storage_silent_migrate_thumbnail`.
- **`Eko_Sampa_Order_Completed_Snapshot`** — `create()`, `is_ready()`, `load_render_bundle()`, `delete_for_order()`.
- **Inspectors** — `Eko_Sampa_Template_Relations_Inspector`, `Eko_Sampa_Order_Relations_Inspector`, `Eko_Sampa_Client_Relations_Inspector` (extensão de `Eko_Sampa_Entity_Relations_Inspector`).

## Snapshot de pedido concluído

**Gatilho:** na transição de estado para `completed` em `Eko_Sampa_Order::update()`, após `UPDATE` bem-sucedido, com template resolvido por `get_row_by_id`.

**Ficheiros** (mínimo para render: `order.json` + `template-snapshot.json`):

- `order.json` — metadados congelados (ids, `service_fields_snapshot_json` em string).
- `template-snapshot.json` — `json_data` congelado (URLs de imagens copiadas para `assets/` e reescritas para URLs públicas em `uploads`).
- `dynamic-data.json` — `dynamic_data_json` bruto.
- `render-context.json` — mapa plano devolvido por `Eko_Sampa_Order::template_render_context()` no momento da conclusão.
- `preview.jpg` — cópia do thumbnail do template (novo ou legado), se existir.
- `assets/` — cópias de ficheiros referenciados no layout.
- `asset-map.json` — mapa origem → destino (auditoria).

## Renderização (`GET /orders/{id}/render`)

1. Se `status === completed` e `Order_Completed_Snapshot::is_ready()` → render a partir do snapshot (`render_source: completed_snapshot`).
2. Caso contrário → template vivo (`render_source: live_template`; se `completed` sem snapshot legado → `live_template_pre_snapshot_fallback`).

## Safe delete

- `eko_sampa_safe_delete_template($id, ['strict' => bool])` — com `strict`, bloqueia se existirem OS **não** `completed` com esse `template_id`. Não remove pastas `completed-orders` de outras OS.
- `eko_sampa_safe_delete_order($id)` — remove linha + árvore do snapshot em disco (via `Order::delete()`).
- `eko_sampa_safe_delete_entity()` — despacha `service`, `template`, `order`.

REST `DELETE /templates/{id}?strict=1` usa o fluxo seguro do template.

## Diagnostics

Em **Eko Sampa → Diagnostics**, ação **Storage integrity report (dry-run)** chama `Eko_Sampa_Storage_Manager::build_storage_integrity_report()` — apenas leitura; não apaga ficheiros.

## Rollback

- Desativar plugin / reverter versão: snapshots permanecem em disco; dados em BD inalterados exceto `preview_image` apontando para paths novos após escrita de thumbnail.
- Migração silenciosa de thumbnails: desativar com `add_filter('eko_sampa_storage_silent_migrate_thumbnail', '__return_false');`

## Próximos passos (fases)

- Política explícita `retain_completed_orders` em delete de cliente / utilizador.
- Repair guiado (não automático) para alinhar snapshots em massa.
- Métricas de uso por cliente na UI.
