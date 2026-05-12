

# Eko Sampa v1.0.0

> Plugin WordPress (PHP) para criação de templates de impressão com editor visual drag-and-drop, gestão de clientes, serviços, ordens de serviço e integração com **WooCommerce**. Interface 100% em português no front-end do site.

O estado do layout do editor e os elementos do template são persistidos em **JSON** estruturado. Cada **template** é **vinculado a um produto WooCommerce**: os placeholders e metadados alimentam o preview e a impressão a partir dos dados do produto e da ordem. O usuário pode **duplicar** um template para a própria galeria e editar a cópia sem alterar o original. Imagens ficam em pastas por usuário, por exemplo `wp-content/uploads/eko-sampa/galeria/user-{id}/`.

---

## Domínio

Estrutura de domínio fixa — entidades obrigatórias:

| Entidade | Descrição |
|----------|-----------|
| Eko Sampa (empresa) | Contexto da gráfica / tenant |
| User | Usuário WordPress com papéis do plugin |
| Client | Cliente final atendido |
| Service | Serviço com campos dinâmicos |
| Product | **Produto WooCommerce** associado ao fluxo de impressão |
| Order (OS) | Ordem de serviço / atendimento |
| Template | Layout do editor (camadas + JSON), ligado a produto e serviço |
| Automation | Regras ou fluxos automáticos (extensível) |

**UI:** Tailwind CSS (CDN + utilitários locais; ver seção Tailwind).

---

## Visão Geral

**Eko Sampa** é um sistema de editor visual de templates integrado em **WordPress** e **WooCommerce**. Templates ficam associados a **produtos**; camadas e propriedades são salvas em **JSON**; arquivos de mídia vão para `wp-content/uploads/eko-sampa/`, incluindo galeria por usuário.

| Recurso | Descrição |
|---------|-----------|
| Editor visual | Drag and drop, redimensionar e duplicar elementos, galeria de imagens, painel de camadas, texto livre, campos dinâmicos alinhados ao serviço/produto, dimensões do template na própria edição, página de impressão isolada com o tamanho exato do template. |
| Templates | Biblioteca com categorias; duplicação para a galeria do usuário sem alterar o template de origem. |
| Usuários | Login, registro com aprovação, perfil; permissões para criar, duplicar e gerenciar templates; dashboard com listagem e filtros. |
| Clientes e pedidos | Gestão de clientes; vincular **pedidos WooCommerce** a clientes para organizar atendimentos. |

---

## Requisitos

- **WordPress** 6.0+
- **WooCommerce**
- **PHP** 7.4+ (recomendado 8.0+)
- **PHP GD** (para geração de miniaturas JPG)
- **Permissão de escrita** em `wp-content/uploads/`

---

## Instalação

### Método 1: Via FTP/SFTP

1. Baixe a pasta `eko-sampa`
2. Envie para `wp-content/plugins/eko-sampa/`
3. Ative o plugin no Painel > Plugins
4. O plugin cria automaticamente:
   - Todas as tabelas do banco de dados
   - Todas as páginas WordPress com shortcodes

### Método 2: Via ZIP

1. Compacte a pasta em `eko-sampa.zip`
2. Painel > Plugins > Adicionar novo > Enviar plugin
3. Instale e ative

---

## Configuração

### Criando Páginas (automático na ativação)

| Slug da Página | Shortcode |
|---------------|-----------|
| `/eko-sampa_dashboard/` | `[eko-sampa_dashboard]` |
| `/eko-sampa_clients/` | `[eko-sampa_clients]` |
| `/eko-sampa_templates/` | `[eko-sampa_templates]` |
| `/eko-sampa_editor/` | `[eko-sampa_editor]` |
| `/eko-sampa_print/` | `[eko-sampa_print]` |
| `/eko-sampa_login/` | `[eko-sampa_login]` |
| `/eko-sampa_register/` | `[eko-sampa_register]` |
| `/eko-sampa_profile/` | `[eko-sampa_profile]` |


## URLs com parâmetros

### Clientes
- `/eko-sampa_clients/` — Listagem
- `/eko-sampa_clients/?action=new` — Novo cliente
- `/eko-sampa_clients/?action=edit&id=5` — Editar cliente

### Serviços
- `/eko-sampa_services/` — Listagem (Meus + Biblioteca do Admin)
- `/eko-sampa_services/?action=new` — Novo serviço
- `/eko-sampa_services/?action=edit&id=18` — Editar serviço

### Ordens
- `/eko-sampa_ordens/` — Listagem (cards com miniatura do template)
- `/eko-sampa_ordens/?action=new` — Novo atendimento
- `/eko-sampa_ordens/?action=edit&id=66` — Editar atendimento
- `/eko-sampa_ordens/?sampa_print=1&ordem_id=66` — Impressão do atendimento

