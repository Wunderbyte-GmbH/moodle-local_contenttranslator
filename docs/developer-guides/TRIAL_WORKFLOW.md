# Wunderbyte trial workflow

How the setup wizard starts the free Wunderbyte trial: what the wizard shows, what a click triggers, and how
the site and the trial service exchange the key. For the user view see
[Setup, section 8](../user/setup/README.md#8-free-wunderbyte-trial); for the web service see
[PHP API and web services](PHP_API_AND_WEBSERVICES.md).

Code: `classes/trial/trial_provisioner.php`, `classes/trial/provider_compat.php`,
`classes/external/request_trial_key.php`, `trial_challenge.php`, `amd/src/trial.js`.

## What the wizard shows and what a click triggers

The box needs the capability `local/contenttranslator:requesttrial`. Only the path through *Start my free trial
now* sends data to Wunderbyte.

```mermaid
flowchart TD
    A["Admin opens the wizard"] --> B{"Capability<br/>requesttrial?"}
    B -- no --> B0["No trial box"]
    B -- yes --> C{"State of the site"}

    C -- "Wunderbyte provider<br/>enabled" --> D1["Box: connected<br/>budget 0, automation off"]
    C -- "Wunderbyte provider<br/>exists but is off" --> D2["Button:<br/>Use the existing Wunderbyte provider"]
    C -- "no Wunderbyte provider" --> D3["Button:<br/>Start my free trial now"]
    C -- "no provider plugin" --> D4["Hint:<br/>install aiprovider_wunderbyte"]
    C -- "core AI not available" --> D5["Hint:<br/>core AI is missing"]

    D2 --> R["Web service request_trial_key<br/>consented = false"]
    R --> R1["Enable the provider<br/>no modal, no network call, no event"]
    R1 --> OK["Page reloads:<br/>box shows connected"]

    D3 --> M["Consent modal<br/>the checkbox unlocks the button"]
    M --> W{"Moodle 4.5 and an existing<br/>configuration would be replaced?"}
    W -- yes --> W1["Warning in the modal<br/>confirmation required"]
    W1 --> G
    W -- no --> G["Agree:<br/>request_trial_key<br/>consented = true"]
    G --> N{"Wunderbyte provider<br/>exists by now?"}
    N -- yes --> R1
    N -- no --> E["Event trial_consent_given"]
    E --> X["Cache the nonce,<br/>POST to the trial service"]
    X --> Q{"Answer of the service"}
    Q -- "200 with apikey" --> P["Create and enable<br/>the provider"]
    P --> OK
    Q -- "error" --> ERR["Message in the wizard<br/>see the table below"]

    classDef act fill:#d5e3f3,stroke:#3a6ea5,color:#0e2540
    classDef ok fill:#cfe8dd,stroke:#2f7d62,color:#0d2b20
    classDef ext fill:#f6e3b8,stroke:#a26b0c,color:#33230a
    classDef stop fill:#f4d3d0,stroke:#b0403a,color:#3a0f0c
    class D2,D3 act
    class D1,OK,P ok
    class X ext
    class B0,D4,D5,ERR stop
```

Legend: blue = button in the wizard, yellow = call to `llm.wunderbyte.at`, green = provider ready,
red = hint or stop.

## Key exchange with the trial service

The service checks that the request really comes from the site: it calls the site back and expects the nonce
that was cached before the POST.

```mermaid
sequenceDiagram
    autonumber
    actor Admin
    participant UI as Wizard in the browser
    participant WS as Moodle request_trial_key
    participant P as Moodle trial_provisioner
    participant C as Moodle trial_challenge.php
    participant S as Trial service llm.wunderbyte.at

    Admin->>UI: Start my free trial now
    UI-->>Admin: Consent modal
    Admin->>UI: Tick the checkbox, agree
    UI->>WS: consented, strategy, confirmoverwrite
    WS->>WS: sesskey, system context, capability requesttrial
    WS->>WS: Event trial_consent_given
    WS->>P: provision()
    P->>P: Look for a Wunderbyte provider, none found
    P->>P: Create nonce, cache trialnonce, TTL 600 s
    P->>S: POST /api/moodle-trial with wwwroot and nonce
    Note over S,C: The service checks where the request comes from
    S->>C: GET /local/contenttranslator/trial_challenge.php?token=nonce
    C-->>S: nonce as text/plain, the nonce is deleted
    S->>S: IP limit, global limit, one trial per site
    S-->>P: 200 with apikey
    P->>P: Create the provider, endpoint and model are fixed in the code
    P-->>WS: code created
    WS-->>UI: success
    UI-->>Admin: Message, reload after 2.5 s
```

## Rules that shape the flow

- **Consent only for a new key.** An existing Wunderbyte provider is reused without modal and without event,
  because nothing is sent to Wunderbyte.
- **The nonce is single-use and valid for ten minutes.** It is cached before the POST; the challenge page
  deletes it on the first call.
- **The endpoint is fixed in the code.** The endpoint the service reports is ignored; the provider always points
  at `https://llm.wunderbyte.at/v1/chat/completions`.
- **One trial per site, shared credit.** All Wunderbyte plugins (booking agent, translator) use the same trial,
  so the wizard suggests budget 0 and automation off.
- **Moodle 4.5 overwrite protection.** There is one configuration per provider plugin; without confirmation the
  trial replaces nothing (code `needsconfirm`).
- **Reachability.** The site must be reachable from the internet over https, or the callback of the service fails.

## Result codes

`local_contenttranslator_request_trial_key` returns `success`, `message` and `code`.

| Code | Cause | Message to the admin |
|---|---|---|
| `created` | service answers 200 with a key | provider created and enabled |
| `reused` | a Wunderbyte provider exists | the existing provider is used, switched on if needed |
| `noconsent` | no provider yet, consent missing | no trial without consent |
| `needsconfirm` | Moodle 4.5, configuration would be replaced, not confirmed | confirm the overwrite |
| `noprovider` | no provider plugin installed | hint with a link to `aiprovider_wunderbyte` |
| `noconnection` | the server cannot reach the service | check firewall or proxy of the server |
| `unreachable` | 403 `origin_unverified` | Wunderbyte could not verify the site, shows the checked URL |
| `alreadyused` | 409 `already_issued` | the trial of the site is used up, with a buy link |
| `iplimit`, `globallimit`, `ratelimited` | 429, codes `ip_limit` and `global_limit` | limit reached, try later |
| `unavailable` | 503 | trial service temporarily unavailable |
| `failed` | 502 `upstream_failed`, 422 `invalid_request`, anything else | the trial could not be set up |
| `coreai` | core AI subsystem missing | Moodle AI is not available |
