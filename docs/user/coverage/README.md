[Back to parent section](../../README.md)

# Content coverage

---

## Table of Contents

1. [Covered out of the box](#1-covered-out-of-the-box)
2. [Auto-discovery of text columns](#2-auto-discovery-of-text-columns)
3. [Sub-tables](#3-sub-tables)
4. [Excluding fields and items](#4-excluding-fields-and-items)
5. [Out of scope](#5-out-of-scope)

---

## 1. Covered out of the box

| Content | Fields |
|---|---|
| Course | full name, summary (short name excluded by default) |
| Course sections | name, summary |
| Course categories | name, description (site level) |
| Every activity module | name, intro/description plus auto-discovered text columns (e.g. `page.content`, `assign.activity`, `workshop.instructauthors`) |
| Book | chapter title and content |
| Choice | options |
| Lesson | page title and contents |
| Feedback | item name and label |
| Booking (mod_booking) | option title, description, location, institution, address, before/after booking texts, notification text |

**Booking with local_entities:** when local_entities is installed, booking options show the place of an
entity instead of their own *location* and *address* fields. Entity names and descriptions are not translated;
learners see them in the original language.

Third-party plugins add their own sources through the
[Content source API](../../developer-guides/CONTENT_SOURCE_API.md).

## 2. Auto-discovery of text columns

For each activity module the plugin looks at the module's main table and takes every `text` column
except a skip list (formats, options, templates, URLs, paths, passwords, JSON/config columns, ...).
Add your own patterns under *Additional skipped columns* (fnmatch syntax, e.g. `*json*`), or switch
discovery off to translate only name and description.

## 3. Sub-tables

Sub-tables (records that hang off an activity, like book chapters) are declared in a small map.
Extend it under *Additional sub-tables (JSON)*:

```json
{
  "mymod_items": {
    "module": "mymod",
    "parentfield": "mymodid",
    "labelfield": "title",
    "restoremapping": "mymod_item",
    "editurl": "/mod/mymod/edit.php?cmid={cmid}&id={id}",
    "fields": {
      "title": {"string": true, "format": 2},
      "body": {"formatfield": "bodyformat"}
    }
  }
}
```

`restoremapping` is the name the module uses in backup/restore for these records; it lets translations
follow the records through course copies.

## 4. Excluding fields and items

- **Excluded fields** (setting): one `table.field` per line, e.g. `course.shortname`.
- **Exclude from translation** (dashboard bulk action): marks single items as excluded.
- Texts containing `{mlang}` or multilang spans are skipped by default and left to the multilang filters.

## 5. Out of scope

Learner-generated content (forum posts, submissions, comments, wiki pages, database entries), quiz
questions (planned), site-level content like blocks and menus (planned), H5P/SCORM internals, files,
video subtitles, language packs, places from local_entities (see section above). Global search, the calendar and a few core pages do not run text
filters at all (core limitations).
