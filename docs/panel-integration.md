# What the panel implements

This is the panel-side half of the contract, as bolt-panel implements it.
Plugin authors do not need it; it is here so the two halves can be checked
against each other.

## The pieces

1. **A registry.** The plugin directory on disk is the list of plugins (one
   `plugin.json` per directory, no registry file). `plugin_installations`
   records what an administrator approved: scopes, commands and hooks, the
   version they saw, the API key, and the operator's settings (encrypted).
2. **An installer.** From the marketplace or the plugin directory: validate
   the manifest, mint the API key, write `runtime.json`, serve the listener
   with the panel's own nginx and a per-plugin PHP-FPM pool, record the
   approval, deliver `plugin.installed`.
3. **A dispatcher.** `App\Plugins\Hooks\PluginHookDispatcher` turns panel
   events into signed deliveries, and `App\Listeners\PluginHookBridge` maps
   the panel's events onto notification hooks.
4. **A UI.** The Plugins page installs, approves, configures, updates,
   deactivates and uninstalls, and shows each plugin's delivery log.

| Piece | Where |
| --- | --- |
| Hook catalogue (kept in step with `Hook`) | `app/Plugins/Hooks/PluginHook.php` |
| Blocking and notification dispatch | `app/Plugins/Hooks/PluginHookDispatcher.php` |
| Queued delivery with retries | `app/Jobs/DeliverPluginHook.php` |
| Delivery log | `plugin_hook_deliveries`, `App\Models\PluginHookDelivery` |
| Signed transport | `app/Plugins/PluginClient.php` |
| Key minting and scope mapping | `app/Plugins/PluginKeyMinter.php` |
| Settings | `PluginProvisioner::writeSettings()`, the Configure action on the Plugins page |

## Minting the key

The panel creates an `ApiKey` row per install, with `allow_all_endpoints`
false and `allowed_endpoints` derived from the manifest scopes. The existing
`ApiKey::canAccessEndpoint()` already enforces the `uri|METHOD` list, so
scoping needs a scope-to-endpoint mapping and nothing new in the middleware.

Every plugin key is marked with the plugin's id and carries only the endpoints
its approved scopes map to. A client-API call must also carry the account grant
the panel put in the envelope (`X-Plugin-Account-Grant`), so a plugin acts on
the account the panel named and cannot choose one. An `admin:*` scope maps onto
the admin API where it actually lives: `/api/hosting-accounts`,
`/api/hosting-account/<resource>` and `/api/admin/<resource>`, and only routes
behind the admin key middleware.

Rotate the key and the hook secret on demand and on every upgrade. Rewrite
`runtime.json` afterwards, and sign with both secrets during the overlap so
in-flight deliveries do not fail.

## Dispatching notification hooks

Each is a panel event. The bridge, `PluginHookBridge`, is one subscriber that maps
an event to a delivery:

| Hook | Panel event |
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
| `account.suspended` | `HostingAccountSuspended` |
| `account.unsuspended` | `HostingAccountUnsuspended` |
| `email_account.created` | `EmailAccountCreated` |
| `email_account.deleted` | `EmailAccountDeleted` |
| `database.created` | `DatabaseCreated` |
| `database.deleted` | `DatabaseDeleted` |
| `certificate.issued` | `CertificateIssued` |

Delivery is queued, never inline. These events already run inside completed
operations that must not fail, and an HTTP call to a plugin is exactly the
kind of thing that would break them.

Deliveries are dispatched after the surrounding transaction commits, so a
plugin is never told about an operation that rolled back.

## Dispatching blocking hooks

These are new dispatch points, and they go in the service, not the controller,
so that the API, the UI and the CLI all fire them.

They are asked from:

| Hook | Service |
| --- | --- |
| `domain.creating` | `DomainCreationService`, per domain type, after the panel's own checks and before the row exists |
| `domain.deleting` | `DomainDeletionService::delete()`, before any teardown, and not when the whole account is going |
| `account.creating` | `HostingAccountCreationService::create()`, before the reseller limits, so a plan a plugin chose is held to them |
| `account.deleting` | `HostingAccountDeletionService::delete()`, before `HostingAccountDeletionStarted` |
| `dns_record.creating` | `DNSRecordService::create()` with `askPlugins: true`, which only the person-facing callers pass |
| `email_account.creating` | `EmailAccountService::create()`, before the plan's quota cap is checked |
| `database.creating` | `DatabaseService::create()`, once the name is final |

The call returns a decision:

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

## Provisioning

`provision` scripts run as root through the agent's command endpoint, from a
queued job (`App\Jobs\RunPluginProvision`) for install and update, and inline
for uninstall, before the files are deleted. `PluginProvisionRunner` checks the
step is still approved as it is on disk, fills `{setting}` arguments from the
saved settings over the declared defaults, refuses any value outside the safe
character set, and hands the agent one command that `cd`s into the plugin,
checks the file's SHA-256 with `sha256sum --check`, and only then runs it,
under `timeout`. Output goes to `var/logs/provision-<run>.log` in the plugin,
which the panel reads while the script runs and keeps on the
`plugin_provision_runs` row afterwards.

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

## Rendering slots

A slot is the same call as a page render, to the same path, and the panel
takes only the components out of what comes back. What differs is everything
around the call, because a slot is rendered while somebody is waiting for a
page that has nothing to do with the plugin:

- a short timeout, three seconds by default;
- the answer cached for the `cache` the manifest asks for, keyed on the plugin
  version and the viewer, so a slot is one round trip a minute rather than one
  per request;
- one failure stops the panel calling that slot for a minute;
- anything with a button in it is dropped before rendering, because there is
  no Livewire component behind a render hook to receive the click;
- nothing thrown while rendering a slot escapes into the page around it.

The positions are the panel's own names, and the panel holds the map from them
to its view layer's render hooks. That map is the only thing that moves if the
view layer is ever replaced: a plugin that declared "footer" keeps drawing in
the footer. One hook is registered per position installed plugins asked for,
and a plugin that is not approved registers nothing.

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
