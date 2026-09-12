[Back to parent section](../../README.md)

# Capabilities

Defined in `db/access.php`; adjust per role under *Site administration → Users → Permissions → Define roles*.

| Capability | Context | Default roles | Allows |
|---|---|---|---|
| `local/contenttranslator:manage` | system | manager | plugin settings, wizard, site dashboard |
| `local/contenttranslator:configurecourse` | course | editing teacher, manager | course translation settings |
| `local/contenttranslator:translate` | course | editing teacher, manager | edit translations, translate now, requeue |
| `local/contenttranslator:review` | course | editing teacher, manager | mark reviewed, lock/unlock, accept suggestion, delete, exclude |
| `local/contenttranslator:bulktranslate` | course | manager | *Translate course* (creates costs) |
| `local/contenttranslator:exceedbudget` | system | – | on-demand translation when the monthly budget is exhausted |
| `local/contenttranslator:viewreports` | course | editing teacher, manager | dashboards |

Typical setups:

- **Translator role** (course level): `translate` + `viewreports`.
- **Reviewer role**: translator plus `review`. Remove `review` from editing teachers if you want a
  four-eyes workflow.
- Keep `bulktranslate` with managers: it is the only action besides on-demand translation that spends
  budget on purpose.
