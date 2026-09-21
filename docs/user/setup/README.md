[Back to parent section](../../README.md)

# Setup

---

## Quick setup path

1. Install both plugins (`local/contenttranslator`, `filter/contenttranslator`) and run the upgrade.
2. Enable the **Content translator** filter and move it to the top: [/admin/filters.php](/admin/filters.php).
3. On the same page set *Apply to* of the filter to *Content and headings*.
4. Run the setup wizard: [/local/contenttranslator/wizard.php](/local/contenttranslator/wizard.php).
   No AI provider yet? Start the [free Wunderbyte trial](#8-free-wunderbyte-trial) there.
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
8. [Free Wunderbyte trial](#8-free-wunderbyte-trial)

---

## 1. Requirements

- Moodle 4.5 LTS or 5.x, PHP 8.1 or later.
- For the **Moodle AI subsystem** engine: at least one AI provider with the *Generate text* action
  enabled (*Site administration → General → AI → AI providers*). Any provider works (OpenAI, Azure
  OpenAI, Ollama, ...). Without a provider you can start the [free Wunderbyte trial](#8-free-wunderbyte-trial).
- Cron must run; all translation happens in background tasks.

## 2. Filter configuration

The filter is the only way to intercept rendered text in Moodle. Two things matter:

- **Order.** The Content translator filter must be **first**, so it sees the untouched source text
  before other filters (multilang, glossary auto-linking, emoticons, ...) change it.
- **Content and headings.** Course names, section names and activity names go through `format_string()`.
  Filters only touch those when their *Apply to* setting is *Content and headings*. Moodle has no separate
  switch for this any more: it turns the hidden `filterall` setting on as soon as one filter applies to
  headings.

Both are checked by the system status check.

## 3. Setup wizard

`/local/contenttranslator/wizard.php` collects the minimum configuration:

| Section | What you set |
|---|---|
| Free trial | shown when no AI provider can generate text, or when a Wunderbyte provider is in use; see [below](#8-free-wunderbyte-trial) |
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

- Interactive **Translate now** runs as the clicking user (they see the normal policy dialog
  the first time they use any AI feature).
- Automatic jobs (on save, backlog, bulk) run as the **translation service user**. The wizard can create
  a dedicated "Content Translator" user (auth `nologin`, not enrollable) and accept the policy for it.

Without a service user that has accepted the policy, automatic jobs using the Moodle AI engine refuse
to run and the status check turns yellow. Costs are still attributed per course in Moodle's
`ai_action_register` because every call carries the real context id.

## 6. System status check

*Site administration → Reports → System status → Content translator setup* reports:

- no target languages, no budget
- filter missing / disabled / not first, not applied to headings
- service user missing or without policy acceptance
- default engine unavailable (no AI provider with *Generate text*); for the Moodle AI engine the message adds
  that the free Wunderbyte trial can be started in the setup wizard

## 7. All settings

| Setting | Default | Meaning |
|---|---|---|
| Target languages | – | site-level list |
| Automatic translation for all courses by default | off | inherited by **every** course without its own setting, existing courses included; switching it on translates all of them and uses budget |
| Default engine / Fallback engine | core_ai / none | routing |
| Price per 1M characters (per engine) | 0 | estimates only |
| Prompt template | shipped prompt | placeholders `{sourcelang} {targetlang} {formality} {styleguide} {glossary} {previous} {context} {text}`; `{glossary}` is reserved and currently always empty |
| Translation service user | – | see above |
| Monthly budget (characters) | 0 | see [Budget](../budget/README.md) |
| Budget warning at | 80 % | notification to administrators at this share of the budget; *Off* disables only this warning |
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

## 8. Free Wunderbyte trial

A site without a working AI provider can try the translator with a free trial of the Wunderbyte AI. It is in
the setup wizard, in the box **Free trial**; the system status check points there.

**What happens**

1. You confirm a data-protection notice. It says what Wunderbyte stores about your site (its URL and IP
   address, checked once by an automated call to your site), that the AI requests run at a European data
   centre contracted by Wunderbyte, and that the content you translate (names, descriptions, page and book
   texts) is sent to the language model. User profiles are not sent.
2. The site asks the trial service at `https://llm.wunderbyte.at` for a key. The address is fixed and not a
   setting.
3. An AI provider is created and enabled with that key. Then *Translate now* works; the first time, Moodle asks
   you to accept the AI policy.

**Requirements**

- The capability `local/contenttranslator:requesttrial` (managers and administrators).
- An AI provider plugin: `aiprovider_wunderbyte` (recommended) or Moodle's own OpenAI provider.
- The site must be reachable from the internet over https. The trial service calls
  `/local/contenttranslator/trial_challenge.php` without a login to check that the request comes from your
  site. Local, intranet and VPN-only sites cannot start the trial.

**One trial per site, shared credit**

There is exactly one trial per site for all Wunderbyte plugins (for example the booking agent and this
translator), and they share one credit.

- If a Wunderbyte provider already exists, the translator uses it and requests no new key. If it was switched
  off, it is switched on again. This needs no data-protection notice, because nothing is sent to Wunderbyte.
- If the trial of the site was already used, the wizard says so and links to the page where you can buy more.
- Bulk translation uses the credit up quickly. While a Wunderbyte provider is in use, the wizard suggests **no**
  monthly budget and leaves automatic translation off. See [Budget](../budget/README.md).

**Moodle 4.5:** there is one configuration per AI provider plugin. If the plugin the trial would use (for
example the OpenAI provider) is already configured, the trial replaces that configuration; the notice says so and
you must confirm it. On Moodle 5.x the trial creates a separate provider instance.

**If it does not work**

| Message | Cause |
|---|---|
| This server could not reach the Wunderbyte trial service | outgoing firewall or proxy of your server |
| Wunderbyte could not verify your site | the site is not reachable from the internet, or a login, firewall, VPN or maintenance page blocks `trial_challenge.php` |
| The free trial for this site has already been used up | the one trial of the site exists already; buy more or use your own provider |
| Too many trials from this address / limit reached | abuse limits of the trial service; try later or write to info@wunderbyte.at |
| No AI provider plugin is installed yet | install the [Wunderbyte AI provider](https://github.com/Wunderbyte-GmbH/moodle-aiprovider_wunderbyte) |
