# Eko Sampa - Arquitetura do Sistema

## Objetivo

O plugin Eko Sampa é um sistema SaaS modular para WordPress + WooCommerce focado em:

* criação de templates de impressão
* editor visual drag-and-drop
* gestão de clientes
* ordens de serviço
* integração com produtos WooCommerce
* renderização de layouts para impressão

Toda a arquitetura deve ser desacoplada e modular.

---

# Regras Arquiteturais

## IMPORTANTE

O sistema NÃO deve:

* misturar HTML com SQL
* misturar regras de negócio com renderização
* misturar AJAX com templates
* salvar HTML bruto do editor
* depender de jQuery legacy

---

# Stack Principal

## Backend

* PHP 8+
* WordPress Plugin API
* WooCommerce API
* MySQL

## Frontend

* Tailwind CSS
* AlpineJS
* InteractJS
* SortableJS

## Persistência

* JSON estruturado

---

# Estrutura do Plugin

eko-sampa/
├── eko-sampa.php
├── includes/
├── views/
├── assets/
├── templates/
├── languages/
└── docs/

---

# Arquitetura Backend

## Classes obrigatórias

### Core

* class-plugin.php
* class-database.php
* class-router.php
* class-assets.php
* class-roles.php
* class-frontend-router.php
* class-admin-redirect.php
* class-shortcodes.php
* templates/frontend-blank.php (documento mínimo, sem chrome do tema)

### Models

* class-client.php
* class-service.php
* class-template.php
* class-order.php
* class-layer.php

### Services

* class-template-renderer.php
* class-print-service.php
* class-thumbnail-service.php
* class-upload-service.php

### AJAX

* class-ajax-clients.php
* class-ajax-templates.php
* class-ajax-orders.php
* class-ajax-editor.php

---

# Padrões obrigatórios

## Todas as classes devem:

* usar prefixo EKO_SAMPA_
* evitar variáveis globais
* usar métodos pequenos
* responsabilidade única

---

# Editor Visual

O editor deve funcionar como aplicação independente.

## Responsabilidades:

* canvas
* drag/drop
* resize
* layers
* zoom
* grid
* placeholders
* renderização
* exportação JSON

---

# Persistência

NUNCA salvar HTML renderizado.

Salvar somente JSON estruturado.

Exemplo:

{
"elements": [
{
"id": "text_1",
"type": "text",
"x": 120,
"y": 80,
"width": 200,
"height": 40,
"content": "Cliente",
"styles": {
"fontSize": 16,
"color": "#000000"
}
}
]
}

---

# Fluxo Principal

1. usuário cria serviço
2. usuário cria template
3. template salva JSON
4. ordem utiliza template
5. placeholders substituem dados
6. impressão renderiza layout final

---

# Segurança

Obrigatório:

* nonce verification
* sanitize_text_field()
* wp_verify_nonce()
* esc_html()
* prepared statements
* current_user_can()

---

# Performance

Evitar:

* queries duplicadas
* inline CSS gigantes
* múltiplos listeners redundantes
* renderização completa a cada mudança

Usar:

* debounce
* cache local
* lazy loading
* delegação de eventos

---

# Objetivo da IA

Sempre gerar:

* código modular
* código reutilizável
* compatibilidade WordPress
* compatibilidade WooCommerce
* PHP 8+
* frontend desacoplado
* sem dependência pesada

Nunca gerar:

* funções monolíticas gigantes
* jQuery legado desnecessário
* HTML inline enorme
* SQL inseguro



---

# Arquitetura Frontend-First

## IMPORTANTE

O plugin Eko Sampa deve operar como uma aplicação frontend-first.

O objetivo é que usuários utilizem o sistema SEM acessar o wp-admin tradicional.

O WordPress deve funcionar apenas como:

* engine CMS
* autenticação
* banco de dados
* API backend
* integração WooCommerce

Toda a experiência do usuário deve acontecer no frontend customizado.

---

# Regras Obrigatórias

## Usuários comuns NÃO acessam wp-admin

Usuários com papéis:

* customer
* eko_operator
* eko_designer
* eko_manager

Devem ser redirecionados automaticamente para:

/eko-sampa_dashboard/

---

# Apenas administradores podem acessar wp-admin

Perfis permitidos:

* administrator

Todos os outros:

* redirect frontend

---

# Frontend Application Shell

