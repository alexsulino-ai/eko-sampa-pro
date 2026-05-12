# Eko Sampa - Editor Visual

## Objetivo

Criar editor visual estilo Canva simplificado.

---

# Tecnologias

Obrigatórias:

* InteractJS
* AlpineJS
* TailwindCSS

Evitar:

* React
* Vue
* jQuery UI

---

# Estrutura do Editor

## Layout

* sidebar esquerda
* canvas central
* sidebar direita
* toolbar superior

---

# Canvas

O canvas deve:

* aceitar drag/drop
* aceitar resize
* aceitar zoom
* possuir grid 5px
* possuir snap alignment
* permitir múltiplas camadas

---

# Elementos

## Tipos suportados

* texto
* imagem
* placeholder dinâmico
* retângulo
* linha
* QR code futuro

---

# Estrutura JSON

Cada elemento deve possuir:

{
"id": "",
"type": "",
"x": 0,
"y": 0,
"width": 0,
"height": 0,
"rotation": 0,
"styles": {},
"content": ""
}

---

# Funcionalidades obrigatórias

## Seleção

* clique simples
* multiselect futuro

## Movimento

* drag suave
* snap grid

## Resize

* 8 handles
* resize proporcional opcional

## Zoom

* 30% até 200%

## Layers

* reorder
* hide/show
* delete

---

# Atalhos

* Delete
* Ctrl+D
* Esc
* Arrow keys

---

# Persistência

Salvar automaticamente:

* debounce 500ms
* AJAX
* JSON puro

Nunca salvar HTML renderizado.

---

# Uploads

Pasta:
wp-content/uploads/eko-sampa/

Subpastas:

* galeria/
* previews/
* layouts/

---

# Preview

Renderização deve:

* substituir placeholders
* respeitar dimensões
* respeitar fontes
* funcionar em impressão

---

# Impressão

Página standalone:

* sem header WordPress
* sem footer
* sem sidebar
* apenas layout

---

# Performance

Evitar:

* rerender total
* listeners duplicados
* DOM gigante

Usar:

* transform translate
* requestAnimationFrame
* delegação de eventos
