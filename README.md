# Content translator (local_contenttranslator)

AI translation of teacher- and admin-authored Moodle content (courses, sections, activities,
book chapters, booking options, ...) into the languages you configure: automatically in the
background, within a monthly budget, and easy for humans to review and correct.

Part of the plugin family described in the Wunderbyte epic *AI Content Translator for Moodle*:

| Plugin | Purpose |
|---|---|
| `local_contenttranslator` | engine, registry, workbench, tasks, backup/restore (this plugin) |
| `filter_contenttranslator` | shows the translation to each user at render time |
| `tiny_contenttranslator` | TinyMCE integration (planned, M2) |

## Key properties

* **Source content is never modified.** Translations live in the plugin's own tables, keyed by
  content identity (component / table / field / record id) plus a hash of the normalised source text.
* **Background translation only.** Engines run on save (debounced), in bulk jobs and from the backlog
  task. The render filter only does cache/DB lookups.
* **Engines:** Moodle's core AI subsystem (`generate_text` with a translation prompt, any provider the
  site has configured) and a pseudo engine for tests and demos. More engines (DeepL, ...) plug in
  through the `register_engines` hook.
* **Humans in control:** dashboard with per-language progress, side-by-side editor with source diff,
  history and rollback, review / lock workflow. Human-edited, reviewed or locked translations are never
  overwritten; a new machine translation is stored as a suggestion next to them.
* **Cost control:** one monthly site budget in source characters, € estimates, admin notifications at a
  configurable warning level, when the budget is used up and when automatic translation pauses.
  Automatic and bulk translation stay off until a budget is set.
* **Translation memory** (exact match) isolated per tenant (site / category / course).
* Backup/restore and course copy keep translations and the course's translation settings (restored for a new
  course or with "Overwrite course configuration", like all course settings); privacy provider; capabilities; events.

## Requirements

Moodle 4.5 or later, PHP 8.1+. For the core AI engine an AI provider with the *Generate text* action
must be enabled (Site administration > General > AI).

## Setup

1. Install both plugins and enable the **Content translator** filter (Site administration > Plugins >
   Filters). Move it to the **top** of the filter order and set *Apply to* to *Content and headings* so
   that course, section and activity names are translated.
2. Open *Site administration > Plugins > Local plugins > Content translator > Setup wizard*: choose the
   engine, the target languages, the service user used for cron AI calls (accept the AI policy for it)
   and a monthly budget.
3. Optionally adjust per language settings (visibility of machine translations, "machine translated"
   indicator, formality, style guide) on the settings page, and per course settings from the course
   *More > Translations* page.

The system status report (*Reports > System status*) lists anything that is still missing.

## How it works

1. **Registry.** Content sources declare translatable fields. Built in: course (full name, summary),
   sections, categories, every activity module (name, intro and auto-discovered text columns) and mapped
   sub-tables (book chapters, choice options, lesson pages, feedback items, booking options). Event
   observers and a scheduled scan keep the registry in sync and flip translations to *stale* when the
   source changes.
2. **Queue.** Changed items are queued per course and language as ad-hoc tasks. Each item runs through the
   pipeline: translation memory -> budget check -> engine (HTML and syntax protected by placeholders,
   output validated and cleaned) -> stored with status, origin and history.
3. **Rendering.** The filter normalises the text it sees, looks up the hash for the user's language
   (falling back to the parent language, then the source), applies the visibility mode and adds the
   `lang` attribute, an optional "machine translated" indicator and a "show original" toggle.

## Extending: your own content source

```php
// db/hooks.php of your plugin.
$callbacks = [[
    'hook' => \local_contenttranslator\hook\register_sources::class,
    'callback' => \mod_example\contenttranslator::register::class . '::sources',
]];
```

```php
class my_source extends \local_contenttranslator\source\content_source {
    public function get_component(): string { return 'mod_example'; }
    public function get_itemtype(): string { return 'example_items'; }
    public function get_fields(): array {
        return ['title' => ['string' => true, 'format' => FORMAT_PLAIN], 'body' => ['formatfield' => 'bodyformat']];
    }
    public function get_items_for_course(int $courseid): iterable { /* yield source_item objects */ }
    public function get_item(int $itemid): ?source_item { /* one record or null when deleted */ }
    public function get_restore_mapping(): ?string { return 'example_item'; }
}
```

Sources for simple sub-tables can also be added without code through the *Additional sub-tables (JSON)*
setting. Plugins that render text without Moodle filters (e-mails, PDFs, table cells) use
`\local_contenttranslator\api::get_translation($text, $lang, $context)` or
`api::translate_field($component, $table, $field, $id, $text, $lang)`.

## Web services

`local_contenttranslator_translate_item`, `local_contenttranslator_save_translation`,
`local_contenttranslator_set_status`, `local_contenttranslator_get_translation`.

## Known limitations (v1)

* Learner-generated content (forum posts, submissions, ...) is out of scope by design.
* Quiz questions, site-level content (blocks, menus, badges), DeepL, TinyMCE plugin, fuzzy translation
  memory, glossaries and reviewer assignments are planned for later milestones.
* Global search, calendar and a few core pages do not run text filters (core limitations).
* Places from local_entities are not translated: when local_entities is installed, booking options show
  the entity name instead of their own location and address fields, and entities are not a content source.

## Tests

```
vendor/bin/phpunit --testsuite local_contenttranslator_testsuite
vendor/bin/phpunit --testsuite filter_contenttranslator_testsuite
```

## License

GNU GPL v3 or later. Copyright 2026 Wunderbyte GmbH.