### Templates
- `/eko-sampa_templates/` — Listagem (Meus + Biblioteca do Admin)
- `/eko-sampa_templates/?action=new` — Novo template
- `/eko-sampa_templates/?action=edit&id=19` — Editar template
- `/eko-sampa_templates/?category=Etiquetas` — Filtrar por categoria

Salvar JSON estruturado template:
{
  "type": "label",
  "x": 10,
  "y": 20,
  "text": "Cliente: {{client.name}}"
}

wp-content/uploads/eko-sampa/images/
├── layouts/
    ├── /user_id/post-id
    └── ...

### Fluxo de Uso Recomendado

1. **Criar Serviço** → Defina os campos dinâmicos (Placa, Modelo, Km, etc.)
2. **Produto WooCommerce** → Associe o produto ao fluxo de impressão (quando aplicável)
3. **Criar Template** → Vincule ao **produto** e ao serviço; edite no editor visual (estado em **JSON**)
4. **Criar Cliente** → Cadastre os clientes
5. **Criar Ordem** → Selecione cliente, serviço, template e preencha os campos
6. **Imprimir** → Visualize o preview e imprima

---

## Funcionalidades

### Dashboard

Painel com estatísticas rápidas:
- Total de clientes, serviços, templates e ordens
- Status de ordens (pendentes, em andamento, fila, concluídas)
- Ações rápidas para criar novos itens

### Clientes

- ✅ CRUD completo
- ✅ Busca por nome, email, cidade
- ✅ Filtro por estado (UF)
- ✅ Toggle **Grid / Lista** (preferência salva)
- ✅ Validação de campos

### Serviços

- ✅ CRUD completo
- ✅ **Campos dinâmicos** por serviço (texto, número, data, textarea)
- ✅ Busca por nome/descrição
- ✅ Toggle Grid / Lista
- ✅ Serviços admin (globais) vs serviços do usuário

### Templates + Editor Visual

#### Editor Visual Completo

| Recurso | Descrição |
|---------|-----------|
| 📐 Canvas configurável | Largura/altura em mm (A4, Carta, custom) |
| ✏️ Texto livre | Adicione textos fixos ao template |
| 📌 Campos dinâmicos | Insira `{{campo}}` do serviço |
| 🖼️ Imagens | Galeria do usuário (upload até 10 imagens, máx 500KB cada) OU URL |
| 🎨 Propriedades | Fonte, tamanho, cor, negrito, itálico, sublinhado, alinhamento, fundo, borda, opacidade | border radius
| 🔄 Redimensionar | 8 handles (cantos + bordas) para todos os elementos |
| 📄 Duplicar elemento | Botão + atalho Ctrl+D |
| 📑 Duplicar template | Copia template + camadas inteiras |
| 🧩 Painel de camadas | Lista, seleciona e deleta camadas |
| 🔍 Zoom | Slider + botões + 100% (30% a 200%) |
| 📐 Snap ao grid | Alinhamento a 5px |

#### Atalhos de Teclado do Editor

| Tecla | Ação |
|-------|------|
| `↑↓←→` | Mover elemento (1px) |
| `Shift + ↑↓←→` | Mover elemento (10px) |
| `Delete` | Excluir elemento |
| `Ctrl + D` | Duplicar elemento |
| `Esc` | Desselecionar / Fechar painel |
| `Duplo clique` | Editar texto inline |

#### Galeria de Imagens

- 📤 **Upload**: Máx 10 imagens, 500KB cada
- 📂 **Pasta**: `/wp-content/uploads/eko-sampa/galeria/user-{id}/id-product
- 🗑️ **Gerenciamento**: Upload direto no editor via botão "Imagem"

### Ordens de Serviço

| Recurso | Descrição |
|---------|-----------|
| 📝 Campos dinâmicos | Aparecem automaticamente ao selecionar serviço |
| 👁️ **Preview ao vivo** | Renderização em tempo real do template |
| 🖨️ **Impressão direta** | Abre página dedicada para impressão |
| 📋 Status | Pendente → Em andamento → Fila de impressão → Concluído |
| ✅ Pronto p/ impressão | Checkbox com indicador visual |
| 🔍 Busca e filtro | Por cliente, #ID, status, prontos |
| 📑 Duplicar ordem | Copia todos os dados da ordem |
| ⊞ Toggle Grid/Lista | Preferência salva por sessão |

### Impressão

- 📄 Página standalone (sem tema WordPress)
- 🖨️ Botão imprimir abre diálogo nativo do navegador
- 📐 Respeita dimensões do template
- 🔄 Substitui todos os placeholders automaticamente

### Login / Registro / Perfil

- 🔐 Login integrado ao WordPress
- 📝 Registro com nome de usuário, email, senha
- ⏳ Status: Ativo, Pendente, Bloqueado, Pausado
- 👤 Perfil com edição de dados e troca de senha

---

## Tailwind CSS

### Arquitetura

O plugin **NÃO** depende do Tailwind CSS compilado. Em vez disso, utiliza:

1. **CSS utilitário customizado** (`assets/css/tailwind.css`) — Contém todas as classes Tailwind usadas pelo plugin, compiladas manualmente para não depender de build tools.
2. **Tailwind CDN** (`https://cdn.tailwindcss.com`) — Carregado apenas nas páginas do plugin com `preflight: false` para **não interferir no admin do WordPress**.
3. **Isolamento total** — O CSS do plugin só é carregado nas páginas com o shortcode `[eko-sampa_*]`.

