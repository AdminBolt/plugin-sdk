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
| `domain.created` | no | the created domain, with `id` |
| `domain.deleting` | yes | the domain about to be removed |
| `domain.deleted` | no | `id`, `domain` |
| `domain.renamed` | no | `id`, `from`, `to` |

### Hosting accounts

| Hook | Blockable | Payload |
| --- | --- | --- |
| `account.creating` | yes | `username`, `domain`, `hosting_plan_id`, `email` |
| `account.created` | no | the created account |
| `account.deleting` | yes | the account about to be removed |
| `account.deleted` | no | `id`, `username` |
| `account.suspended` | no | `id`, `username`, `reason` |
| `account.unsuspended` | no | `id`, `username` |
| `account.transferred` | no | `id`, `from_owner`, `to_owner` |

### DNS

| Hook | Blockable | Payload |
| --- | --- | --- |
| `dns_record.creating` | yes | `domain_id`, `type`, `name`, `content`, `ttl` |
| `dns_record.created` | no | the created record |
| `dns_record.updated` | no | the record, with `previous` |
| `dns_record.deleted` | no | the removed record |

SOA and NS records are managed by the panel and do not fire these hooks.

### Email, databases, TLS, server

| Hook | Blockable | Payload |
| --- | --- | --- |
| `email_account.creating` | yes | `domain_id`, `email`, `quota_mb` |
| `email_account.created` | no | the created mailbox |
| `email_account.deleted` | no | `id`, `email` |
| `database.creating` | yes | `name`, `engine` |
| `database.created` | no | the created database |
| `database.deleted` | no | `id`, `name` |
| `certificate.issued` | no | `domain_id`, `domain`, `issuer`, `expires_at` |
| `webserver.switched` | no | `from`, `to` |

No hook payload ever carries a password, a private key or an API secret. A
plugin that needs the certificate body reads it back through the API, where
the request is scoped and audited.

### The plugin's own lifecycle

| Hook | When |
| --- | --- |
| `plugin.installed` | after install, before the first operational delivery |
| `plugin.configured` | an operator changed a setting |
| `plugin.uninstalled` | before the panel removes the files; last chance to clean up externally |

`plugin.installed` is the right place to provision whatever the plugin needs,
because it runs once and the API key already works.
