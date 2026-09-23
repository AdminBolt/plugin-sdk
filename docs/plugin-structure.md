# Plugin structure

A plugin is an ordinary PHP application. It does not extend a panel class, it
is not loaded by the panel's autoloader, and it does not share a process with
the panel. It speaks two contracts and nothing else:

1. It receives **signed hook deliveries** from the panel.
2. It calls the panel's **admin and client REST APIs** with a key the panel
   minted for it.

That is the whole coupling. A plugin can be written with Laravel, Symfony,
Slim or no framework at all, it is released on its own schedule, and a bug in
it cannot take the panel down.

## Layout

Only two paths are required: `plugin.json` and the entrypoint it names.
Everything else is convention.

```
my-plugin/
├── plugin.json          required, the manifest
├── composer.json        requires adminbolt/plugin-sdk
├── public/
│   └── index.php        required, the entrypoint the manifest names
├── src/                 your code
│   └── Handlers/
├── ui/                  optional, pages the panel embeds
├── tests/
├── README.md
└── LICENSE
```

At install time the panel adds two things next to your files. Neither is
yours to write, and neither belongs in version control:

```
├── runtime.json         credentials and settings, mode 0600
└── var/                 data, cache and logs the panel guarantees are writable
```

Put both in `.gitignore`. `runtime.json` holds a live API secret.

## The entrypoint

```php
<?php

use AdminBolt\Plugin\Hook\Hook;
use AdminBolt\Plugin\Hook\HookRequest;
use AdminBolt\Plugin\Hook\HookResponse;
use AdminBolt\Plugin\Plugin;

require __DIR__ . '/../vendor/autoload.php';

$plugin = Plugin::boot(__DIR__);

$plugin->on(Hook::DOMAIN_CREATING, function (HookRequest $hook) use ($plugin) {
    $domain = (string) $hook->payload('domain');

    if (str_ends_with($domain, '.test')) {
        return HookResponse::reject('.test domains cannot be hosted here.');
    }

    return HookResponse::ok();
});

$plugin->run();
```

`Plugin::boot()` finds `plugin.json` by walking up from the directory you give
it, loads `runtime.json`, and starts logging where the panel expects to find
the log. `run()` serves deliveries using whichever transport the manifest
declares, and is the last line of the file.

## The manifest

`plugin.json` is the only file the panel reads before it trusts anything. A
minimal one:

```json
{
    "$schema": "https://raw.githubusercontent.com/AdminBolt/plugin-sdk/main/schema/plugin.schema.json",
    "id": "domain-policy",
    "name": "Domain Policy",
    "version": "1.0.0",
    "sdk": "^1.0",
    "runtime": {
        "php": "^8.2",
        "entrypoint": "public/index.php",
        "transport": "http"
    },
    "api": {
        "scopes": ["client:domains:read"]
    },
    "hooks": [
        { "event": "domain.creating", "blocking": true, "timeout": 5 }
    ],
    "settings": [
        { "key": "blocked_suffixes", "type": "string", "label": "Blocked suffixes", "required": true }
    ]
}
```

Field by field in [manifest.md](manifest.md), and pages in [ui.md](ui.md).
Validate one before you ship it:

```
bolt-plugin validate
```

## The two transports

**`http`** is the default. The plugin runs as a small resident listener on a
Unix socket or a loopback port, and the panel POSTs each delivery to it. Fast
enough for a blocking hook on the user's critical path.

**`cli`** executes the entrypoint once per delivery, envelope on stdin and
answer on stdout. It costs a process spawn per hook, so it is the wrong choice
for a busy notification hook, but it needs no resident process and no port. Set
`"transport": "cli"` and change nothing else: the same handlers run.

## Where a plugin lives

The panel installs plugins under `/usr/local/bolt/plugins/<id>/`, each running
as its own unprivileged system user. A plugin cannot read another plugin's
`runtime.json`, and it never runs as root. Whatever the plugin needs to do to
the server, it does through the panel's API, which is where the permission
checks and the audit trail are.

## Settings

Settings declared in the manifest are rendered by the panel as a form, stored
encrypted, and handed back through `runtime.json`:

```php
$token = $plugin->setting('api_token');
```

A `secret` setting is write-only in the panel UI: the operator can replace it
but never read it back. Do not declare a default for one.

Changing a setting fires `plugin.configured`, so a plugin that caches
derived state can rebuild it.

## Testing a plugin

The SDK's runtime is a pure function of headers and a body, so the whole
delivery path is testable without a panel or a web server:

```php
use AdminBolt\Plugin\Testing\FakeHttpClient;

$plugin = Plugin::create($manifest, $config, http: new FakeHttpClient());
$result = $plugin->httpRuntime()->handle('POST', '/', $headers, $body);

self::assertSame('reject', $result->json()['status']);
```

`FakeHttpClient` answers from a queue and records what it was asked, and it
covers both halves of a plugin: the panel calls made through `admin()` and
`client()`, and whatever the plugin itself calls through `http()`.

```php
$http = (new FakeHttpClient())
    ->queueJson(200, ['version' => '11.2.0'])
    ->queue(new TransportException('Connection refused'));

// ... the plugin runs ...

self::assertSame(['https://grafana.test/api/health'], $http->urls());
```

Queue a `TransportException` for the case where nothing answers at all. It is
a different path from a 500, and usually the one with the bug in it.

`bolt-plugin hook <name> --payload='{...}'` signs and delivers a fixture to a
running plugin, which is the fastest way to see a handler run for real.

## Publishing

A plugin ships on its own schedule. Nothing is added to the panel and no panel
release is involved: an operator installs it, approves what it asks for, and it
starts working.

There are two ways onto a server.

**The marketplace.** Panels browse, install and update plugins from the
AdminBolt plugin marketplace, from the Plugins page. To publish there:

1. Build the archive. `bolt-plugin package` validates the manifest, refuses
   anything that would carry credentials (`runtime.json`, `.env`, `var/`), and
   writes `build/<id>-<version>.tar.gz`.
2. Upload it with an API token issued for your marketplace account:

   ```sh
   curl -H "Authorization: Bearer $PLATFORM_TOKEN" -H "Accept: application/json" \
        -F archive=@build/acme-widgets-1.2.0.tar.gz -F changelog="What changed" \
        https://plugins.adminbolt.com/api/v1/publish
   ```

   The first archive for an id creates the listing under your account, and
   every later one needs a higher `version` in `plugin.json`. Re-uploading the
   exact same archive is a no-op (`200`), so a re-run CI job is harmless; a
   different archive under an existing version is refused (`409`).
3. Screenshots are a separate upload, `POST /api/v1/plugins/{id}/images`, one
   image per request. The archive panels download stays free of them.

A new version waits for review before panels are offered it, unless your
account is verified for automatic approval. The marketplace's own
[API reference](https://github.com/AdminBolt/plugins-platform-app/blob/main/docs/api.md)
has every status code; the official plugins' `publish.yml` workflows are a
working CI setup to copy.

**By hand.** Unpack the archive into the panel's plugin directory
(`/usr/local/bolt/plugins/<id>`), and it appears on the Plugins page ready to
install. This is what development and private plugins do.

Either way, what an operator approved is recorded against the version they saw.
An update that asks for a new API scope, a new or changed command, or a hook on
new terms (a new subscription, one that became blocking, one that now fails
closed) stays off until somebody approves it again.
