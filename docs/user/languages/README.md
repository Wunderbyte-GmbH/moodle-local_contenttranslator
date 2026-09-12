[Back to parent section](../../README.md)

# Languages and visibility

---

## Quick setup path

1. Set the site target languages in the wizard or on the settings page.
2. For each language a settings block appears: choose **Visibility**, **Show stale translations**,
   the **"Machine translated" indicator**, an optional engine, **Formality** and a **Style guide**.
3. Override languages per category or course if needed, see [Course and category settings](../course_settings/README.md).

---

## Table of Contents

1. [Target languages](#1-target-languages)
2. [Source language](#2-source-language)
3. [Visibility of machine translations](#3-visibility-of-machine-translations)
4. [Stale translations](#4-stale-translations)
5. ["Machine translated" indicator and "Show original"](#5-machine-translated-indicator-and-show-original)
6. [Language fallback](#6-language-fallback)
7. [Formality and style guide](#7-formality-and-style-guide)

---

## 1. Target languages

Any language code Moodle knows can be a target language; users normally switch their interface to an
installed language pack, so install the packs you translate into. Categories and courses can add or
remove languages ([Course and category settings](../course_settings/README.md)).

## 2. Source language

The source language of an item is resolved as: forced activity language → forced course language →
site language. Items whose source language equals a target language are skipped for that language.

## 3. Visibility of machine translations

| Mode | Learners see |
|---|---|
| **Show immediately** (default) | machine translations at once, marked as machine translated |
| **Show only after human review** | only *reviewed* translations; otherwise the source text |

The mode is set per language and can be overridden per category or course.

## 4. Stale translations

When the source changes, the translation becomes *stale*. Per language you decide whether the old
translation is still shown until it is updated (default) or whether learners see the source text.
In "reviewed only" mode a stale translation is shown only if it had been reviewed.

## 5. "Machine translated" indicator and "Show original"

| Indicator | Effect |
|---|---|
| Off | no visual hint |
| Badge on each text block | a small badge after every translated block |
| One banner per page (default) | one discreet banner at the top of the content area |

The **Show original** toggle (setting) adds a link to the banner; the choice is remembered for the
session. Translated text carries `lang="xx"`; source text shown to a user of another language is
wrapped with the source `lang` attribute for assistive technology.

## 6. Language fallback

Lookup order at render time: user language → its parent language (e.g. `de_du` → `de`) → source text.
A translation is never shown in a language other than the user's language chain.

## 7. Formality and style guide

- **Formality**: default / formal (Sie, vous) / informal (du, tu), passed to the engine prompt.
- **Style guide**: free text added to the prompt for this language (tone, gender-inclusive language,
  terminology). Keep it short; it is sent with every request.
