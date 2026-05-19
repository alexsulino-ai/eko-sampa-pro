# Templates: rotas e UI

## URLs frontend

| Path | View PHP | Alpine mode |
|------|----------|-------------|
| `/eko-sampa_templates/` | `templates-list.php` | list |
| `/eko-sampa_templates/new/` | `templates-form.php` | new |
| `/eko-sampa_templates/{id}/` | `templates-detail.php` | view |
| `/eko-sampa_templates/{id}/edit/` | `templates-form.php` | edit |

Router: `Eko_Sampa_Frontend_Router::CRUD_VIEWS` inclui `templates`.

## View vs Edit

| Aspeto | `/templates/{id}/` | `/templates/{id}/edit/` |
|--------|--------------------|-------------------------|
| Formulário | read-only | editável |
| PATCH | não | sim (`templateSavePayload`) |
| Create order | sim | sim |
| REST usado no create | idêntico | idêntico |

A diferença é **UX**, não regra de negócio no backend.

## Editor

Abrir editor visual: rota separada `/eko-sampa_editor/` (não confundir com edit CRUD).

## Regras

[../business-rules/templates.md](../business-rules/templates.md)
