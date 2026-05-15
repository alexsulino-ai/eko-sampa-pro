# Layout do plugin (código real)

```
eko-sampa.php                 # bootstrap, EKO_SAMPA_DB_VERSION
includes/
  class-plugin.php            # hooks centrais
  class-database.php          # migrate, alignment, row_exists
  class-database-integrity.php
  class-model-base.php        # ownership, list, column map
  class-client.php
  class-service.php
  class-service-field.php
  class-template.php
  class-order.php             # relations_validate, prepare_create_data
  class-rest-api.php
  class-frontend-router.php
  class-router.php            # admin diagnostics
  class-roles.php
  class-assets.php
  class-template-renderer.php
  class-template-thumbnail*.php
  helpers-capabilities.php
  helpers-crud-ui.php
views/
  crud/                       # templates-*, orders-*, ...
  admin-diagnostics.php
assets/js/frontend-app.js     # Alpine factories CRUD
assets/js/editor-canvas.js
docs/                         # esta árvore
```

## Entidades → classes

| Entidade | Classe | Tabela |
|----------|--------|--------|
| Client | `Eko_Sampa_Client` | `wp_eko_sampa_clients` |
| Service | `Eko_Sampa_Service` | `wp_eko_sampa_services` |
| Field | `Eko_Sampa_Service_Field` | `wp_eko_sampa_fields` |
| Template | `Eko_Sampa_Template` | `wp_eko_sampa_templates` |
| Layer | (via template JSON / table layers) | `wp_eko_sampa_layers` |
| Order | `Eko_Sampa_Order` | `wp_eko_sampa_orders` |

## Opções WordPress relevantes

| Option | Uso |
|--------|-----|
| `eko_sampa_db_version` | Versão migrada |
| `eko_sampa_integrity_last_report` | Último relatório integrity |
| `eko_sampa_integrity_repair_history` | Histórico repairs (admin) |
