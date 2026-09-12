[Back to parent section](../../README.md)

# Budget and cost control

---

## Quick setup path

1. Set a **Monthly budget (characters)** in the wizard (suggested: 2,000,000).
2. Enter a **Price per 1M characters** for your engine so the dashboard can show € estimates.
3. Watch the budget bar on the site dashboard; administrators are notified at 80 % and 100 %.

---

## Table of Contents

1. [Why characters](#1-why-characters)
2. [Safe default: nothing automatic without a budget](#2-safe-default-nothing-automatic-without-a-budget)
3. [What counts](#3-what-counts)
4. [Notifications and pausing](#4-notifications-and-pausing)
5. [Estimates](#5-estimates)
6. [Exceeding the budget on demand](#6-exceeding-the-budget-on-demand)

---

## 1. Why characters

The budget unit is **source characters sent to an engine**: known before the call, identical for LLMs
and DeepL, and enforceable. Token usage of the Moodle AI subsystem is recorded as well (usage log and
`ai_action_register`) for reporting.

## 2. Safe default: nothing automatic without a budget

On a fresh install automatic (on save, backlog) and bulk translation are **off until a monthly budget
is set**. On-demand *Translate with AI now* in the editor works immediately. No surprise bills.

## 3. What counts

- Only the visible text of a field (markup stripped) counts.
- Translation memory hits are free.
- Failed engine calls are logged but do not count.
- The budget is site-wide per calendar month; there is no per-course billing (the usage log still
  records the course for insight).

## 4. Notifications and pausing

- At **80 %** all site administrators receive a notification (message provider *Translation budget
  and automation notices*).
- At **100 %** automatic and bulk jobs pause; pending items stay queued and continue next month.
- Both are sent once per month; the event `budget_threshold_reached` is triggered.

## 5. Estimates

*Translate course* shows a pre-flight estimate per language: items, translation memory hits and the
characters that will be sent, with an € figure computed from the engine price. The remaining budget is
shown next to it.

## 6. Exceeding the budget on demand

Users with the capability `local/contenttranslator:exceedbudget` may still translate single items on
demand when the budget is exhausted. Automatic jobs never exceed it.
