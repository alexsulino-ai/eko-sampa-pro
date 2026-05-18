# Índice de continuidade (consolidação técnica)

Este pacote documenta o **estado atual** do plugin para continuidade segura, sem alterar comportamento em runtime.

**Código de referência:** `EKO_SAMPA_VERSION` / `EKO_SAMPA_DB_VERSION` em `eko-sampa.php`.

| Área | Documento |
|------|-------------|
| Visão geral do pacote | [architecture/PROJECT-CONTINUITY-OVERVIEW.md](architecture/PROJECT-CONTINUITY-OVERVIEW.md) |
| Editor visual (lifecycle, DOM, persistência) | [architecture/EDITOR-VISUAL-SYSTEM.md](architecture/EDITOR-VISUAL-SYSTEM.md) |
| Pipeline de renderização | [rendering/VISUAL-PIPELINE.md](rendering/VISUAL-PIPELINE.md) |
| Thumbnails (captura, tiers, anti-regressão) | [thumbnail-system/README.md](thumbnail-system/README.md), [thumbnail-system/CAPTURE-TIERS-AND-META.md](thumbnail-system/CAPTURE-TIERS-AND-META.md) |
| Quick Print | [quick-print/README.md](quick-print/README.md) |
| Regras de negócio (consolidado) | [business-rules/DOMAIN-CONSOLIDATED.md](business-rules/DOMAIN-CONSOLIDATED.md) |
| Contratos (payloads, invariantes) | [contracts/README.md](contracts/README.md) |
| **Não quebrar** | [contracts/DO-NOT-BREAK.md](contracts/DO-NOT-BREAK.md) |
| Limitações honestas | [known-limitations/README.md](known-limitations/README.md) |
| Changelog técnico recente | [changelogs/TECH-RECENT.md](changelogs/TECH-RECENT.md) |

Documentação histórica existente (mantida): `docs/architecture/visual-render-contract.md`, `docs/templates/thumbnail-pipeline.md`, `docs/architecture/print-isolation.md`, `docs/editor/*`, etc.
