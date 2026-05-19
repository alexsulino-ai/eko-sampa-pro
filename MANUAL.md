# Eko Sampa — Manual do usuário (MVP)

Este manual descreve o fluxo operacional da primeira versão funcional. Toda a experiência principal ocorre no **frontend do plugin** (Tailwind + Alpine), sem depender do menu tradicional do WordPress para o dia a dia.

## 1. Instalação e ativação

1. Instale o plugin conforme `INSTALL.md`.  
2. Ative **Eko Sampa** em **Plugins**.  
3. Se as URLs amigáveis não funcionarem, vá em **Configurações → Links permanentes** e salve.

## 2. Acesso e login

- URL típica de login: `https://seusite.com/eko-sampa_login/`  
- Após autenticar, você é levado ao **Dashboard** (`/eko-sampa_dashboard/`).  
- **Administradores** usam o mesmo frontend que os demais perfis; no topo aparece o selo **Admin** e, nas telas de listagem, um filtro **All users** para restringir dados por `user_id`.

## 3. Criar páginas (opcional)

Se quiser o app dentro do tema em vez das rotas virtuais:

1. Crie uma página **Painel** e insira: `[eko_sampa_shell view="dashboard"]`  
2. Crie uma página **Login** com: `[eko_sampa_login]`  
3. Publique e use os links dessas páginas na navegação.

As rotas `/eko-sampa_*` continuam disponíveis em paralelo.

## 4. Serviços

1. Abra **Serviços** na barra lateral.  
2. Use **New** para cadastrar nome e descrição. **Administradores** podem marcar **Global catalog** para serviços visíveis a todos (leitura), mantendo edição/remoção restrita ao dono ou ao admin.  
3. Ao editar um serviço existente, defina **campos dinâmicos** (rótulo, slug, tipo, obrigatório). Use **Edit** num campo para alterá-lo e **Save field**; **Cancel edit** limpa o formulário para novo campo. Os slugs alimentam placeholders no formato `{{slug}}` nos templates e dados das ordens.

## 5. Clientes

1. **Clientes** → **New** preenche ficha (nome obrigatório; e-mail, telefone, documento, cidade, estado).  
2. **Search** filtra por texto em vários campos.  
3. **Administradores** podem escolher **Owner user ID** ao criar um cliente para outro usuário.

## 6. Templates

1. **Templates** → crie metadados (nome, categoria opcional, dimensões em mm, serviço vinculado opcional, opcionalmente ID de produto WooCommerce e URL de imagem de preview).  
2. **Editor** abre o canvas com `?template_id=ID` (o link **Editor** na tabela já inclui isso).  
3. No editor: textos estáticos, placeholders `{{slug}}`, retângulos, imagens da **Galeria**, camadas (reordenar), zoom, arrastar/redimensionar, salvamento automático (debounce) do JSON no campo `json_data`.  
4. **Duplicate** cria uma cópia com o mesmo layout e dono original.

## 7. Ordens

1. **Ordens** → **New** escolhe cliente, serviço e template.  
2. Em **Dynamic data**, adicione pares `slug` → `valor` (alinhados aos campos dinâmicos do serviço e aos placeholders do template). Apenas valores simples (texto/número) são aceites; estruturas aninhadas ou listas são rejeitadas ao gravar.  
3. **Preview** mostra o HTML renderizado com substituição de placeholders (usa o endpoint REST `orders/{id}/render`). O painel de pré-visualização usa um iframe isolado (sem scripts).  
4. **Print** abre a página isolada `/eko-sampa_print/{id}/` (sem chrome do tema) para conferência e impressão do navegador.  
5. **WooCommerce order ID** (opcional): preencha o ID numérico do pedido no WooCommerce no campo correspondente da ordem Eko. Utilizadores com `manage_options` ou capacidade de gestão de ordens Eko veem, no ecrã desse pedido em wp-admin, um painel com atalhos para a vista de impressão e para a lista de ordens na app. Isto é apenas referência cruzada (não sincroniza linhas de pedido).

## 7.1 Listagens e desempenho

* As tabelas de **Clientes**, **Serviços**, **Templates** e **Ordens** usam paginação simples (**Previous** / **Next**); cada página pede `limit+1` linhas para saber se existe página seguinte.  
* Na vista **Ordens**, os menus Cliente / Serviço / Template carregam de um único endpoint REST (`lookups/order-form`), alinhado com o filtro **All users** do administrador.

## 8. Impressão

- A página de impressão inclui botão **Print** (oculto na impressão física) e o layout nas dimensões mm configuradas no template. O HTML devolvido pelo renderizador é filtrado com `wp_kses_post` antes de ser impresso.  
- Certifique-se de que o PDF/impressora respeitam margens; o MVP usa renderização HTML/CSS.

## 9. Galeria

- Upload na modal **Gallery** do editor (POST multipart para `eko-sampa/v1/gallery`).  
- Arquivos ficam em `wp-content/uploads/eko-sampa/galeria/user-{seu_id}/`.

## 10. Segurança (visão rápida)

- Nonce REST (`wp_rest`), capabilities por rota, ownership nos modelos PHP, consultas preparadas, sanitização nos modelos e nas rotas.  
- Administradores ignoram o predicado de `user_id` nas leituras, mas o código de mutação continua validando relações (cliente/serviço/template existentes e visíveis).  
- Corpos JSON muito grandes são rejeitados (413); `dynamic_data_json` de ordens aceita só mapas planos com valores escalares. Pré-visualização e impressão aplicam `wp_kses_post` ao HTML gerado. Slugs de campos de serviço são únicos por serviço (409 em duplicado).

Para detalhes de arquitetura, consulte `docs/architecture.md`, `docs/database.md`, `docs/editor.md` e `docs/frontend.md`.
