# Eko Sampa — Checklist MVP (pré-produção / primeiros testes)

Este documento resume o que está **pronto para testar**, **limitações aceites no MVP**, **itens futuros** e **bugs/riscos conhecidos**. Não substitui `INSTALL.md` nem `MANUAL.md`.

---

## Funcionalidades prontas (validar em ambiente real)

Marque após testar num site limpo (WP 6.x, PHP 8.x, permalinks ≠ “Simples”).

### Acesso e navegação

- [ ] Ativação do plugin: tabelas, roles, flush de rewrites sem erro.
- [ ] **Permalinks:** após ativar ou mudar rewrites, “Guardar” em Configurações → Links permanentes.
- [ ] **Login** em `/eko-sampa_login/` (formulário HTML → `admin-post.php`, nonce).
- [ ] **Logout** a partir do link no shell.
- [ ] **Dashboard** `/eko-sampa_dashboard/` com contadores e atalhos.
- [ ] **Shell:** sidebar, título, menu hambúrguer (mobile), item ativo.

### Dados e REST (`eko-sampa/v1`)

- [ ] **Clientes:** listar (paginação Prev/Next), pesquisa, criar, editar, apagar; admin: filtro “All users”, owner ao criar.
- [ ] **Serviços:** idem; campos dinâmicos: adicionar, editar, remover; **slug duplicado** no mesmo serviço → erro (409).
- [ ] **Templates:** metadados, paginação; **Editor** abre com `?template_id=`; duplicar; apagar.
- [ ] **Editor visual:** canvas, texto, placeholder, retângulo, zoom, camadas (reordenar), galeria (listar/upload), guardar (estado “OK” / erro).
- [ ] **Ordens:** listar com filtros; dropdowns cliente/serviço/template via **`GET /lookups/order-form`**; CRUD; `dynamic_data` plano; `woo_order_id` / `print_ready`.
- [ ] **Preview:** botão Preview → iframe (sandbox); HTML vindo de `orders/{id}/render` + `wp_kses_post`.
- [ ] **Impressão:** `/eko-sampa_print/{id}/` com utilizador autenticado e permissão de ordens.

### Shortcodes (opcional)

- [ ] `[eko_sampa_shell view="dashboard"]` (e outras views) dentro de página do tema.
- [ ] `[eko_sampa_login]` em página pública.

### WooCommerce (opcional)

- [ ] Com WC ativo: painel no pedido em wp-admin quando `woo_order_id` coincide (ver `class-wc-bridge.php`).

### wp-admin (opcional / secundário)

- [ ] Menu **Eko Sampa** (só administradores): shell mínimo.
- [ ] **Visual editor** (submenu): canvas + REST; URL com `&template_id=` carrega o layout correto.

---

## Limitações conhecidas (MVP)

- **CDN:** Tailwind, Alpine, Interact, Sortable via URL externa — requer internet no browser; versões fixas nos handles.
- **Paginação:** listagens usam `limit = pageSize + 1` (30+1 por página); não há contagem total global nem “última página” explícita.
- **Payloads:** corpo JSON mutável limitado a **~512 KiB**; `json_data` de template a **~384 KiB** (servidor) e verificação extra no cliente do editor (~380 KiB).
- **`dynamic_data_json`:** só mapas planos com valores escalares (sem objetos/listas aninhadas); chaves/valores com limites de tamanho/número.
- **`wp_kses_post`** em preview e impressão pode remover tags/atributos raros que alguém injete manualmente em `json_data` — prioridade é segurança.
- **Preview em iframe `data:`:** pré-visualizações muito grandes podem aproximar-se de limites do browser para URLs `data:`.
- **HPOS (WooCommerce):** o painel opcional em pedidos WC usa hooks da UI clássica; em lojas só HPOS o painel pode não aparecer — ligação por `woo_order_id` na app continua válida.
- **Menu wp-admin “Visual editor”:** abre sem `template_id` por defeito (canvas vazio) salvo que se use query string manual.

---

## Funcionalidades futuras (fora do MVP)

- Sincronização real de pedidos WooCommerce (linhas, estados, stock).
- Paginação com total (`X-WP-Total` ou resposta envelope).
- `.pot` / traduções completas em `languages/`.
- Thumbnails automáticos / pipeline de build Tailwind local.
- Tabela `wp_eko_sampa_layers` operacional (hoje o layout vive em `json_data`).
- Registo de utilizadores / aprovação no frontend.

---

## Bugs / riscos conhecidos do MVP

| Item | Gravidade | Notas |
|------|-----------|--------|
| Dependência de CDNs | Média | Falha de rede = UI sem estilo ou sem Alpine. |
| `wp_kses_post` vs layout | Baixa–média | Se o layout “desaparecer” parcialmente, rever tags permitidas, não reverter `x-html` cru. |
| Preview `data:` URL | Baixa | HTML gigante pode falhar em alguns browsers. |
| WC HPOS + bridge | Baixa | Painel wp-admin pode não surgir; dados Eko não dependem disso. |
| Primeira carga sem flush de rewrites | Alta (instalação) | 404 nas rotas `/eko-sampa_*` até guardar permalinks. |

---

## Responsividade e UX (verificação manual)

- [ ] Telemóvel: sidebar abre/fecha, tabelas com scroll horizontal aceitável.
- [ ] Estados **Loading…** nas listagens (clientes, serviços, templates, ordens).
- [ ] Mensagens de erro REST visíveis (`err`).
- [ ] Editor: `saveState` visível após guardar.

---

## Donos e permissões (sanidade)

- [ ] Utilizador sem `access_eko_dashboard` não entra no app (403).
- [ ] Utilizador sem capability da secção (ex.: clientes) recebe 403 na rota virtual correspondente.
- [ ] Administrador vê todos os registos; filtro `filter_user_id` restringe listas e lookups de ordens.

---

*Última revisão alinhada ao código do plugin (versão em `eko-sampa.php`).*
