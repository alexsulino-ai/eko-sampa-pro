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
- **`Eko_Sampa_Order_Completed_Snapshot`** — `create()` (staging + manifest), `is_ready()`, `has_self_contained_manifest()`, `load_render_bundle()`, `render_integrity_hints()`, `delete_for_order()`.
- **`Eko_Sampa_Storage_Audit`** — registo append-only limitado para eventos de snapshot/migração/delete relevantes.
- **Inspectors** — `Eko_Sampa_Template_Relations_Inspector`, `Eko_Sampa_Order_Relations_Inspector`, `Eko_Sampa_Client_Relations_Inspector` (extensão de `Eko_Sampa_Entity_Relations_Inspector`).

## Snapshot de pedido concluído

**Gatilho:** na transição de estado para `completed` em `Eko_Sampa_Order::update()`, após `UPDATE` bem-sucedido, com template resolvido por `get_row_by_id`.

**Ficheiros** (mínimo legado: `order.json` + `template-snapshot.json`; **produção recomendada:** + `manifest.json` completo):

- `order.json` — metadados congelados (ids, `service_fields_snapshot_json` em string).
- `template-snapshot.json` — `json_data` congelado (URLs de imagens copiadas para `assets/` e reescritas para URLs públicas em `uploads`).
- `dynamic-data.json` — `dynamic_data_json` bruto.
- `render-context.json` — mapa plano devolvido por `Eko_Sampa_Order::template_render_context()` no momento da conclusão.
- `preview.jpg` — cópia do thumbnail do template (novo ou legado), se existir.
- `assets/` — cópias de ficheiros referenciados no layout.
- `asset-map.json` — mapa origem → destino (auditoria).

## Renderização (`GET /orders/{id}/render`)

1. Se `status === completed` e `Order_Completed_Snapshot::is_ready()` → render a partir do snapshot (`render_source: completed_snapshot`).
2. Caso contrário → template vivo (`render_source: live_template`; se `completed` sem snapshot pronto → `live_template_pre_snapshot_fallback`).

### Avisos de integridade no JSON de resposta

Para consumidores REST, o payload inclui (entre outros campos de render):

| Campo | Significado |
|-------|-------------|
| `render_source` | `completed_snapshot` \| `live_template` \| `live_template_pre_snapshot_fallback` |
| `render_warning` | `true` quando há risco operacional para uma OS `completed` (sem snapshot pronto ou só legado sem manifest). |
| `integrity_warning` | Igual a `render_warning` (sinal explícito para dashboards). |
| `snapshot_missing` | `true` se não existe snapshot utilizável para render (`is_ready` falso). |
| `legacy_snapshot_without_manifest` | `true` se existe snapshot legado (ficheiros antigos) mas ainda sem `manifest.json` do contrato atual. |

**Risco do fallback:** `live_template_pre_snapshot_fallback` mantém URLs e templates antigos compatíveis, mas uma OS concluída **não deve** depender do template vivo em produção gráfica séria — tratar como **não production-ready** até existir snapshot autocontido válido.

## Manifest (`manifest.json`) e snapshot autocontido

Contrato na raiz do snapshot (`completed-orders/order-{id}/manifest.json`):

- `snapshot_schema_version` — versão do formato (incrementar quando o contrato evoluir).
- `created_at`, `order_id`, `template_id_original`.
- `snapshot_complete` — só `true` após validação de integridade; `is_ready()` exige isto para snapshots com manifest.
- `snapshot_state` — `validating` \| `ready` \| `failed` (e estados intermédios só dentro do staging).
- `integrity_state` + lista `assets[]`: cada entrada com `path` relativo, `source`, `sha1`, `mime`, `size`, `exists`, etc., para tudo o que entra no render (layout congelado, URLs em `dynamic_data_json` onde aplicável, `preview.jpg` se existir).

**Inventário:** além do HTML final, o pipeline analisa `json_data` / URLs em texto (incl. `dynamic_data_json`) para copiar ficheiros sob `assets/` e registá-los no manifest.

## Ciclo de vida e estados (build atómico)

1. **Lock** por `order_id` (opção com TTL) antes de mutar disco.
2. Diretório de trabalho: `completed-orders/_staging/order-{id}-{uniq}/` (nunca publicar meio-ficheiro na pasta final).
3. Escrever JSONs, copiar assets, calcular integridade, gravar `manifest.json` com `snapshot_complete: true` só se tudo passar.
4. **Publicação:** `rename(staging → order-{id})` quando possível; fallback cópia recursiva + remoção do staging; se já existir snapshot válido (`snapshot_complete`), o staging é descartado sem substituir silenciosamente.
5. Em falha: apagar staging, `release_lock`, audit (`snapshot_failed`, etc.). Pasta final antiga não é substituída por conteúdo incompleto.

Estados mentalmente: `creating` (staging) → `validating` → `ready` **ou** `failed` / descartado. Nunca marcar produção sem validação.

## Locks e migrações

| Operação | Mecanismo |
|----------|-----------|
| Geração de snapshot | Opção `eko_sampa_snapshot_order_lock_{order_id}` + TTL (~120s). |
| Migração silenciosa thumbnail | Transient `eko_sampa_thumb_migrate_lock_{template_id}` + `verify_copy_bytes` após cópia. |
| Duplicar revisão (REST) | Transient curto por OS (evita dupla revisão concorrente). |

**Regra:** não disparar migrações em massa em pedidos públicos anónimos; migração de thumbnail só corre no fluxo autorizado de leitura/escrita do template e pode ser desligada com o filtro documentado.

## Filesystem safety (`safe_path_guard`)

Toda cópia/remoção/árvore relevante passa por validação:

- Raiz obrigatória dentro de `wp-content/uploads/` e, para mutações Eko, sob `eko-sampa/`.
- Bloqueio de `..`, paths vazios, remoção da raiz de uploads ou de `eko-sampa/` como alvo de delete.
- Resolução com cuidado a symlinks (não seguir para fora da área permitida).

Em dúvida, a operação **aborta** (fail-safe).

## Relatório de integridade (dry-run)

`Eko_Sampa_Storage_Manager::build_storage_integrity_report()` apenas lê e classifica:

- `completed_without_snapshot` + `findings[]` com `severity: CRITICAL` quando uma OS `completed` não tem snapshot render-ready (manifest + verificação).
- `WARNING` para snapshot legado sem manifest ou pastas `_staging/*` abandonadas.

Sem auto-delete agressivo; limpeza futura deve ser política explícita + dry-run + audit.

## Invariantes (sistema)

1. Uma OS `completed` **idealmente** nunca depende do template vivo; se depender, o REST deve sinalizar (`live_template_pre_snapshot_fallback` + warnings).
2. Snapshot **production-ready** = autocontido com `manifest.json` válido e `snapshot_complete` verificado no disco.
3. Deletes/cópias **nunca** saem da fronteira acordada sob `uploads/eko-sampa/` (com realpath/guards).
4. Migrações de ficheiros com lock + verificação pós-cópia quando aplicável.
5. Mutações de filesystem sempre validadas antes de executar.
6. Snapshots incompletos **não** substituem um snapshot válido existente.

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

- Inventário alargado (órfãos, duplicados, diretórios excessivos) — só relatório / dry-run até política de GC existir.
- Política explícita `retain_completed_orders` em delete de cliente / utilizador.
- Repair guiado (não automático) para alinhar snapshots em massa.
- Métricas de uso por cliente na UI.
