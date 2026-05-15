# Eko Sampa - Frontend

## Objetivo

O frontend deve funcionar como aplicação SaaS completa.

O usuário nunca deve perceber o wp-admin tradicional.

---

# Stack Frontend

Obrigatório:

* TailwindCSS
* AlpineJS
* Vanilla JS modular

Opcional:

* SortableJS
* InteractJS

Evitar:

* Bootstrap
* jQuery legado
* wp-admin CSS

---

# Layout Base

## Estrutura

* sidebar esquerda fixa
* topbar superior
* content area
* sistema responsivo
* dark mode futuro

---

# Sidebar

Itens:

* Dashboard
* Clientes
* Serviços
* Templates
* Ordens
* Perfil
* Logout

---

# Design System

## Cores

Usar CSS variables:

:root {
--primary-color: #4f46e5;
--secondary-color: #10b981;
--danger-color: #ef4444;
--warning-color: #f59e0b;
}

---

# Componentes

Implementados (v1.5.0 — ver [crud-modules.md](crud-modules.md)):

* **Modais**: `ekoSampaModalService` + `ekoModalMixin` + `views/partials/eko-base-modal.php`
* **Action buttons CRUD**: `eko_sampa_crud_action()` em `includes/helpers-crud-ui.php`
* **CRUD factories**: `eko*Factory()` + `ekoCrudMixin()` em `assets/js/frontend-app.js`
* cards, tables, inputs (Tailwind nas views `views/crud/`)

Planeados:

* dropdowns
* badges
* toasts

---

# Responsividade

Sistema deve funcionar:

* desktop
* tablet
* mobile

Sidebar:

* collapse mobile
* overlay mobile

---

# UX

Obrigatório:

* loading states
* feedback visual
* skeleton loading futuro
* toasts
* validação inline

---

# Login

Página frontend:

* moderna
* minimalista
* responsiva
* sem wp-login.php

---

# Dashboard

Widgets:

* total clientes
* total ordens
* total templates
* status produção

---

# Templates

Listagem:

* grid
* preview
* filtros
* busca
* duplicação

---

# Editor

Deve abrir em layout fullscreen.

Sem header WordPress.
Sem footer WordPress.

---

# Impressão

Página standalone:

* fundo branco
* sem UI
* apenas template renderizado

---

# Assets

Carregar assets apenas:

* páginas Eko Sampa
* evitar carregamento global

---

# Objetivo Visual

Referências:

* Canva
* Trello
* Notion
* Figma
* Shopify Admin

Visual:

* clean
* moderno
* rápido
* profissional