### Por que não quebra o admin do WordPress?

| Técnica | Como funciona |
|---------|---------------|
| `preflight: false` | Tailwind CDN não aplica reset CSS global |
| `body_class` filter | Adiciona `sampa-page` apenas nas páginas do plugin |
| Escopo condicional | Assets só carregam quando `is_eko-sampa_page()` retorna true |
| `!important` seletivo | Usado apenas para sobrescrever o tema nas páginas do plugin |

### Customizando o Tema

As cores padrão são definidas em CSS variables:

```css
:root {
    --primary-color: #4f46e5;    /* Indigo 600 */
    --secondary-color: #10b981;  /* Emerald 500 */
    --accent-color: #f59e0b;     /* Amber 500 */
    --danger-color: #ef4444;     /* Red 500 */
}
```

---

## Estrutura de Arquivos

```
eko-sampa/
├── eko-sampa.php                          # Arquivo principal do plugin
├── includes/
│   ├── class-plugin.php                     # Classe principal (hooks, rotas)
│   ├── class-database.php                   # Criação de tabelas
│   ├── class-client.php                     # CRUD de clientes
│   ├── class-service.php                    # CRUD de serviços
│   ├── class-field.php                      # Campos dinâmicos
│   ├── class-template.php                   # CRUD de templates
│   ├── class-ordem.php                      # CRUD de ordens
│   ├── class-layer.php                      # Camadas do editor
│   ├── class-category.php                   # Categorias de templates
│   ├── class-thumbnail.php                  # Geração de miniaturas JPG
│   ├── class-ajax-handler.php               # Handlers AJAX (upload, duplicar, etc.)
│   └── class-frontend.php                   # Renderização de shortcodes + Application Shell
├── views/frontend/
│   ├── dashboard.php                        # Painel principal
│   ├── clients.php                          # Gestão de clientes
│   ├── services.php                         # Gestão de serviços
│   ├── templates.php                        # Biblioteca de templates
│   ├── editor.php                           # Editor visual completo (standalone)
│   ├── ordens.php                           # Gestão de ordens + preview ao vivo
│   ├── print.php                            # Página de impressão (standalone)
│   ├── login.php                            # Tela de login
│   ├── register.php                         # Tela de registro
│   ├── profile.php                          # Perfil do usuário
│   └── categories.php                       # Categorias de templates
├── assets/
│   ├── css/
│   │   ├── tailwind.css                     # Classes utilitárias Tailwind
│   │   ├── frontend.css                     # Isolamento + overrides do tema
│   │   └── admin.css                        # Estilos do admin WP
│   └── js/
│       ├── frontend.js                      # Toast, AJAX helper
│       ├── editor.js                        # (obsoleto — JS embutido no editor.php)
│       └── tailwind.js                      # Config do Tailwind CDN (preflight: false)
├── README.md                                # Este arquivo
└── wp-content/uploads/eko-sampa/          # Criado automaticamente
    ├── templates/                           # Miniaturas de templates
    ├── atendimentos/                        # Miniaturas de ordens
    ├── galeria/
    │   └── user-{id}/                       # Galeria de imagens por usuário
    └── images/layouts/
```

---

## Banco de Dados

| Tabela | Descrição |
|--------|-----------|
| `wp_eko-sampa_clients` | Cadastro de clientes |
| `wp_eko-sampa_services` | Serviços com campos dinâmicos |
| `wp_eko-sampa_fields` | Campos dinâmicos por serviço |
| `wp_eko-sampa_templates` | Templates de impressão |
| `wp_eko-sampa_layers` | Camadas/elementos de cada template |
| `wp_eko-sampa_ordens` | Ordens de serviço (atendimentos) |
| `wp_eko-sampa_categories` | Categorias de templates |
| `wp_eko-sampa_template_categories` | Relacionamento template ↔ categoria |
| `wp_eko-sampa_user_status` | Status dos usuários |

---

## Shortcodes

