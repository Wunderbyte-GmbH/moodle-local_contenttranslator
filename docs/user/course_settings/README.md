[Back to parent section](../../README.md)

# Course and category settings

---

## Quick setup path

1. Course: *More → Translations → Course translation settings*
   (`/local/contenttranslator/course.php?courseid=<id>`).
2. Choose **Automatic translation** (inherit / yes / no), the **target languages** (inherit or own
   list), **Visibility** and whether **external engines** may be used.
3. Save. The dashboard shows where each value is inherited from.

---

## Inheritance

Values resolve **course → category → parent categories → site**. Each level may override any single
value and leave the others inherited. Category overrides are stored in the same table
(`instancetype = category`) and can be set by administrators through the API or a future category UI.

| Value | Site default | Typical override |
|---|---|---|
| Automatic translation | *Automatic translation on for new courses* | switch off for archive or confidential courses |
| Target languages | site list | a tenant course with its own languages |
| Visibility | per language setting | "reviewed only" for legally sensitive courses |
| External engines allowed | yes | no for confidential content (only local engines are used) |

## Multi-tenant platforms

With **Tenant boundary = Course** (site setting) translation memory and render lookups are isolated per
course, so no tenant ever sees another tenant's texts. Onboarding a tenant then only means choosing
its languages here (or inheriting them from its category).
