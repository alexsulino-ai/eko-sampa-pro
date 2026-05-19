# Changelog

Todas as alterações notáveis ao projeto **Eko Sampa** são documentadas aqui.

O formato inspira-se em [Keep a Changelog](https://keepachangelog.com/pt-PT/1.0.0/).

---

## [1.10.0] — 2026-05-18

### Adicionado

- **`Eko_Sampa_Public_Analytics`:** contadores agregados (catálogo, conversão, editor, quick print), rollup limitado por master, fila transient para “views”, snapshot de popularidade + cron horário `eko_sampa_analytics_hourly`.
- **Catálogo público:** novos scopes REST `trending_today`, `trending_week`, `recently_printed`, `most_saved` (tabs na home); ordenação por `FIELD(id,…)` em `list_public_master_catalog` quando `id_order` está definido.
- **Saúde operacional:** `GET /eko-sampa/v1/internals/health` (admin) com filas presas, QP abandonado, snapshot de derivação + métricas públicas.
- **Admin “Public & guests”:** avisos operacionais + tabela de analytics agregados + ligação ao endpoint de health.
- **Fork convidado:** reuso servidor (transient por client key + master) além do JSON `reuse`.
- **Telemetria:** `POST /public/telemetry` aceita `editor_boot`; `do_action('eko_sampa_editor_recovery_tracked')` para contagem de recuperação.
- **Hooks:** `eko_sampa_order_created`, `eko_sampa_quick_print_job_created`, `eko_sampa_quick_print_job_completed`, `eko_sampa_guest_qp_marked_abandoned`, `eko_sampa_guest_sessions_expired_cleanup`; filtro `eko_sampa_analytics_event_context` via `eko_sampa_analytics_filter_event_context()`.
- **Docs:** `docs/analytics-architecture.md`, `docs/platform-observability.md`, `docs/healthcheck-and-cleanup.md`.

### Alterado

- Quick print (UI): fases `quickPrintPhase` + rótulos; bloqueio se o modal já está a abrir; correção do handler `afterprint` para usar `self.api` ao concluir job.

---

## [1.9.0] — 2026-05-18

### Adicionado

- **Home pública (funil):** hero de conversão (Começar agora / Ver modelos), cartões com badges **Popular** / **Destaque** / **Novo**, reutilização de fork via `sessionStorage` + corpo JSON `reuse` em `POST /public/templates/{id}/session` (200 sem novo fork quando a sessão convidada ainda é válida); **Imprimir rápido** abre o editor com `eko_open_qp=1` (modal QP automático para convidado).
- **Biblioteca “Meus templates”:** barra de uso `used/max` (`eko_sampa_max_saved_templates_per_user`) e aviso ao aproximar do limite.
- **Editor:** toolbar com hierarquia Salvar → Imprimir → Create order → Salvar em Meus; badge **Sessão temporária** com tooltip; banner de recuperação com **Continuar edição** / **Descartar aviso**; bloqueio de novo job de quick print enquanto existir job `queued` ou `sent_to_browser`.
- **Extensibilidade:** `includes/helpers-public-funnel.php` — `eko_sampa_public_funnel_do()`, filtro `eko_sampa_marketplace_catalog_row` (marketplace-ready sem dados extra).

### Alterado

- Resposta do fork público usa `enrich_row_for_public_catalog` (sem `json_data` no JSON REST).

## [1.8.5] — 2026-05-18

### Corrigido

- **REST `GET /public/catalog` (erro 500 / crítico):** o catálogo deixa de fazer `SELECT *` com `json_data` e usa `enrich_row_for_public_catalog()` — sem recalcular hash visual a partir do layout (evita esgotar memória com templates grandes). Cache transient `pubcat_v2`; falhas devolvem 503 em vez de derrubar o REST.

## [1.8.4] — 2026-05-18

### Corrigido

- **Erro crítico (PHP 8) na home pública:** o filtro `document_title_parts` e o filtro `body_class` deixam de exigir `array` no argumento — se outro plugin ou o tema devolver um tipo inválido na cadeia de filtros, o núcleo já não dispara `TypeError` com `strict_types` ao carregar `/eko-sampa/`. Meta `og:url` usa `esc_attr(esc_url(...))` no atributo `content`.

## [1.8.3] — 2026-05-18

### Adicionado

- **Gestão de templates públicos (admin):** o formulário de template **master** envia `is_public_catalog` no guardar (alinhado com `is_public` no servidor); lista de templates mostra o distintivo **Public web** quando o master está no catálogo anónimo (`/eko-sampa/`).

## [1.8.2] — 2026-05-18

### Corrigido

- **Home pública (visitante):** `GET public/catalog` e `GET public/categories` não enviam `X-WP-Nonce`, evitando erro quando a página HTML está em cache com nonce `wp_rest` expirado. A grelha do catálogo volta a renderizar; repaint completo a partir de `state.items` corrige duplicação ao paginar.

## [1.8.1] — 2026-05-18

### Adicionado

- **`Eko_Sampa_Public_Experience_Service`**: escopo `recent` (ordenado por `created_at`), `guest_quick_print_enabled` por linha do catálogo, telemetria `POST /public/telemetry` (`conversion_modal_open`, nonce + rate limit), agregado admin `top_masters_by_guest_fork`, notas em `get_aggregated_stats()`.
- **Router convidado**: sessão inválida com query plausível → `frontend-guest-session-expired.php` + `?fork=` para o master derivado quando conhecido.
- **Partials** `views/partials/public-experience/*` (hero, filtros, skeleton, banner convidado, modal conversão).
- **`public-home.js`**: tab **Populares**, cache GET em memória, `IntersectionObserver` + sentinel, skeleton, `?fork=` deep-link, badge QP, hover leve nos cartões.
- **Admin “Public & guests”**: trending IDs, teto global de sessões convidado, snapshot consolidado.
- **Docs:** `docs/public-experience-architecture.md`, `docs/guest-conversion-flow.md`.

### Alterado

- **Conversão:** modal com benefícios; convidado bloqueado em **Create order** com o mesmo fluxo; impressão rápida com mensagens de fase + bloqueio se `previewStatus` ativo.
- **SEO home pública:** meta description/OG + JSON-LD; título HTML via `document_title_parts` quando `?categoria=`.
- **Métrica recuperação:** incremento ao carregar o editor com `eko_recover_session` + token + `template_id` válidos na query.

---

## [1.8.0] — 2026-05-18

### Adicionado

- **Home pública** `/eko-sampa/`: catálogo só **master** + **`is_public_catalog = 1`**, pesquisa, categorias, tabs (todos / destaque / recentes), `GET /public/catalog` e `GET /public/categories` (cache leve), CTAs Personalizar / Imprimir rápido (fork de sessão).
- **Convidado no editor:** rota `editor` sem login quando `template_id` + `session_token` válidos; envoltório `frontend-guest-editor-wrap.php`; modal elegante para «Salvar em Meus Templates» com `redirect_to` no login frontend.
- **Anti-abuso:** `guard_session_fork` + contagem horária de forks só após sucesso; quick print convidado: quota horária após job criado; métricas opcionais em opções.
- **Admin:** Eko Sampa → **Public & guests** (toggles e limites).
- **Documentação:** `docs/public-home-flow.md`, `docs/guest-session-lifecycle.md`.

### Alterado

- `can_fork_public_session`: exige **master**; com coluna `is_public_catalog` exige valor `1` (sem OR com `is_public` nesse caso).
- `list_public_master_catalog` / categorias alinhados ao mesmo critério de catálogo público.

---

## [1.7.9] — 2026-05-18

### Adicionado

- **Hardening de sessão (DB 1.0.12):** `session_fingerprint` + `session_lifecycle`; validação unificada `request_can_use_session_row` em REST/model; PATCH de sessão limitado a `json_data` / dimensões; quick print convidado com `expires_at`, estado `abandoned`, limpeza em cron; contadores `eko_sampa_derivation_observability` + `GET /internals/derivation-stats` (admin); flags `is_public_catalog` / `is_user_shareable` / `is_marketplace_item` (retrocompat com `is_public`); hooks `eko_sampa_template_session_forked` e `eko_sampa_session_persisted_to_user_template`.
- **Editor:** autosave debounced da sessão; miniatura cliente com throttle; botão «Salvar em Meus Templates»; banner de recuperação com `eko_recover_session=1`; `persist-to-mine` com `require_app_user`.
- **Pedidos:** `session_token` opcional no `POST /orders` (corpo ou query) + `get_for_order_context` para templates em sessão.
- **Documentação:** `docs/session-hardening.md`.

### Alterado

- **Persistência / delete:** `eko_sampa_safe_delete_template` aceita `session_token` para remover sessões válidas sem `user_id`.
- **Miniatura servidor:** sem auto-GD para linhas `template_type = session` (evita explosão de renders).

---

## [1.7.8] — 2026-05-18

### Adicionado

- **Derivação de templates (master → sessão → utilizador):** colunas DB 1.0.11, fork público `POST /public/templates/{id}/session`, persistência `POST /templates/{id}/persist-to-mine`, catálogo `?catalog_public=1`, imutabilidade de `master` para não-admins, cron de limpeza de sessões, quick print anónimo com `session_token`.
- **Documentação:** `docs/template-derivation-system.md`.

---

## [1.7.7] — 2026-05-16

### Corrigido / produção

- **Impressão:** metadados operacionais na página `eko-sampa_print` deixam de ir para papel/PDF — `print:hidden` + `@media print` explícito (`.eko-sampa-print-ui-only` / separador); superfície imprimível isolada (`.eko-sampa-print-surface`); `window.ekoSampaPrintIntegrity` para contrato de testes.
- **`order_title`:** normalização reforçada (`normalize_order_title_operational`: invisíveis/zero-width, NBSP, colapso de espaços, UTF-8 truncado).

### Adicionado

- **DB 1.0.8:** índice `eko_sampa_orders_order_title` em `order_title(191)`.
- **Diagnostics:** `orders_operational_title` expandido (estratégia de busca, índice, duplicados de texto, completed sem título, amostra normalização/UTF-8); `snapshot_operational_consistency` (somente leitura em `order.json`).

### Documentação

- `docs/architecture/print-isolation.md`; actualizações em `orders-schema`, `business-rules/orders`, `rest-api/orders`, `diagnostics/admin-tool`, `database/migrations`, `README`.

---

## [1.7.6] — 2026-05-16

### Adicionado

- **Orders — título operacional (`order_title`):** coluna `varchar(255) NULL`, migração **1.0.7**, CRUD + listagem + busca + duplicação com sufixo ` (Copy)`; **não** entra em `template_render_context`, canvas ou export; incluído em `order.json` do snapshot concluído; bloco operacional na página de impressão; `operational_meta` em `GET /orders/{id}/render`; relatório `orders_operational_title` no integrity check.

---

## [1.7.5] — 2026-05-16

### Corrigido

- **Ativação fatal (parse error):** em `class-database.php`, o corpo de `migrate_to_1_0_5` tinha ficado **fora** de qualquer método (merge incompleto); restaurado o método e removido o código órfão.

### Integridade de schema (templates híbridos)

- **Drift legado vs canónico:** `Eko_Sampa_Template_Schema_Contract`, `Eko_Sampa_Template_Schema_Diagnostics::analyze()`, `Eko_Sampa_Template_Legacy_Row_Bridge` (política explícita por coluna), `Eko_Sampa_Template_Schema_Repair`.
- **INSERT:** `prepare_create_row` aplica bridge **antes** de `filter_row_to_existing_columns`; `schema_integrity_bridge` em `try_create` / REST duplicate (201); `insert_formats` suporta colunas legadas (`title`, `width`, …).
- **DDL:** `EKO_SAMPA_DB_VERSION` **1.0.6** — `migrate_to_1_0_6` relaxa `NOT NULL` sem default em colunas legadas (`ALTER … MODIFY … DEFAULT`), backfill `title` ← `nome`; audit `template_schema_legacy_defaults_relaxed`.
- **Admin Diagnostics:** *Simulate* / *Run template schema repair* + JSON de relatório.
- **Docs:** `docs/schema/templates-schema.md`, `docs/architecture/schema-alignment.md`; actualizações em `business-rules/templates.md`, `diagnostics/admin-tool.md`, `README.md`.
- **`load_schema`:** público em `Eko_Sampa_Template_Insert_Diagnostics` para diagnóstico partilhado.
- **Fallback allowlist:** colunas legadas em `Eko_Sampa_Model_Base::table_fallback_column_allowlist()` para `eko_sampa_templates`.

---

## [1.7.4] — 2026-05-16

### Corrigido / UX

- **Preview image:** URLs públicas via `Eko_Sampa_Storage_Manager::public_url_for_upload_relative`; REST `enrich_row` expõe `preview_image_public_url` e `preview_image_resolved`; detalhe do template deixa de usar o path relativo como `href`.
- **Duplicar template:** `prepare_create_row` + `Eko_Sampa_Template_Insert_Diagnostics::simulate_insert_validation` antes de cada `INSERT`; erros semânticos (`mysql_errno`, `sql_state`, `offending_column`, `insert_diagnostics`); audit `template_duplicate_insert_failed`; endpoint `GET /templates/{id}/duplicate-diagnostics`; remoção do fallback genérico `insert_failed`.
- **`json_data` duplo (string JSON dentro de string):** `json_decode_lenient_assoc` faz unwrap recursivo até ao documento objeto/array antes de re-canonizar, evitando `json_invalid` na duplicação quando a coluna guarda JSON escapado uma vez a mais.
- **`json_data` na duplicação (definitivo):** aceitar valor vindo da BD como **objeto** (coluna MySQL `JSON` / driver); candidatos a decode **sem** `wp_unslash` primeiro (evita corromper `\\` válidos), depois com `wp_unslash` para linhas antigas; `trim_json_blob` (BOM, NBSP, controlos Unicode); `json_decode` com profundidade 8192; `JSON_PARTIAL_OUTPUT_ON_ERROR` + sanitização de `NAN`/`INF` antes de `wp_json_encode`; mensagem REST de duplicação inclui detalhe técnico em `[%s]`.
- **Visual único:** `eko-canvas-renderer.js` exige `EkoVisualRenderContract` (sem ramo mm/scale duplicado); editor usa `mmToCanvasPx` do contrato; GD thumbnail usa `Eko_Sampa_Render_Schema::CSS_PX_PER_MM`; `decode_failed` explícito; `?visual_debug=1` + `detect_unicode_render_issues`; `Eko_Sampa_Visual_Drift_Diagnostics` (servidor).
- **Listagem de templates:** botão «Create order» removido só na grelha/lista; mantido no detalhe e na edição.

### Melhorado (thumbnail fiel)

- Pipeline: espera `document.fonts.ready` (timeout configurável), retries de assets (`ASSET_RETRIES`), `pixelRatio` limitado (`CAPTURE_PIXEL_RATIO_CAP`); eventos `eko-sampa:render:fonts-*`.
- **Visual Render Contract:** obrigatório para `eko-canvas-renderer.js` e para o editor (`mmToCanvasPx`); `?visual_debug=1`; `detect_unicode_render_issues`; medição `layout_surface_drift` em diagnóstico; `object-position` em imagens quando definido no elemento.

### Adicionado / Endurecimento

- Snapshots concluídos: `manifest.json` com `snapshot_schema_version`, inventário de assets (hash, mime, tamanho), estados de integridade; build em `_staging/order-{id}-{uniq}/` e publicação atómica (rename com fallback a cópia).
- Locks: snapshot por OS (`add_option` + TTL, evita corrida de transients); migração silenciosa de thumbnail com transient + verificação byte-a-byte após cópia e audit em falha.
- REST `GET /orders/{id}/render`: campos `render_warning`, `integrity_warning`, `snapshot_missing`, `legacy_snapshot_without_manifest` alinhados ao `render_source` (incl. `live_template_pre_snapshot_fallback`).
- `Eko_Sampa_Storage_Audit`: trilho limitado para falhas de snapshot/migração; relatório de integridade marca OS `completed` sem snapshot pronto como **CRITICAL** em `findings`.
- `Eko_Sampa_Storage_Manager`: validação de paths (`safe_path_guard`) e deletes sob fronteira `uploads/eko-sampa/`; deteção de pastas de staging abandonadas no relatório.

### Documentação

- `docs/storage/storage-architecture.md`: ciclo de vida do snapshot, manifesto, locks, invariantes e riscos do fallback ao template vivo.
- `docs/templates/thumbnail-pipeline.md`, `duplicate-lifecycle.md`, `preview-image-resolution.md`; `docs/rest-api/templates.md`; `docs/diagnostics/admin-tool.md`; `docs/README.md` (índice).
- `docs/architecture/visual-render-contract.md`.

## [1.7.3] — 2026-05-16

### Adicionado

- `Eko_Sampa_Storage_Manager`: paths centralizados, cópia/remoção segura sob `uploads/eko-sampa/`, relatório dry-run de integridade filesystem.
- Galeria: novos uploads em `eko-sampa/users/user-{id}/gallery/` com listagem merged com legado `eko-sampa/galeria/user-{id}/`.
- Thumbnails de template: escrita preferencial em `users/user-{id}/templates/{id}.jpg` com leitura fallback no path legado; migração silenciosa opcional (`eko_sampa_storage_silent_migrate_thumbnail`).
- Snapshot filesystem ao concluir OS (`completed-orders/order-{id}/`); render via snapshot quando disponível; OS `completed` imutáveis em `update`; `POST /orders/{id}/duplicate-revision`.
- Safe delete: `eko_sampa_safe_delete_template`, `eko_sampa_safe_delete_order`; inspectors `Template`, `Order`, `Client`; `DELETE /templates/{id}` com `?strict=1`.
- Diagnostics: botão **Storage integrity report (dry-run)**.
- Documentação: `docs/storage/storage-architecture.md`, atualizações em `orders`, REST orders, safe-delete-pattern, diagnostics.

## [1.7.2] — 2026-05-15

### Corrigido

- Botões Ver/Editar/Duplicar sumiam nas listas: `sanitize_key()` quebrava chaves `service.view`; removido skip de render no PHP (gating só no Alpine).

## [1.7.1] — 2026-05-15

### Adicionado

- Optimistic UI + toast para delete/reorder de dynamic fields.
- `policy: 'disabled'` em actions destrutivas; REST `check-slug`; `ekoSampaStore`.

## [1.7.0] — 2026-05-15

### Adicionado

- Toast global (`eko-toast.js`) com fila, variantes e bridge EventBus.
- Optimistic UI em dynamic fields (snapshot, requestId, rollback).
- Capabilities no REST localize + `ekoSampaCan()` + `can` nas action buttons.
- `eko-field-registry.js`, `ekoSampaValidationEngine`, envelope `schema_version` em validação.
- `docs/event-contracts.md`.

## [1.6.0] — 2026-05-15

### Adicionado

- Design system (`assets/css/eko-design-system.css`): tokens, z-layers, botões, inputs, estados UI.
- Modal: focus trap, scroll lock com gap da scrollbar, dirty state, `beforeunload`, z-index por stack.
- `ekoSampaFieldSchema` (definition vs meta/instance), `ekoSampaEventBus`, `ekoSampaLayers`.
- Dynamic fields: filtro, painel colapsável, linhas expansíveis; `eko_sampa_crud_actions_render()`.

## [1.5.0] — 2026-05-15

### Adicionado

- Modal reutilizável (`assets/js/eko-ui.js`, `views/partials/eko-base-modal.php`) para Dynamic Fields em Services.
- Botões de ação CRUD padronizados (`includes/helpers-crud-ui.php`) nas listagens.
- Documentação consolidada em `docs/crud-modules.md`.

## [1.4.7] — 2026-05-15

### Corrigido

- `ensure_schema()` e `bin/eko-sampa-repair-db.php` para criar tabelas core em falta (ex. `eko_sampa_fields`).

---

## [1.0.0] — 2026-05-12

### Adicionado

- Documento **`CHECKLIST-MVP.md`** para validação manual antes de testes reais em produção.
- **`CHANGELOG.md`** (este ficheiro).
- Endpoint REST **`GET /eko-sampa/v1/lookups/order-form`** — agrega listas de clientes, serviços e templates para o formulário de ordens (menos pedidos em rede).
- Paginação simples (**Previous** / **Next**) nas listagens de clientes, serviços, templates e ordens (`limit = pageSize + 1` + `offset`).
- Estado **`loading`** nas listagens com texto “Loading…” nas vistas parciais.
- No **wp-admin**, suporte a **`template_id`** na query string ao localizar o script do editor (`ekoSampaEditor.templateId`).
- Regra **`[x-cloak]`** em `assets/css/admin.css` para evitar flash de modais Alpine no admin.

### Corrigido (validação QA — estabilidade JS)

- **Editor em wp-admin:** quando `frontend-app.js` não está carregado, o canvas define um **`ekoSampaApi` mínimo** a partir de `ekoSampaEditor` (root + nonce).
- **Editor (`editor-canvas.js`):** `interact` encadeia **`.draggable().resizable()`** no mesmo alvo (evita duplicação de listeners ao rebind).
- **Editor:** `destroy()` limpa timers, **Sortable** e **interact.unset** nos elementos do canvas.
- **Editor:** `persist()` ignora resultado se `templateId` mudar durante o `await`.
- **Galeria:** falha em `refreshGallery` reporta-se em `saveState`; upload bem-sucedido limpa `saveState` após atualizar a lista.
- Garantia de sintaxe JS verificável (`node --check`) nos bundles principais.

### Segurança / robustez (já integrados na linha 1.0.0)

- Limite de tamanho do corpo JSON nas rotas mutáveis do namespace `eko-sampa/v1` (~512 KiB).
- Limite de `json_data` em templates (~384 KiB codificado) na REST.
- Validação estrita de **`dynamic_data_json`** em ordens (mapa plano, escalares).
- Slug **único** por serviço nos campos dinâmicos (REST **409**).
- **`wp_kses_post`** no HTML de preview (REST) e impressão (`frontend-print.php`).
- Preview de ordem em **iframe sandbox** + URL `data:` (sem `x-html` direto no DOM principal).

### Limitações documentadas

- Ver **`CHECKLIST-MVP.md`** (CDNs, HPOS, `wp_kses_post`, previews grandes, etc.).

---

## Tipos de mudanças

- **Adicionado** — novas funcionalidades.
- **Alterado** — mudanças em comportamento existente.
- **Descontinuado** — funcionalidades marcadas para remoção futura.
- **Removido** — funcionalidades removidas.
- **Corrigido** — correção de bugs.
- **Segurança** — correções relacionadas com segurança.
