[Back to parent section](../../README.md)

# Scheduled and ad-hoc tasks

| Task | Default schedule | Purpose |
|---|---|---|
| **Content translator: translate course content** (ad-hoc) | queued on demand | translates the pending items of one course into one language, up to *Items per job*, then re-queues itself if more is waiting. Rate limits of the AI provider make the task fail and retry with back-off; an exhausted budget stops it quietly. |
| **Scan for changed content** | every 15 minutes | checks *Courses per scan run* courses (oldest scan first) for content changed without events (imports, restores, web services, direct DB edits) and site-level content |
| **Process backlog** | every 30 minutes | inside the configured time window, queues jobs for every course/language pair with pending work; visible courses and courses starting soon first |
| **Clean up** | daily 03:20 | removes history older than the retention period and orphaned rows |

Triggers that queue ad-hoc jobs:

- **On save**: content events (course, section, module, sub-table records) register the change and
  queue a job after the debounce period, so repeated saves cost once.
- **Bulk**: *Translate course* on the dashboard.
- **Backlog**: the task above.
- **On demand**: *Translate now* runs synchronously in the browser request, as the clicking user.

Automatic jobs run only when a monthly budget is set, *Enable automatic translation* is on and the
course has automatic translation enabled.
