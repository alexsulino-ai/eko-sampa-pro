# Eko Sampa - Banco de Dados

## Regras Gerais

* Todas as tabelas usam prefixo wp_eko_sampa_
* Charset utf8mb4
* Engine InnoDB
* Compatível com dbDelta()

---

# Tabelas

## clients

Tabela:
wp_eko_sampa_clients

Campos:

* id
* user_id
* nome
* email
* telefone
* documento
* cidade
* estado
* created_at
* updated_at

---

## services

Tabela:
wp_eko_sampa_services

Campos:

* id
* user_id
* nome
* descricao
* is_global
* created_at
* updated_at

---

## fields

Tabela:
wp_eko_sampa_fields

Campos:

* id
* service_id
* label
* slug
* type
* required
* options_json
* sort_order

Tipos:

* text
* textarea
* number
* select
* date

---

## templates

Tabela:
wp_eko_sampa_templates

Campos:

* id
* user_id
* product_id
* service_id
* nome
* descricao
* width_mm
* height_mm
* preview_image
* json_data
* created_at
* updated_at

---

## layers

Tabela:
wp_eko_sampa_layers

Campos:

* id
* template_id
* layer_type
* layer_order
* layer_json

---

## orders

Tabela:
wp_eko_sampa_orders

Campos:

* id
* user_id
* client_id
* service_id
* template_id
* woo_order_id
* status
* dynamic_data_json
* print_ready
* created_at
* updated_at

---

# Índices obrigatórios

Adicionar índices:

* user_id
* template_id
* service_id
* product_id
* status

---

# Regras

## JSON

Campos JSON:

* json_data
* layer_json
* dynamic_data_json

Nunca salvar HTML bruto.

---

# Status de Ordens

Valores:

* pending
* in_progress
* print_queue
* completed

---

# Migrações

Criar:

* método migrate()
* controle de versão
* upgrades incrementais

Nunca apagar tabelas automaticamente.

---

# Ownership

Todos os dados devem respeitar:

* user_id
* permissões WordPress
* isolamento entre usuários

Admin pode visualizar tudo.
Usuário comum apenas os próprios registros.