O sistema deve funcionar como SPA híbrida baseada em páginas frontend.

Estrutura:

* sidebar fixa
* topbar
* content area dinâmica
* navegação interna
* layout consistente

---

# UI Framework

Toda a interface deve usar:

* TailwindCSS
* AlpineJS

Evitar:

* wp-admin UI
* estilos nativos WordPress
* tabelas antigas WP_List_Table

---

# Shortcodes

Todas as páginas devem renderizar:

* aplicações completas
* layouts completos
* formulários completos

Nunca depender do admin WordPress.

## Implementação (v1)

Rotas virtuais (rewrite) são o caminho principal: URLs `/{eko-sampa_*}/` servidas por `templates/frontend-blank.php` (sem UI do wp-admin).

Shortcodes opcionais para páginas no tema:

* `[eko_sampa_shell view="dashboard"]` — shell + área de conteúdo (atributo `view`: dashboard, clients, services, templates, editor, orders, profile)
* `[eko_sampa_login]` — tela de login frontend

Após instalar ou atualizar o plugin, salvar **links permanentes** uma vez se as rotas não resolverem (o `activation hook` executa `flush_rewrite_rules`).

### CRUD frontend (Services, Orders, Templates, Clients)

Padrão de rotas, listagem, visualização, edição, modais e botões de ação: **[docs/crud-modules.md](crud-modules.md)**.

### Schema DB resiliente (v1.4.7+)

`Eko_Sampa_Database::ensure_schema()` roda no boot do plugin: aplica migrações pendentes e recria tabelas core em falta (`eko_sampa_fields`, etc.) mesmo quando `eko_sampa_db_version` já está atualizado. Reparo manual: `bin/eko-sampa-repair-db.php`.

---

# Controle de Permissões

Criar roles customizadas:

* eko_operator
* eko_designer
* eko_manager

Capabilities customizadas:

* manage_eko_clients
* manage_eko_templates
* manage_eko_orders
* manage_eko_services
* access_eko_dashboard

---

# Navegação

O sistema deve possuir navegação própria:

* Dashboard
* Clientes
* Serviços
* Templates
* Editor
* Ordens
* Perfil

---

# Autenticação

Frontend:

* login customizado
* registro customizado
* recuperação futura
* logout customizado

Nunca usar wp-login.php visualmente.

---

# Objetivo Final

O usuário deve sentir que está utilizando:

* um SaaS profissional
* não um plugin WordPress tradicional



---

# Fase Atual do Projeto

## Objetivo Atual

Finalizar a primeira versão funcional (MVP) do sistema Eko Sampa.

O foco agora é:

* estabilidade
* funcionamento completo
* consistência visual
* fluxo operacional real

Evitar:

* refatorações grandes
* mudanças arquiteturais desnecessárias
* troca de stack
* reinvenção do frontend

---

# Regras da Fase MVP

## IMPORTANTE

A arquitetura atual deve ser preservada.

O sistema deve:

* manter TailwindCSS
* manter frontend-first
* manter shortcodes existentes
* manter estrutura modular

Não alterar:

* estrutura principal
* organização das classes
* fluxo de autenticação
* sistema frontend

---

# Admin Master

O administrador deve possuir:

* acesso total
* visualização global
* filtros por usuário
* gestão completa de clientes
* gestão completa de templates
* gestão completa de ordens
* gestão completa de serviços

A interface do admin deve ser:

* igual à interface dos usuários
* utilizando o mesmo frontend
* sem wp-admin tradicional

---

# Multiusuário

Todos os registros devem possuir:

* user_id
* ownership
* isolamento por usuário

Administrador:

* pode visualizar tudo
* pode filtrar por usuário

Usuário comum:

* apenas próprios registros

---

# Objetivo do MVP

O sistema deve permitir:

* login frontend
* dashboard funcional
* cadastro de clientes
* cadastro de serviços
* cadastro de templates
* duplicação de templates
* editor visual funcional
* placeholders dinâmicos
* upload de imagens
* criação de ordens
* preview de impressão
* impressão funcional

---

# Editor MVP

O editor precisa funcionar de forma estável.

Obrigatório:

* adicionar texto
* mover texto
* resize
* editar texto inline
* placeholders dinâmicos
* adicionar imagens da galeria
* salvar JSON corretamente
* carregar JSON corretamente

