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
| `output_test` | template, settings tree, status check, external functions |
| `privacy/provider_test` | export and deletion |
| `filter_contenttranslator/text_filter_test` | filter output for translated, untranslated and source-language users, show original, visibility |
| `behat/workbench.feature` | dashboard scan, editor translate/review/lock, course settings, wizard (core steps only) |

Notes for contributors:

- The pseudo engine (`engine = pseudo`) makes every test deterministic and network-free.
- The event observer is *internal* on purpose: external observers are deferred until the DB
  transaction commits, which never happens inside PHPUnit on PostgreSQL.
- Language parameters use `PARAM_ALPHANUMEXT` plus own validation because `PARAM_LANG` only accepts
  installed language packs.
