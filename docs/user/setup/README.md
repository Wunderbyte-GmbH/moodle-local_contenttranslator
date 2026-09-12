[Back to parent section](../../README.md)

# Setup

---

## Quick setup path

1. Install both plugins (`local/contenttranslator`, `filter/contenttranslator`) and run the upgrade.
2. Enable the **Content translator** filter and move it to the top: [/admin/filters.php](/admin/filters.php).
3. Enable *Filter all strings*: [/admin/search.php?query=filterall](/admin/search.php?query=filterall).
4. Run the setup wizard: [/local/contenttranslator/wizard.php](/local/contenttranslator/wizard.php).
5. Verify with *Reports → System status → Content translator setup*.

---

## Table of Contents

1. [Requirements](#1-requirements)
2. [Filter configuration](#2-filter-configuration)
3. [Setup wizard](#3-setup-wizard)
4. [Engines](#4-engines)
5. [Service user and AI policy](#5-service-user-and-ai-policy)
6. [System status check](#6-system-status-check)
7. [All settings](#7-all-settings)

---

## 1. Requirements

- Moodle 4.5 LTS or 5.x, PHP 8.1 or later.
- For the **Moodle AI subsystem** engine: at least one AI provider with the *Generate text* action
  enabled (*Site administration → General → AI → AI providers*). Any provider works (OpenAI, Azure
  OpenAI, Ollama, ...).
- Cron must run; all translation happens in background tasks.

## 2. Filter configuration

The filter is the only way to intercept rendered text in Moodle. Two things matter:

- **Order.** The Content translator filter must be **first**, so it sees the untouched source text
  before other filters (multilang, glossary auto-linking, emoticons, ...) change it.
- **Filter all strings.** Course names, section names and activity names go through `format_string()`.
  Filters only touch those when `filterall` is on.

Both are checked by the system status check.

## 3. Setup wizard

`/local/contenttranslator/wizard.php` collects the minimum configuration:

| Section | What you set |
|---|---|
| Engine | default engine and its price per million characters (for estimates) |
| Target languages | the languages content is translated into (categories and courses can override) |
| Service user and AI policy | the user cron AI calls run as; option to create a dedicated system user; accept the AI policy for it |
| Monthly budget | characters per month; automatic translation stays off while this is 0 |

Saving the wizard writes the same values as the settings page; you can change everything later under
*Site administration → Plugins → Local plugins → Content translator → Settings*.

## 4. Engines

| Engine | Notes |
|---|---|
| **Moodle AI subsystem (LLM)** | default. Uses `generate_text` with the plugin's translation prompt. Markup is protected by placeholders and validated afterwards. External. |
| **Pseudo translation** | prefixes texts with `[lang]`, never calls the network. For demos, tests and dry runs. |

A **fallback engine** can be configured; it is used when the default engine fails or returns broken
markup twice. Per language you can pick a different engine. Further engines plug in through the
[Engine API](../../developer-guides/ENGINE_API.md).

## 5. Service user and AI policy

Moodle's AI subsystem requires every user to accept the AI policy before actions run in their name.

- Interactive **Translate with AI now** runs as the clicking user (they see the normal policy dialog
  the first time they use any AI feature).
- Automatic jobs (on save, backlog, bulk) run as the **translation service user**. The wizard can create
  a dedicated "Content Translator" user (auth `nologin`, not enrollable) and accept the policy for it.

Without a service user that has accepted the policy, automatic jobs using the Moodle AI engine refuse
to run and the status check turns yellow. Costs are still attributed per course in Moodle's
`ai_action_register` because every call carries the real context id.

## 6. System status check

*Site administration → Reports → System status → Content translator setup* reports:

- no target languages, no budget
- filter missing / disabled / not first, `filterall` off
- service user missing or without policy acceptance
- default engine unavailable (no AI provider with *Generate text*)

## 7. All settings

| Setting | Default | Meaning |
|---|---|---|
| Target languages | – | site-level list |
| Automatic translation on for new courses | on | inherited by courses without own setting |
| Default engine / Fallback engine | core_ai / none | routing |
| Price per 1M characters (per engine) | 0 | estimates only |
| Prompt template | shipped prompt | placeholders `{sourcelang} {targetlang} {formality} {styleguide} {glossary} {context} {text}` |
| Translation service user | – | see above |
| Monthly budget (characters) | 0 | see [Budget](../budget/README.md) |
| Enable automatic translation | on | master switch (needs budget) |
| Debounce (seconds) | 120 | wait after a save before translating |
| Backlog window start / end | 0 / 0 | hours; equal = always |
| Items per job | 200 | per ad-hoc task run |
| Courses per scan run | 20 | see [Scheduled tasks](../scheduled_tasks/README.md) |
| Tenant boundary | none | translation memory and lookups shared site-wide, per category or per course |
| Skip multilang content | on | leave `{mlang}` texts to the multilang filters |
| Set lang attributes | on | accessibility |
| "Show original" toggle | on | learners can switch to the source text |
| History retention (days) | 365 | clean-up task |
| Auto-discover text columns | on | see [Coverage](../coverage/README.md) |
| Additional skipped columns / Excluded fields / Additional sub-tables | – | see [Coverage](../coverage/README.md) |
| Per language: visibility, show stale, badge, engine, formality, style guide | – | see [Languages](../languages/README.md) |
