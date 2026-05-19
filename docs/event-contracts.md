# Eventos e hooks

Hooks WordPress usados no plugin (não confundir com contratos de domínio em [architecture/domain-contracts.md](architecture/domain-contracts.md)):

| Hook | Onde |
|------|------|
| `rest_api_init` | Registo REST |
| `eko_sampa_register_rewrites` | Placeholder em `class-router.php` |
| Actions de activation | `eko-sampa.php` — roles, flush rewrites, `ensure_schema` |

Para novos eventos públicos, documentar aqui e em `CHANGELOG.md` se breaking.

Domínio (Template/Order/Service): **domain-contracts.md**, não este ficheiro.
