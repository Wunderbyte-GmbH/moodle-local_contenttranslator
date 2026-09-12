# Content source API

A **content source** tells the translator which fields of which table are translatable, how to
enumerate them per course, how to read one record and where it is edited. Plugins ship their own source
and register it through a hook; no change to local_contenttranslator is needed.

## 1. Register the hook listener

`db/hooks.php` of your plugin:

```php
$callbacks = [
    [
        'hook' => \local_contenttranslator\hook\register_sources::class,
        'callback' => \mod_example\local\contenttranslator\hook_listener::class . '::register_sources',
    ],
];
```

```php
namespace mod_example\local\contenttranslator;

class hook_listener {
    public static function register_sources(\local_contenttranslator\hook\register_sources $hook): void {
        if (!class_exists(\local_contenttranslator\source\content_source::class)) {
            return; // local_contenttranslator not installed: nothing to do.
        }
        $hook->add_source(new item_source());
    }
}
```

Keep the `class_exists` guard so your plugin works without the translator installed.

## 2. Implement the source

```php
namespace mod_example\local\contenttranslator;

use local_contenttranslator\source\content_source;
use local_contenttranslator\source\source_item;

class item_source extends content_source {
    public function get_component(): string { return 'mod_example'; }
    public function get_itemtype(): string { return 'example_items'; }   // table name

    public function get_fields(): array {
        return [
            'title' => ['string' => true, 'format' => FORMAT_PLAIN],     // rendered via format_string()
            'body' => ['formatfield' => 'bodyformat'],                  // format column of the record
            'note' => ['format' => FORMAT_HTML],                        // fixed format
        ];
    }

    public function get_items_for_course(int $courseid): iterable {
        global $DB;
        $sql = "SELECT i.*, cm.id AS cmid, e.course
                  FROM {example_items} i
                  JOIN {example} e ON e.id = i.exampleid
                  JOIN {course_modules} cm ON cm.instance = e.id AND cm.module = :moduleid
                 WHERE e.course = :courseid";
        foreach ($DB->get_recordset_sql($sql, [...]) as $record) {
            yield $this->build($record);
        }
    }

    public function get_item(int $itemid): ?source_item {
        // Same as above for one id; return null when the record is gone.
    }

    private function build(\stdClass $record): source_item {
        return new source_item(
            itemid: (int)$record->id,
            contextid: \context_module::instance($record->cmid)->id,
            courseid: (int)$record->course,
            label: 'Example item: ' . $record->title,
            fields: $this->extract_fields($record),         // helper: applies get_fields() to the record
            lang: null,                                    // explicit source language, if the record has one
            editurl: new \moodle_url('/mod/example/edit.php', ['id' => $record->id]),
        );
    }

    public function get_restore_mapping(): ?string { return 'example_item'; }  // backup mapping name
}
```

Optional overrides:

| Method | Purpose |
|---|---|
| `get_site_items()` | records not bound to a course (site level) |
| `get_tables()` | additional tables whose events should re-sync this source (default: the item type) |
| `get_display_name()` | label in dashboards (default: plugin name) |
| `get_edit_url(int $itemid)` | deep link when a source_item has no editurl |
| `is_user_generated()` | return true for learner content: such sources are refused in v1 |

## 3. Keep translations in sync

Nothing else is needed when your plugin triggers standard events with `objecttable` set to your table:
the catch-all observer re-syncs the record. The scheduled scan catches everything else.

If your plugin renders text without Moodle filters (e-mails, PDFs, table cells), use the
[PHP API](PHP_API_AND_WEBSERVICES.md) to fetch the translation in the recipient's language.

## 4. Declarative alternative

Simple sub-tables can be declared without code in the *Additional sub-tables (JSON)* setting, see
[Content coverage](../user/coverage/README.md#3-sub-tables).
