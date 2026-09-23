# Testing

```bash
vendor/bin/phpunit --testsuite local_contenttranslator_testsuite
vendor/bin/phpunit --testsuite filter_contenttranslator_testsuite
vendor/bin/behat --tags @local_contenttranslator
```

| Test | Covers |
|---|---|
| `normaliser_test` | hash stability between raw and rendered text, file URL rewriting |
| `html_protector_test` | placeholder protection, restore tolerance, validation, LLM output cleaning |
| `pipeline_test` | registry, pseudo engine, translation memory, visibility and tenant lookups, status lifecycle, budget, observer and tasks, config inheritance |
| `backup_restore_test` | course backup and restore incl. module and sub-table remapping |
| `output_test` | template, settings tree, status check, external functions, AI credit tile rendering |
| `privacy/provider_test` | export and deletion, declaration of the trial call |
| `trial_test` | Wunderbyte trial: key request with a faked service, response mapping, reuse of an existing provider, Moodle 4.5 overwrite protection, consent gate, capability, wizard defaults, AI credit usage lookup (mapping, caching, unlimited/unavailable states) |
| `engine/core_ai_engine_test`, `engine_pipeline_test` | also: cut-off answers (finish reason) and temporary failures are never stored, retried once |
| `filter_contenttranslator/text_filter_test` | filter output for translated, untranslated and source-language users, show original, visibility |
| `filter_contenttranslator/rendering_test` | also: the "Show original" toggle is only offered for real translated content, never for a course/activity name alone; the `showoriginal` capability actually blocks the toggle, not just hides it |
| `behat/workbench.feature` | dashboard scan, editor translate/review/lock, course settings, wizard (core steps only) |

Notes for contributors:

- The pseudo engine (`engine = pseudo`) makes every test deterministic and network-free.
- The event observer is *internal* on purpose: external observers are deferred until the DB
  transaction commits, which never happens inside PHPUnit on PostgreSQL.
- Language parameters use `PARAM_ALPHANUMEXT` plus own validation because `PARAM_LANG` only accepts
  installed language packs.
