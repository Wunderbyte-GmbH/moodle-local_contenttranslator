# Engine API

Engines translate segments (whole fields) and are discovered through the
`local_contenttranslator\hook\register_engines` hook.

```php
// db/hooks.php
$callbacks = [[
    'hook' => \local_contenttranslator\hook\register_engines::class,
    'callback' => \local_myengine\hook_listener::class . '::register_engines',
]];
```

```php
use local_contenttranslator\engine\engine;
use local_contenttranslator\engine\result;
use local_contenttranslator\engine\segment;

class deepl_engine implements engine {
    public function get_name(): string { return 'deepl'; }
    public function get_display_name(): string { return 'DeepL'; }
    public function is_available(): bool { return (bool)get_config('local_myengine', 'apikey'); }
    public function is_available_for_user(int $userid): bool { return $this->is_available(); }
    public function is_external(): bool { return true; }     // honours the per-course "no external engines" opt-out
    public function supports(string $sourcelang, string $targetlang): bool { return true; }
    public function supports_html(): bool { return true; }   // true: raw HTML is sent, no placeholders
    public function max_batch_size(): int { return 50; }

    /** @param segment[] $segments  @return result[] keyed by segment id */
    public function translate_batch(array $segments, string $sourcelang, string $targetlang, array $options = []): array {
        // $options: contextid, userid, strict (retry after broken markup), formality, styleguide, glossary,
        // previous (earlier source and its translation by a person, plain text; null if none)
        $results = [];
        foreach ($segments as $segment) {
            // ... call the API ...
            $results[$segment->id] = new result(
                id: $segment->id, success: true, text: $translated, model: 'deepl',
            );
            // On errors: new result($segment->id, false, '', $message, $ratelimited, retryable: $temporary);
        }
        return $results;
    }
}
```

Contract:

- Return one `result` per segment. `ratelimited = true` makes the task back off and retry later
  instead of failing the item.
- `retryable = true` marks a failure that is probably temporary (gateway timeout, an answer that was cut off).
  The pipeline calls the engine once more with the same prompt; a second failure fails the item. Use it for
  single items that went wrong, `ratelimited` for "stop the whole job".
- Never return an answer that was cut off as a success. Check the finish reason of the provider: a text that
  stopped because of the length limit is a failed result, not a translation. LLMs that reason before they
  answer can return their partial reasoning as content.
- Engines with `supports_html() === false` receive protected text: markup and syntax are replaced by
  `<ph id="N"/>` placeholders that must be returned unchanged, each exactly once, tag placeholders in
  order. The pipeline validates this and retries once with `options['strict'] = true`.
- Do not store anything yourself; the pipeline logs usage (`budget::log_usage`) and writes the
  translation.
- `is_available_for_user()` is where a policy acceptance check belongs (the core AI engine checks the
  Moodle AI policy of the calling or service user).

Routing: per language an engine and a fallback can be chosen in the settings; the site default applies
otherwise. Prices per million characters are configured per engine name (`price_<name>`).
