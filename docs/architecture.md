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
