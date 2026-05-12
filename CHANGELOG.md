# Changelog

Todas as alterações notáveis ao projeto **Eko Sampa** são documentadas aqui.

O formato inspira-se em [Keep a Changelog](https://keepachangelog.com/pt-PT/1.0.0/).

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

### Corrigido

- **Editor em wp-admin:** quando `frontend-app.js` não está carregado, o canvas define um **`ekoSampaApi` mínimo** a partir de `ekoSampaEditor` (root + nonce), permitindo GET/PATCH de templates e galeria sem erros “API unavailable”.
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
