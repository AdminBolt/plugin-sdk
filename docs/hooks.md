# Hooks

## Naming

Every hook is `<resource>.<verb>`, and the tense of the verb tells you the
phase.

| | |
| --- | --- |
| `domain.creating` | still happening, and you can stop it |
| `domain.created` | already happened, and you cannot |

Which hooks may block is a fixed list, not something worked out from the name.
The tense makes the distinction readable; the list makes it authoritative. Run
`bolt-plugin hooks` for both.

## The two families

**Blocking hooks** run inside the operation, synchronously, while the panel
still holds the request. The plugin can veto the operation or adjust an
allow-listed input. It is on the user's critical path, so the panel caps the
timeout and applies a failure policy if the plugin is slow or down. There are
seven, all present participles.

**Notification hooks** run once the operation has already succeeded. Delivery
is queued and retried, and the answer is recorded but changes nothing.
Rejecting is meaningless, because the domain already exists. Use these for
syncing, billing and audit.

The mistake this naming invites is subscribing to `domain.created` when you
meant `domain.creating`. That validates cleanly and gives you a plugin that
silently cannot refuse anything, so `bolt-plugin validate` warns when you
subscribe to a completed hook whose blocking twin you have not taken.

A hook name is a public API. Names are added and never repurposed, because
renaming one silently breaks every installed plugin.

## The delivery

The panel POSTs the envelope, or pipes it to stdin for a `cli` plugin.

```http
POST / HTTP/1.1
Content-Type: application/json
X-Bolt-Hook: domain.creating
X-Bolt-Delivery: dlv_01J9Z0C4YQ
X-Bolt-Timestamp: 1757500000
X-Bolt-Signature: v1=6f1c...
X-Bolt-Plugin: domain-policy
X-Bolt-Contract: 1
```

```json
{
    "hook": "domain.creating",
    "delivery_id": "dlv_01J9Z0C4YQ",
    "blocking": true,
    "attempt": 1,
    "occurred_at": "2026-09-10T10:00:00+00:00",
    "panel": { "version": "1.9.0", "url": "https://panel.example:2087" },
    "actor": { "type": "client", "id": 7, "username": "acme" },
    "context": {
        "hosting_account": { "id": 7, "username": "acme" }
    },
    "payload": {
        "domain": "example.com",
        "domain_type": "addon",
        "php_version": "8.2",
        "document_root": "public_html/example.com"
    }
}
```

`actor.type` is `admin`, `client`, `reseller` or `system`. A hook fired by a
scheduled task or by another plugin's API call has actor type `system`.

## The signature

Every delivery is authenticated with an HMAC over the exact bytes sent:

```
signature = "v1=" + hex(hmac_sha256(hook_secret, "v1:" + timestamp + ":" + raw_body))
```

The timestamp is inside the signed string, so a captured delivery cannot be
replayed with a fresh one. The default tolerance is 300 seconds of clock drift.

Verify against the **raw body**. Decoding and re-encoding the JSON changes key
order and whitespace, and the signature will never match. The SDK does this
correctly; if you write your own listener in another language, this is the one
thing to get right.

During a secret rotation the panel sends both signatures, comma separated, so
the plugin keeps working while the new secret propagates.

## The answer

HTTP status answers "did the plugin run". The body's `status` answers "what
did it decide".

```json
{ "status": "ok" }
```

```json
{ "status": "reject", "message": "example.test is not permitted on this server." }
```

```json
{ "status": "ok", "mutations": { "php_version": "8.3" } }
```

```json
{ "status": "error", "message": "Cloudflare API unreachable." }
```

A rejection is HTTP **200**, never a 4xx. A 4xx is indistinguishable from a
broken listener, and the panel would apply its delivery failure policy instead
of honouring the veto.

The rejection message is shown to whoever triggered the operation, so write it
for them. "example.test is not permitted on this server" beats "policy check
failed".

## Mutations

A blocking hook can change specific inputs. Only these keys, and only for
these hooks:

| Hook | Mutable keys |
| --- | --- |
| `domain.creating` | `php_version`, `document_root` |
| `email_account.creating` | `quota_mb` |
| `account.creating` | `hosting_plan_id` |

Anything else is refused and logged against the plugin. Accepted mutations are
re-validated with the same rules that apply to operator input, so a plugin
cannot use one to reach a state the panel would not have accepted from a
person.

## When a plugin fails

For a notification hook the panel retries with backoff and gives up after the
configured attempts, leaving the failure on the delivery log.

For a blocking hook the panel applies the plugin's failure policy, set per
hook in the manifest as `on_failure`:

- **`open`** (default) proceeds with the operation. A broken plugin does not
  stop customers creating domains.
- **`closed`** aborts it. Correct for a plugin that enforces a policy the
  operator cannot afford to have bypassed, such as a compliance check.

Choosing `closed` means the plugin being down stops the operation. That is the
point of it, and it is why `open` is the default.

Every plugin consulted about one operation shares one time budget, ten seconds
by default, whatever each asked for. Plugins are asked in id order; when the
ones before have used the budget up, the next is treated as failed and its
policy decides.

A blockable hook declared without `"blocking": true` is not asked at all. The
plugin is told the operation is under way, through the queue like any
notification, and nothing it answers changes anything.

