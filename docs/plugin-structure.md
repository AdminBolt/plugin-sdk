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

$plugin->on(Hook::BEFORE_DOMAIN_CREATION, function (HookRequest $hook) use ($plugin) {
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
        { "event": "before_domain_creation", "blocking": true, "timeout": 5 }
    ],
    "settings": [
        { "key": "blocked_suffixes", "type": "string", "label": "Blocked suffixes", "required": true }
    ]
}
```

Field by field in [manifest.md](manifest.md). Validate one before you ship it:

```
bolt-plugin validate
```

## The two transports

**`http`** is the default. The plugin runs as a small resident listener on a
Unix socket or a loopback port, and the panel POSTs each delivery to it. Fast
enough for a blocking hook on the user's critical path.

**`cli`** executes the entrypoint once per delivery, envelope on stdin and
answer on stdout. It costs a process spawn per hook, so it is the wrong choice
for a busy `after_*` hook, but it needs no resident process and no port. Set
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

Changing a setting fires `plugin_settings_updated`, so a plugin that caches
derived state can rebuild it.

## Testing a plugin

The SDK's runtime is a pure function of headers and a body, so the whole
delivery path is testable without a panel or a web server:

```php
$plugin = Plugin::create($manifest, $config, http: new FakeHttpClient());
$result = $plugin->httpRuntime()->handle('POST', '/', $headers, $body);

self::assertSame('reject', $result->json()['status']);
```

`bolt-plugin hook <name> --payload='{...}'` signs and delivers a fixture to a
running plugin, which is the fastest way to see a handler run for real.

## Publishing

Tag a release and the panel can install it from a Git URL, a release tarball,
or the plugin directory. Nothing needs to be added to the panel, and no panel
release is involved.
