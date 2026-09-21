[Back to parent section](../../README.md)

# Budget and cost control

---

## Quick setup path

1. Set a **Monthly budget (characters)** in the wizard (suggested: 2,000,000).
2. Enter a **Price per 1M characters** for your engine so the dashboard can show € estimates.
3. Watch the budget bar on the site dashboard; administrators are notified at the warning level
   (80 % by default), when the budget is used up and when it no longer fits the next text.

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
is set**. On-demand *Translate now* in the editor works immediately. No surprise bills.

This holds for the free Wunderbyte trial as well. The trial credit belongs to the **site** and is shared with the
other Wunderbyte AI features (for example the booking agent), and bulk translation uses it up quickly. So while a
Wunderbyte provider is in use, the setup wizard suggests no budget and leaves automatic translation off; you set
a budget on purpose. See [Setup](../setup/README.md#8-free-wunderbyte-trial).

## 3. What counts

- Only the visible text of a field (markup stripped) counts.
- Translation memory hits are free.
- Failed engine calls are logged but do not count.
- The budget is site-wide per calendar month; there is no per-course billing (the usage log still
  records the course for insight).

## 4. Notifications and pausing

All site administrators receive these notifications (message provider *Translation budget and
automation notices*). Clicking one opens the site dashboard of the content translator.

| When | Notification |
|---|---|
| The share set in *Budget warning at* is used (default 80 %, can be switched off) | *Translation budget: N % used* |
| The budget is used up | *Translation budget used up: automatic translation paused* |
| The next text does not fit into the rest of the budget | *Automatic translation paused: budget not enough for further texts* |

- Automatic and bulk jobs pause once the next text does not fit; pending items stay queued and continue
  next month or as soon as the budget is raised.
- Each notification is sent once per month. "Used up" and "not enough" mean the same for the reader,
  so only the first of the two is sent.
- Raising the budget starts over: the notifications can arrive again for the new amount.
- While paused, the dashboard shows a red notice above the budget bar with a *Change budget* link.
- Each notification triggers the event `budget_threshold_reached`.

## 5. Estimates

*Translate course* shows a pre-flight estimate per language: items, translation memory hits and the
characters that will be sent, with an € figure computed from the engine price. The remaining budget is
shown next to it.

## 6. Exceeding the budget on demand

Users with the capability `local/contenttranslator:exceedbudget` may still translate single items on
demand when the budget is exhausted. Automatic jobs never exceed it.
