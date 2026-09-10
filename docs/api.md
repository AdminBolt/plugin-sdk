# Calling the panel API

The panel exposes two REST APIs and the SDK wraps both.

**Admin** is server-wide: accounts, plans, resellers, every account's
resources. **Client** is scoped to exactly one hosting account, and the panel
scopes every query it runs to that account.

```php
$plugin->admin()->hostingAccounts()->all();
$plugin->client('acme')->domains()->all();
```

Prefer the client API. A narrower key is a smaller blast radius when the
plugin has a bug, and most plugins only ever act on the account whose
operation triggered the hook:

```php
$plugin->on(Hook::AFTER_DOMAIN_CREATION, function (HookRequest $hook) use ($plugin) {
    $plugin->clientFor($hook)->dnsRecords()->createRecord(
        domainId: $hook->payload('id'),
        type: 'TXT',
        name: '_acme-verify',
        content: 'provisioned-by-my-plugin',
    );
});
```

## Authentication and scopes

The panel mints an API key when the plugin is installed and writes it into
`runtime.json`. The SDK sends it as `X-API-Key` and `X-API-Secret` on every
request. The key is restricted to the endpoints the manifest declared:

```json
"api": { "scopes": ["client:domains:read", "client:dns-records:write"] }
```

A call outside those scopes comes back 403. Declare the narrowest set that
works: the scopes are shown to the administrator approving the install, and
"this plugin can read and write everything" is a reason to say no.

Acting on another account with an admin or reseller key sends
`X-Hosting-Account`. A reseller key may only name accounts it owns; a
hosting-account key ignores the header entirely and always acts on itself.

## Errors

A non-2xx answer throws `ApiException`, carrying the panel's own message:

```php
try {
    $plugin->client('acme')->domains()->create(['domain' => 'example.com']);
} catch (ApiException $e) {
    if ($e->isAuthorizationFailure()) {
        // The key is wrong, inactive, IP-blocked, or not scoped to this endpoint.
        // Retrying will never help.
    }

    $e->validationErrors(); // ['domain' => ['The domain field is required.']]
}
```

`TransportException` is different: no answer arrived at all. The panel may
still have done the work.

## Retries

Reads are retried on 429 and 5xx with exponential backoff and jitter,
honouring `Retry-After`.

Writes are **not** replayed after a transport failure. A timeout on a domain
creation is ambiguous, the panel may well have created it, and a replay would
create a second one. The exception reaches the caller instead. A 429 is the
exception to the exception: it is refused before the panel does any work, so
replaying it repeats nothing.

## What is wrapped

Typed resources cover accounts, plans, resellers, domains, DNS records, email
accounts, databases and their users, FTP accounts, cron jobs, IP blockers, TLS
certificates, the file manager, WordPress and services.

Everything else is one call away, signed and retried identically:

```php
$plugin->admin()->raw()->get('system-updates');
$plugin->client('acme')->raw()->post('applications/12/restart');
```

Endpoints the panel does not implement say so instead of failing at runtime.
`$api->databases()->update(...)` throws immediately with the reason: a
database has no editable fields, so there is no update endpoint.

## Hooks fire on your own calls

Creating a domain through the API fires `before_domain_creation` and
`after_domain_creation` like any other creation, including for the plugin that
made the call. A plugin that creates a domain from inside its own
`after_domain_creation` handler will recurse. Guard with the delivery id or a
marker of your own.
