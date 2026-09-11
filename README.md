# AdminBolt Plugin SDK

PHP SDK for building [AdminBolt](https://adminbolt.com) panel plugins.

A plugin is an ordinary PHP application. It does not extend a panel class, it
is not loaded by the panel's autoloader, and it does not share a process with
the panel. It speaks two contracts: it receives signed hook deliveries, and it
calls the panel's REST APIs with a scoped key. That is the whole coupling.

Which means a plugin ships on its own schedule, with no panel release, and a
bug in it cannot take the panel down.

```
composer require adminbolt/plugin-sdk
```

Requires PHP 8.2 and ext-curl. Nothing else: no framework, no HTTP library.

## A plugin

```php
<?php

use AdminBolt\Plugin\Hook\Hook;
use AdminBolt\Plugin\Hook\HookRequest;
use AdminBolt\Plugin\Hook\HookResponse;
use AdminBolt\Plugin\Plugin;

require __DIR__ . '/../vendor/autoload.php';

$plugin = Plugin::boot(__DIR__);

// Runs inside the operation and can stop it.
$plugin->on(Hook::DOMAIN_CREATING, function (HookRequest $hook) {
    if (str_ends_with((string) $hook->payload('domain'), '.test')) {
        return HookResponse::reject('.test domains cannot be hosted here.');
    }

    return HookResponse::ok();
});

// Runs after it succeeded, and calls back into the panel.
$plugin->on(Hook::DOMAIN_CREATED, function (HookRequest $hook) use ($plugin) {
    $plugin->clientFor($hook)->dnsRecords()->createRecord(
        domainId: (int) $hook->payload('id'),
        type: 'TXT',
        name: '_verify',
        content: 'provisioned',
    );
});

$plugin->run();
```

Plus a `plugin.json` naming the plugin, the entrypoint, the hooks it wants and
the API scopes it needs. That is a complete plugin.

## Start here

```
composer create-project adminbolt/plugin-starter my-plugin
```

Or scaffold one with the CLI:

```
bolt-plugin new my-plugin
bolt-plugin validate
bolt-plugin hook domain.creating --payload='{"domain":"example.test"}'
```

## Documentation

- [Plugin structure](docs/plugin-structure.md) — layout, entrypoint, transports, testing
- [Hooks](docs/hooks.md) — the catalogue, the envelope, signatures, vetoes and mutations
- [Calling the panel API](docs/api.md) — admin and client APIs, scopes, errors, retries
- [Plugin pages](docs/ui.md) — putting your own pages in the panel
- [Slots](docs/slots.md) — drawing in the panel's own footer, sidebar and pages
- [Themes](docs/themes.md) — shipping a stylesheet the panel can wear
- [Running commands](docs/commands.md) — declaring what a plugin may run in an account
- [plugin.json](docs/manifest.md) — every manifest field
- [What the panel implements](docs/panel-integration.md) — the panel-side half of the contract

## Hooks in one paragraph

Hooks are `<resource>.<verb>`, and the tense tells you the phase. Blocking
hooks are present participles like `domain.creating`: they run inside the
operation and can veto it with a message the user sees, or adjust an
allow-listed input. Notification hooks are past participles like
`domain.created`: they run once it has succeeded, are queued and retried, and
cannot change anything. Every delivery is HMAC signed over the raw body with
the timestamp inside the signed string, so a captured delivery cannot be
replayed. A veto is HTTP 200 with `"status": "reject"`, never a 4xx, because a
4xx is indistinguishable from a broken listener.

The full catalogue is in [docs/hooks.md](docs/hooks.md).

## Calling the panel

```php
$plugin->admin()->hostingAccounts()->findByUsername('acme');
$plugin->client('acme')->domains()->all();
$plugin->client('acme')->sslCertificates()->issue($domainId);
$plugin->clientFor($hook)->files()->write('/public_html/robots.txt', "User-agent: *\n");
```

Typed resources cover accounts, plans, resellers, domains, DNS, email,
databases, FTP, cron, IP blockers, TLS, files, WordPress and services.
Anything else is `$plugin->admin()->raw()->get('system-updates')`, signed and
retried identically.

The key is minted by the panel at install time and scoped to the endpoints the
manifest declared. A call outside them comes back 403. Ask for the minimum:
the scopes are shown to the administrator approving the install.

## Running something in an account

A plugin can run `php artisan migrate` or `composer install` for the account
whose page it is drawing. It cannot run *a* command: it declares the ones it
needs, an administrator approves them by name and by argument, and the panel
builds every command line from its own copy of what was approved.

```json
"commands": [
    {
        "name": "artisan",
        "program": "php",
        "args": ["artisan", "{command}", "--no-interaction"],
        "params": { "command": { "type": "enum", "values": ["migrate:status", "optimize"] } },
        "cwd": "required"
    }
]
```

```php
$result = $plugin->clientFor($request)->cli()->run('artisan', ['command' => 'optimize'], cwd: 'shop');

$result->ok();
$result->output();
```

A non-zero exit is a result, not an exception: a failed migration has
something to tell the customer and it is on stdout. Anything that outlives a
request is `async` and polled instead.

[docs/commands.md](docs/commands.md) has the rest, including what the panel
checks before it spawns anything.

## Testing

The runtime is a pure function of headers and a body, so the whole delivery
path is testable with no panel and no web server.

```php
$plugin = Plugin::create($manifest, $config, http: new FakeHttpClient());
$result = $plugin->httpRuntime()->handle('POST', '/', $headers, $body);

self::assertSame('reject', $result->json()['status']);
```

```
composer test
```

## Related

- [plugin-starter](https://github.com/AdminBolt/plugin-starter) — a runnable plugin to copy
- [plugin-cli](https://github.com/AdminBolt/plugin-cli) — scaffold, validate, sign test deliveries, package

## License

MIT.