## Approval

Hooks are approved like API scopes. The approval screen lists every hook a
plugin subscribes to, marks the ones that can refuse an operation, and says
which of those stop it when the plugin is unreachable. An update that adds a
subscription, makes one blocking or switches one to `on_failure: closed` keeps
the plugin off until an administrator approves it again.

## The delivery log

Every delivery is recorded, blocking or not, with the answer and how long it
took. An operator reads it from the plugin's card on the Plugins page, and a
notification that failed every retry is parked there with a button to send it
again. A redelivery keeps its `delivery_id`.

## Idempotency

A delivery can arrive more than once: the panel retried after a timeout that
the plugin had in fact already handled. `delivery_id` is stable across retries
and `attempt` counts them. A handler with side effects should key on the
delivery id rather than trusting that it runs once.

## The catalogue

Hooks marked blockable can be declared `"blocking": true` and can veto.

### Domains

| Hook | Blockable | Payload |
| --- | --- | --- |
| `domain.creating` | yes | `domain`, `domain_type`, `php_version`, `document_root` |
| `domain.created` | no | `id`, `domain`, `domain_type`, `php_version`, `document_root`, `parent_domain_id`, `hosting_account_id`, `status` |
| `domain.deleting` | yes | the domain about to be removed, same fields as `domain.created` |
| `domain.deleted` | no | `id`, `domain` |
| `domain.renamed` | no | `id`, `from`, `to` |

`domain_type` is `domain`, `subdomain` or `parked`. `document_root` is relative
to the account's home directory. `domain.creating` fires for all three types;
a parked domain is served from the domain it is parked on, so for one of those
a plugin can refuse but its mutations are ignored. `domain.deleting` is not
asked when a whole account is being removed: that was `account.deleting`, and
a veto halfway through tearing an account down would leave it half removed.

### Hosting accounts

| Hook | Blockable | Payload |
| --- | --- | --- |
| `account.creating` | yes | `username`, `domain`, `hosting_plan_id`, `reseller_id`, `email` |
| `account.created` | no | `id`, `username`, `domain`, `hosting_plan_id`, `reseller_id`, `email`, `is_suspended` |
| `account.deleting` | yes | the account about to be removed, same fields as `account.created` |
| `account.deleted` | no | `id`, `username` |
| `account.suspended` | no | `id`, `username`, `reason` |
| `account.unsuspended` | no | `id`, `username` |
| `account.transferred` | no | `id`, `from_owner`, `to_owner` |

`email` on `account.creating` is `null` today: the account's contact details
are filled in after it exists. `reason` on `account.suspended` is `null` until
the panel records one.

### DNS

| Hook | Blockable | Payload |
| --- | --- | --- |
| `dns_record.creating` | yes | `domain_id`, `type`, `name`, `content`, `ttl` |
| `dns_record.created` | no | `id`, `domain_id`, `type`, `name`, `content`, `ttl`, `priority` |
| `dns_record.updated` | no | the record, with `previous` holding the fields that changed |
| `dns_record.deleted` | no | the removed record |

SOA and NS records are managed by the panel and do not fire these hooks.

`dns_record.creating` is asked about a record a person adds: from the zone
editor, the admin DNS page or either REST API. Records the panel writes itself
(a new domain's zone from its template, mail records, upgrade backfills) are
not put to plugins, because a veto there would leave a domain half
provisioned. They still fire `dns_record.created`.

### Email, databases, TLS, server

| Hook | Blockable | Payload |
| --- | --- | --- |
| `email_account.creating` | yes | `domain_id`, `email`, `quota_mb` |
| `email_account.created` | no | `id`, `domain_id`, `email`, `quota_mb` |
| `email_account.deleted` | no | `id`, `email` |
| `database.creating` | yes | `name`, `engine` |
| `database.created` | no | `id`, `name`, `engine` |
| `database.deleted` | no | `id`, `name` |
| `certificate.issued` | no | `domain_id`, `domain`, `issuer`, `expires_at` |
| `webserver.switched` | no | `from`, `to` |

`database.creating` and `database.created` carry the full name, with the
account's prefix, as it exists on the server. `certificate.issued` fires for a
certificate the panel ordered (`issuer` is the provider) and for one somebody
uploaded (`issuer` is `uploaded`). `from` on `webserver.switched` is `null`
until the panel records it.

No hook payload ever carries a password, a private key or an API secret. A
plugin that needs the certificate body reads it back through the API, where
the request is scoped and audited.

### The plugin's own lifecycle

| Hook | When | Payload |
| --- | --- | --- |
| `plugin.installed` | after install, before the first operational delivery | `version` |
| `plugin.configured` | an operator changed a setting | `changed`: the keys whose value changed |
| `plugin.uninstalled` | before the panel removes the files; last chance to clean up externally | `version` |

Only the plugin itself is told about its own lifecycle, and only when it
subscribed. `plugin.configured` names the settings, never their values: the
values are already in `runtime.json`, and a secret has no business travelling
twice. `plugin.uninstalled` is delivered synchronously, because once the files
are gone there is nobody to deliver it to; it gets ten seconds, and the
uninstall goes ahead whatever the answer.

`plugin.installed` is the right place to provision whatever the plugin needs,
because it runs once and the API key already works.
