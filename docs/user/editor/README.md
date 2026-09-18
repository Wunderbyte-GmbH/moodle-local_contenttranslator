[Back to parent section](../../README.md)

# Editor and review workflow

---

## Quick setup path

1. From the dashboard, click a status badge. The editor opens with the source on the left and the
   translation on the right (TinyMCE for HTML fields, a text box for names).
2. Press **Translate now** for an immediate machine translation (runs as you).
3. Correct the text, then **Save & mark reviewed** or **Save & next** (Ctrl+Enter) to jump to the next
   item needing attention in the same course and language.

---

## Table of Contents

1. [Layout](#1-layout)
2. [Actions](#2-actions)
3. [Human edits are never overwritten](#3-human-edits-are-never-overwritten)
4. [Stale source and suggestions](#4-stale-source-and-suggestions)
5. [History and rollback](#5-history-and-rollback)
6. [Concurrent editing](#6-concurrent-editing)

---

## 1. Layout

- Status line: status badge, origin (machine / human / translation memory / imported), engine and
  model, lock state.
- Left: rendered source with a *Show raw HTML* expander and a link to edit the source content.
- Right: the translation form.
- Below: history table.

## 2. Actions

| Action | Effect | Capability |
|---|---|---|
| Translate now | immediate machine translation; for reviewed/locked translations it becomes a suggestion | translate |
| Save | stores your text as *Edited* (origin human) | translate |
| Save & mark reviewed | stores and sets *Reviewed* | review |
| Save & next | stores and opens the next item that is machine, stale, failed or has a suggestion | translate |
| Mark reviewed | approve the current text without changes | review |
| Lock / Unlock | prevent any automatic change | review |
| Delete | remove the translation (history kept until clean-up) | review |

## 3. Human edits are never overwritten

A translation with origin *human*, status *reviewed* or the *locked* flag is protected. When the
source changes or someone requests a re-translation, the engine result is stored as a **suggestion**
next to the protected text. Learners keep seeing the protected text (subject to the stale setting).

## 4. Stale source and suggestions

When the source changed, a yellow panel shows:

- a word diff of the old and the new source text,
- the new machine suggestion (if one exists), with **Accept suggestion**,
- **Keep previous translation and mark reviewed** if the change does not affect the translation.

## 5. History and rollback

Every change (machine translation, edit, review, suggestion, rollback) keeps the previous text with
user, time and reason. *Restore this version* puts the old text back as an edited draft.

## 6. Concurrent editing

If someone else saved the same translation while you were editing, saving is refused with a notice
and nothing is lost; reload and merge your change.
