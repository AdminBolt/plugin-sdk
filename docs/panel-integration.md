# What the panel implements

This is the panel-side half of the contract, for whoever builds the plugin
host in bolt-panel. Plugin authors do not need it.

## The pieces

1. **A registry.** `plugins` and `plugin_hooks` tables: id, version, path,
   transport, listen address, enabled, failure policy, hook secret, api_key_id.
2. **An installer.** Fetch, validate the manifest, create the system user,
   create the directory, mint the API key, write `runtime.json`, register the
   declared hooks, start the listener, deliver `plugin.installed`.
3. **A dispatcher.** Turns a panel event into a signed delivery.
4. **A UI.** Install, configure, enable and disable, plus the delivery log.

## Minting the key

The panel creates an `ApiKey` row per install, with `allow_all_endpoints`
false and `allowed_endpoints` derived from the manifest scopes. The existing
`ApiKey::canAccessEndpoint()` already enforces the `uri|METHOD` list, so
scoping needs a scope-to-endpoint mapping and nothing new in the middleware.

`owner_type` follows the scopes: a plugin declaring only `client:*` gets a
hosting-account or reseller key, and only a plugin declaring `admin:*` gets an
admin key. Never mint an admin key for a plugin that did not ask for one.

Rotate the key and the hook secret on demand and on every upgrade. Rewrite
`runtime.json` afterwards, and sign with both secrets during the overlap so
in-flight deliveries do not fail.

## Dispatching notification hooks

Most of these already exist as events. The bridge is one subscriber that maps
an event to a delivery:

| Hook | Existing event |
| --- | --- |
| `domain.created` | `DomainProvisioned` |
| `domain.deleted` | `DomainWasDeleted` |
| `domain.renamed` | `HostingAccountDomainRenamed` |
| `account.created` | `HostingAccountProvisioned` |
| `account.deleted` | `HostingAccountDeleted` |
| `account.transferred` | `HostingAccountOwnerChanged` |
| `dns_record.created` | `DnsRecordCreated` |
| `dns_record.updated` | `DnsRecordUpdated` |
| `dns_record.deleted` | `DnsRecordDeleted` |
| `webserver.switched` | `WebServerSwitched` |

Delivery is queued, never inline. These events already run inside completed
operations that must not fail, and an HTTP call to a plugin is exactly the
kind of thing that would break them.

Suspension, email, database and TLS hooks need new events, following the same
shape as the existing ones.

## Dispatching blocking hooks

These are new dispatch points, and they go in the service, not the controller,
so that the API, the UI and the CLI all fire them.

For domain creation that is the top of `DomainCreationService::create()`,
before the database record exists. The call returns a decision:

- **reject** aborts. Return a failed `ServiceResponseData` carrying the
  plugin's message, so the user sees why.
- **mutations** are validated and merged into `$data` before creation
  proceeds. Only the keys in the mutable map, re-validated with the rules that
  apply to operator input.
- **timeout or error** applies the plugin's failure policy: `open` proceeds,
  `closed` aborts.

Run every subscribed plugin, in a defined order, and stop at the first
rejection. The whole set shares one budget so that ten plugins with a five
second timeout cannot add up to fifty seconds on a user's request.

## Signing

```
X-Bolt-Signature: v1=hex(hmac_sha256(hook_secret, "v1:" + timestamp + ":" + raw_body))
```

Sign the exact bytes sent. During rotation send both, comma separated.

Include `X-Bolt-Delivery` and keep it stable across retries: plugins key their
idempotency on it.

## Retrying notification hooks

Exponential backoff, a handful of attempts, then park the delivery in the log.
Disable a plugin automatically only after sustained failure, and say so
loudly: a silently disabled billing plugin is worse than a failing one.

## Rendering plugin pages

Each `ui` entry in a manifest becomes a Filament page registered on the panel
it names, in the navigation group it names. The `panel` field is the
authorisation boundary and the panel enforces it: a page declared for admin is
not routable from the client area, so plugins do not have to check.

For a declarative page the panel POSTs the signed viewer envelope to
`/ui/{slug}` on the plugin listener, gets a page description back, and renders
it with native components:

| Description | Filament |
| --- | --- |
| `stat` | a stat card in the header row |
| `table` | a table, with the row actions as row action buttons |
| `form` | a form schema, submitting to the named action |
| `section` | a section card |
| `alert` | a callout |
| `text` | prose, Markdown through a restricted renderer |

Three rules the renderer has to hold to, because they are what make this safe:

Escape everything. A plugin sends data, never markup, so every string is
escaped on output and the Markdown renderer runs with raw HTML disabled. A
plugin that manages to put a script tag in a table cell is a panel bug, not a
plugin feature.

Honour only the semantic colour names. Anything else is dropped rather than
passed through to a class or a style attribute.

Invoke only actions that were on the page you rendered. Keep the set of action
names from the render and check the incoming action against it, so a crafted
request cannot reach an action the viewer was never offered.

Buttons POST to `/ui/{slug}/{action}` with the submitted values and the
action's arguments. Apply the result: toast, re-render, redirect or replace.

For an iframe page the panel reverse-proxies `path` on the plugin listener and
embeds it, with the signed viewer context as a request header. Serve it with a
frame-ancestors policy naming the panel only, and never expose the plugin
listener publicly: the proxy is what keeps it reachable only through the panel,
where the session check already happened.

## The viewer envelope

The same HMAC scheme as a hook delivery, over the same signed string. It
carries the panel, the viewer, the hosting account in scope, the locale, and
for an action the submitted input and the action arguments.

Sign it. This envelope is what a plugin scopes its data by, so an unsigned or
forgeable one is a way to read another account's data through a plugin.

## Isolation

Each plugin runs as its own unprivileged system user, never root, and never in
the panel's process. It cannot read another plugin's `runtime.json`. Anything
it does to the server, it does through the panel's API, which is where the
permission checks and the audit trail already are.

This is the same boundary the panel already draws for the web tier: the web
process is agent-only and does not execute privileged work locally. A plugin
sits outside that boundary, not inside it.

## What moves out of the panel

The integrations already carried in the panel are the natural first plugins.
Each is a listener bridging a panel event to a third-party service, which is
exactly the shape of a notification plugin:

| Listener | Becomes |
| --- | --- |
| `CloudflareHookListener` | `plugin-cloudflare` |
| `SpamExpertsHookListener` | `plugin-spamexperts` |
| `MailChannelsHookListener` | `plugin-mailchannels` |
| `SyncCpguardOn*` | `plugin-cpguard` |
| `CloudLinuxHookListener` | `plugin-cloudlinux` |

Move them one at a time, each behind a setting that selects the built-in or
the plugin, and delete the built-in once the plugin has shipped a release.
