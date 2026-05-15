# Editor visual

O editor drag-and-drop usa `assets/js/editor-canvas.js` e a rota `/eko-sampa_editor/`.

Regras gerais de arquitetura (JSON, não HTML): [architecture/overview.md](architecture/overview.md).

Persistência do layout: campo `json_data` em templates — [database/schema.md](database/schema.md).

Thumbnail/print pipelines são módulos separados em `includes/class-template-thumbnail*.php` e `class-template-renderer.php`.
