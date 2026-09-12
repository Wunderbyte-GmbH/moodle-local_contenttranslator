[Back to parent section](../../README.md)

# Dashboard

---

## Quick setup path

1. Open a course and choose *More → Translations* (or `/local/contenttranslator/index.php?courseid=<id>`).
2. Press **Scan content** to register all translatable fields of the course.
3. Press **Translate course**, check the estimate, confirm. Cron processes the queue.
4. Use the filters (language, status, content type, search) to find what needs attention and open items
   in the [editor](../editor/README.md).

The site-wide dashboard for administrators is at
[/local/contenttranslator/index.php](/local/contenttranslator/index.php).

---

## Table of Contents

1. [Progress per language](#1-progress-per-language)
2. [Actions](#2-actions)
3. [Item list and filters](#3-item-list-and-filters)
4. [Bulk actions](#4-bulk-actions)

---

## 1. Progress per language

One card per target language: reviewed (green), machine (blue) and stale (yellow) as a progress bar,
plus counts for queued, failed and missing. Administrators also see the monthly budget bar.

## 2. Actions

| Button | What it does |
|---|---|
| **Scan content** | re-reads every covered field of the course, registers new items, removes deleted ones and marks changed sources as stale |
| **Translate course** | scan + pre-flight estimate + confirmation; queues one background job per target language |
| **Course translation settings** | per course on/off, languages, visibility, external engines |

## 3. Item list and filters

Each row is one field of one content record (e.g. *Page: Introduction / content*) with its source
excerpt, character count and one status badge per language. Click a badge to open the editor; click the
item label to open the source edit page.

Filters: language, status (including *Missing*, *New suggestion*, *Locked*), content type and a text
search in source and translations.

## 4. Bulk actions

Select rows (or all) and choose an action, optionally restricted to one language:

| Action | Capability |
|---|---|
| Translate / re-translate | translate |
| Mark reviewed, Lock, Unlock, Delete translations | review |
| Exclude from translation (item is skipped from now on) | review |

Re-translating a reviewed or locked translation stores the result as a **suggestion** and never
overwrites the human text.