```php
[eko-sampa_dashboard]       // Painel principal
[eko-sampa_clients]         // CRUD de clientes
[eko-sampa_services]        // CRUD de serviços
[eko-sampa_ordens]          // CRUD de ordens
[eko-sampa_templates]       // Biblioteca de templates
[eko-sampa_editor]          // Editor visual (via ?template_id=X)
[eko-sampa_print]           // Página de impressão
[eko-sampa_login]           // Login
[eko-sampa_register]        // Registro
[eko-sampa_profile]         // Perfil
[eko-sampa_categories]      // Categorias de templates
```

---

## URLs e Parâmetros

### Clientes
```
/eko-sampa_clients/                          # Listagem
/eko-sampa_clients/?action=new               # Novo cliente
/eko-sampa_clients/?action=edit&id=5         # Editar cliente
```

### Serviços
```
/eko-sampa_services/                         # Listagem
/eko-sampa_services/?action=new              # Novo serviço
/eko-sampa_services/?action=edit&id=18       # Editar serviço
```

### Ordens
```
/eko-sampa_ordens/                           # Listagem
/eko-sampa_ordens/?action=new                # Nova ordem
/eko-sampa_ordens/?action=edit&id=66         # Editar ordem
/eko-sampa_ordens/?action=new&template_id=1  # Nova ordem com template pré-selecionado
```

### Templates
```
/eko-sampa_templates/                        # Listagem
/eko-sampa_templates/?action=new             # Novo template
/eko-sampa_templates/?action=edit&id=19      # Editar template
/eko-sampa_templates/?category=Etiquetas     # Filtrar por categoria
/eko-sampa_editor/?template_id=19            # Editor visual
```

### Impressão
```
/eko-sampa_print/?ordem_id=66                # Imprimir ordem existente
```

### Comandos Admin
```
/?eko-sampa_regen_thumbs=1                   # Regenerar todas as miniaturas
/?eko-sampa_run_migrations=1                 # Recriar tabelas/colunas do banco
```

---

## Comandos Admin

| URL | Ação |
|-----|------|
| `/?eko-sampa_regen_thumbs=1` | Regenera todas as miniaturas JPG |
| `/?eko-sampa_run_migrations=1` | Recria tabelas e colunas do banco |

---

## Hooks e Filtros

### Ações (Actions)

```php
// Ao salvar um template
do_action('eko-sampa_template_saved', $template_id);

// Ao criar uma ordem
do_action('eko-sampa_order_created', $order_id);

// Ao atualizar status de ordem
do_action('eko-sampa_order_status_changed', $order_id, $new_status, $old_status);

// Ao fazer login via Eko Sampa
do_action('eko-sampa_user_logged_in', $user_id);

// Ao registrar usuário
do_action('eko-sampa_user_registered', $user_id, $username, $email);
```

### Filtros (Filters)

```php
// Filtrar dados do template antes de salvar
$template_data = apply_filters('eko-sampa_template_data', $data, $template_id);

// Filtrar dados da ordem antes de salvar
$order_data = apply_filters('eko-sampa_order_data', $data, $order_id);

// Filtrar conteúdo do template antes de renderizar
$content = apply_filters('eko-sampa_template_content', $content, $template_id, $order_data);
```

---

## Segurança

| Medida | Implementação |
|--------|---------------|
| **Nonce verification** | Todos os formulários e requisições AJAX usam nonces WordPress |
| **Sanitização** | Todos os inputs usam `sanitize_*()` functions |
| **Escape de saída** | Todos os outputs usam `esc_html()`, `esc_attr()`, `esc_url()` |
| **Prepared statements** | Todas as queries SQL usam `$wpdb->prepare()` |
| **Verificação de permissões** | `current_user_can()` e verificação de ownership |
| **Upload seguro** | Validação de tipo MIME, tamanho máx 500KB, extensão whitelist |
| **Isolamento de CSS** | Plugin CSS não interfere no admin WordPress |

---

## Licença

GPL v2 ou superior

---

**Eko Sampa v1.0.0** — Desenvolvido com ❤️

> Últimas funcionalidades adicionadas:
> - ✅ Editor visual com resize handles (8 direções)
> - ✅ Galeria de imagens do usuário (upload, 10 imagens, 500KB máx)
> - ✅ Preview ao vivo em Nova Ordem
> - ✅ Duplicar elemento, template e ordem
> - ✅ Cor de fundo salva corretamente
> - ✅ Toggle Grid/Lista em todas as listagens com preferência salva
> - ✅ Busca e filtro em Clientes, Serviços, Templates e Ordens
> - ✅ Botão "Criar Ordem" no editor e na listagem de templates
> - ✅ Página de impressão standalone (sem tema)
> - ✅ Criação de ordem com template pré-selecionado via URL
