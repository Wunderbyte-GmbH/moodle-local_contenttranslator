# PHP API and web services

## PHP API (`\local_contenttranslator\api`)

```php
use local_contenttranslator\api;

// Text that bypasses Moodle filters (e-mails, PDFs, wunderbyte_table cells).
$subject = api::get_translation($option->text, $recipient->lang, $context);

// Same, by identity instead of hash lookup (no normalisation involved).
$body = api::translate_field('mod_booking', 'booking_options', 'description', $option->id, $option->description, $lang);

// Full lookup result (status, origin, language actually used, item id) for custom rendering.
$info = api::lookup($text, $lang, $context);   // null when the text is unknown

// Interactive translation of one item (runs as $userid, counts as on-demand).
$translation = api::translate_now($itemid, 'de', $USER->id);
```

All lookups use the same MUC cache as the filter and never call an engine. `get_translation()` and
`translate_field()` return the source text when no visible translation exists and rewrite
`@@PLUGINFILE@@` references using the URLs found in the source you pass in.

Guard your calls so your plugin works without the translator:

```php
if (class_exists(\local_contenttranslator\api::class)) { ... }
```

## Other useful classes

| Class | Use |
|---|---|
| `item_manager` | `sync_course()`, `sync_by_table()`, `get_stats()`, `estimate_course()` |
| `translation_manager` | statuses, `save_human()`, `mark_reviewed()`, `set_locked()`, `rollback()` |
| `queue` | `queue_course()`, `queue_items()`, `queue_course_lang()` |
| `config` | effective settings per course, per language settings |
| `budget` | `can_spend()`, `get_used()`, `estimate()` |

## Web services

| Function | Purpose | Capability |
|---|---|---|
| `local_contenttranslator_translate_item(itemid, lang)` | translate one item now | translate |
| `local_contenttranslator_save_translation(translationid, text, format, review, timemodified)` | save a human edit, optional review, optimistic concurrency | translate (+ review) |
| `local_contenttranslator_set_status(translationid, action, historyid)` | `review`, `lock`, `unlock`, `acceptsuggestion`, `keepprevious`, `requeue`, `delete`, `rollback` | review / translate |
| `local_contenttranslator_get_translation(text, lang, contextid)` | render lookup (Moodle App, custom front ends) | – |

All functions are AJAX-enabled and validate the item context.