Evitar:

* animações complexas
* efeitos avançados
* features experimentais

---

# Galeria

Cada usuário deve possuir:
wp-content/uploads/eko-sampa/galeria/user-{id}/

Funções:

* upload
* listagem
* seleção
* remoção

---

# Renderização

O sistema de renderização deve:

* substituir placeholders
* renderizar preview
* renderizar impressão
* respeitar dimensões do template

---

# Checklist MVP

## Deve funcionar:

* CRUD clientes
* CRUD serviços
* CRUD templates
* duplicar template
* CRUD ordens
* preview
* impressão
* login/logout
* roles
* redirects

---

# Proibido nesta fase

Não implementar:

* websocket
* React
* Vue
* sistema realtime
* undo/redo complexo
* colaboração multiusuário
* IA
* microservices

Objetivo:
entregar MVP funcional e estável.

---

# MVP congelado — estado do código (referência)

Esta secção alinha a documentação ao repositório **sem alterar a arquitetura aprovada**. O que está listado como “implementado” existe no código atual; “adiado” permanece fora do MVP ou só parcialmente ligado.

## Implementado (includes / views / assets)

* `class-plugin.php`, `class-database.php`, `class-model-base.php`
* Modelos: `class-client.php`, `class-service.php`, `class-service-field.php`, `class-template.php`, `class-order.php`
* Rotas frontend: `class-frontend-router.php` + `templates/frontend-blank.php`
* Redirect wp-admin: `class-admin-redirect.php`
* Shortcodes: `class-shortcodes.php`
* Assets: `class-assets.php` + `assets/css/frontend.css`, `assets/js/frontend-app.js`, `assets/js/editor-canvas.js`, `assets/css/admin.css`, `assets/js/admin.js`
* REST: `class-rest-api.php` (`eko-sampa/v1`)
* Upload galeria: `class-upload-service.php`
* Render impressão/preview: `class-template-renderer.php`
* Roles: `class-roles.php`
* Router wp-admin mínimo + hooks WC vazios: `class-router.php`
* WooCommerce **opcional**: `class-wc-bridge.php` (painel no pedido WC quando `woo_order_id` corresponde)
* Vistas parciais do shell: `views/frontend-partial-*.php`, `views/editor-canvas.php`, `views/frontend-print.php`, `views/frontend-login.php`

## Adiado / não usado no MVP atual

* Classes `class-ajax-*.php` — substituídas na prática pelo REST.
* `class-layer.php` — elementos do editor persistem em `templates.json_data` (objeto `elements`).
* Uso operacional da tabela `wp_eko_sampa_layers` — criada por `dbDelta`; reservada para evolução.
* `class-print-service.php` / `class-thumbnail-service.php` — não existem; impressão via `class-template-renderer.php` + rota `print`.
* Páginas WP criadas automaticamente na ativação — **não** ocorrem; usam-se rewrites e/ou shortcodes.
* Registro frontend / aprovação de utilizador — fora do escopo atual (só login frontend).

## Congelamento

Não alterar nesta fase: stack (Tailwind CDN + Alpine + Interact + Sortable), estrutura de pastas, sistema de rotas virtuais, nem substituir o frontend por outro framework.

## Estabilização MVP (código real)

* **REST:** limite de corpo JSON ~512 KiB (`rest_pre_dispatch`); `json_data` de template limitado (~384 KiB codificado); listagens com `limit`/`offset` (default `limit=50` se omitido).
* **`GET /lookups/order-form`:** um pedido devolve listas de clientes, serviços e templates (até 500 cada) para o formulário de ordens, respeitando `filter_user_id` do administrador.
* **Ordens — `dynamic_data_json`:** apenas mapa plano (não listas), chaves sanitizadas, valores escalares, até ~120 chaves e 8000 caracteres por valor; rejeição se estrutura inválida.
* **Campos de serviço:** slug único por `service_id` (REST 409 se duplicado).
* **Preview de ordem:** HTML do render passa por `wp_kses_post` na API; no frontend o preview usa `iframe` com `sandbox` + `data:` URL (sem `x-html`).
* **Impressão (`frontend-print.php`):** HTML final também filtrado com `wp_kses_post`.
* **Editor:** debounce ao rebind do Interact após alterações nas camadas; cliente recusa guardar JSON acima de ~380 KiB antes do PATCH.
