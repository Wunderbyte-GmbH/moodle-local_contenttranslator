# local_contenttranslator — Documentation

Welcome to the documentation for the **Content translator** Moodle plugin family by
[Wunderbyte GmbH](https://www.wunderbyte.at).

The plugin translates teacher- and admin-authored content (course names and summaries, sections,
activities, book chapters, booking options, ...) with AI into the languages you configure. Translation
runs in the background within a monthly budget, source content is never modified, and humans review and
correct the result in a dedicated workbench.

| Plugin | Purpose |
|---|---|
| `local_contenttranslator` | engine, registry, workbench, tasks, backup/restore (this repository) |
| [`filter_contenttranslator`](https://github.com/Wunderbyte-GmbH/moodle-filter_contenttranslator) | shows the translation to each user at render time |

---

## Quick-start guide

| I want to… | Go to… |
|------------|--------|
| Install and configure the plugin for the first time | [Setup](user/setup/README.md) |
| Choose target languages and how machine translations are shown | [Languages and visibility](user/languages/README.md) |
| Understand the monthly budget, notifications and cost estimates | [Budget and cost control](user/budget/README.md) |
| See translation progress and start a bulk translation of a course | [Dashboard](user/dashboard/README.md) |
| Review, correct, lock or roll back a translation | [Editor and review workflow](user/editor/README.md) |
| Switch translation on/off or change languages for one course or category | [Course and category settings](user/course_settings/README.md) |
| Know which content is translated and exclude fields | [Content coverage](user/coverage/README.md) |
| Set up roles and permissions | [Capabilities](user/capabilities/README.md) |
| Understand the background tasks | [Scheduled tasks](user/scheduled_tasks/README.md) |
| Add translatable fields of my own plugin | [Content source API](developer-guides/CONTENT_SOURCE_API.md) |
| Add another translation engine (DeepL, Azure, ...) | [Engine API](developer-guides/ENGINE_API.md) |
| Translate texts in e-mails, PDFs or tables from PHP | [PHP API and web services](developer-guides/PHP_API_AND_WEBSERVICES.md) |
| Understand how it all fits together | [Architecture](developer-guides/ARCHITECTURE.md) |

Important distinctions for AI/explain tasks:

- Questions about *what learners see* (translation shown or not, "machine translated" label, "show
  original") belong to [Languages and visibility](user/languages/README.md) and the
  [filter documentation](https://github.com/Wunderbyte-GmbH/moodle-filter_contenttranslator/tree/main/docs).
- Questions about *why nothing is translated automatically* almost always end in
  [Budget and cost control](user/budget/README.md): automatic and bulk translation are off until a
  monthly budget is set.
- Questions about *wrong or outdated translations* belong to the [Editor](user/editor/README.md)
  (statuses *stale*, *suggestion*, *locked*).
- The plugin translates **authored content only**. Learner-generated content (forum posts, submissions,
  wiki pages) and language packs / UI strings are out of scope by design.

## First admin workflow (click-by-click)

1. Install `local_contenttranslator` and `filter_contenttranslator`.
2. Enable the filter and move it to the **top** of the filter order:
   [/admin/filters.php](/admin/filters.php). Enable *Filter all strings* (`filterall`) in
   [/admin/search.php?query=filterall](/admin/search.php?query=filterall).
3. Open the setup wizard: [/local/contenttranslator/wizard.php](/local/contenttranslator/wizard.php).
   Choose the engine, target languages, a service user (accept the AI policy for it) and a monthly budget.
4. Check *Site administration → Reports → System status*: the "Content translator setup" check lists
   anything still missing.
5. Open a course, then *More → Translations*: press **Scan content**, then **Translate course**.
6. Review the results in the editor, see [Editor and review workflow](user/editor/README.md).

## Status vocabulary

| Status | Meaning |
|---|---|
| **Missing** | no translation row exists yet for this item and language |
| **Queued** | waiting for the background job |
| **Machine** | translated by an engine (or from translation memory), not reviewed |
| **Edited** | a human changed the text but did not mark it reviewed |
| **Reviewed** | approved by a human; never overwritten automatically |
| **Stale** | the source text changed after the translation was made |
| **Failed** | the engine failed; the reason is shown in the dashboard and editor |
| **Locked** | may not be changed automatically, regardless of status |
| **New suggestion** | a fresh machine translation waits next to a reviewed/locked one |
