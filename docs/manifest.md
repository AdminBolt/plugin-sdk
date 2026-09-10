# plugin.json

The one file the panel reads before it trusts a plugin. Validate it with
`bolt-plugin validate`, or point your editor at
[`schema/plugin.schema.json`](../schema/plugin.schema.json) for completion and
inline errors.

## Identity

| Field | Required | Notes |
| --- | --- | --- |
| `id` | yes | Lower-case kebab-case. Becomes the directory name, the API key label and the hook route, so it cannot contain spaces, dots or slashes. |
| `name` | yes | Shown to the operator. |
| `version` | yes | Semantic version. The panel compares it to decide whether an update is available. |
| `description` | no | One line, shown on the plugin card. |
| `author` | no | `name`, `email`, `url`. |
| `license` | no | SPDX identifier. |
| `homepage` | no | Where to file a bug. |
| `icon` | no | Heroicon name, for example `heroicon-o-globe-alt`. |

## Compatibility

| Field | Notes |
| --- | --- |
| `sdk` | Composer constraint on `adminbolt/plugin-sdk`, for example `^1.0`. |
| `panel` | Minimum panel version, for example `>=1.9.0`. The panel refuses to install a plugin that asks for more than it is. |

Set `panel` whenever the plugin subscribes to a hook that was added in a
particular release. Without it the install succeeds and the hook never fires,
which is a much worse failure than a refused install.

## `runtime`

| Field | Required | Notes |
| --- | --- | --- |
| `entrypoint` | yes | The PHP file the panel serves or executes, relative to the plugin root. Cannot contain `..`. |
| `transport` | no | `http` (default) or `cli`. See [plugin-structure.md](plugin-structure.md). |
| `php` | no | Constraint such as `^8.2`, checked at install. |
| `listen` | no | Unix socket path or `127.0.0.1:port`. The panel assigns one if omitted; let it. |
| `user` | no | System user to run as. The panel creates a dedicated unprivileged user by default and refuses root. |
| `memory_limit` | no | For example `128M`. |

## `api.scopes`

What the plugin's API key may reach, as `<api>:<resource>:<read|write|execute>`:

```json
"api": {
    "scopes": [
        "client:domains:read",
        "client:dns-records:write",
        "admin:hosting-accounts:read"
    ]
}
```

The panel mints the key with exactly these endpoints and nothing else. These
are shown to the administrator approving the install, so ask for the minimum.

`execute` is its own access level, used by `client:cli:execute`, because
running programs in somebody's account is not a write and an approval screen
that called it one would have misinformed the only person it exists for.

## `hooks`

```json
"hooks": [
    "domain.created",
    { "event": "domain.creating", "blocking": true, "timeout": 5, "on_failure": "open" }
]
```

| Field | Default | Notes |
| --- | --- | --- |
| `event` | required | A name from the [catalogue](hooks.md). An unknown one fails validation. |
| `blocking` | `false` | Only the seven hooks that run inside an operation may block. |
| `timeout` | `5` | Seconds, 1 to 30. A blocking hook holds up a user-facing operation, so the panel caps it. |
| `on_failure` | `open` | `open` proceeds when the plugin fails, `closed` aborts the operation. |

The panel only delivers hooks the manifest declares. A handler for anything
else never runs, and the SDK logs a warning at startup saying so.

## `settings`

Rendered by the panel as a form, stored encrypted, handed back through
`runtime.json`.

```json
"settings": [
    { "key": "api_token", "type": "secret", "label": "API token", "required": true },
    { "key": "mode", "type": "select", "label": "Mode", "default": "proxy",
      "options": { "proxy": "Proxied", "dns_only": "DNS only" } }
]
```

Types: `string`, `secret`, `bool`, `int`, `select`, `text`, `url`.

A `secret` is write-only in the panel UI and must not declare a `default`.

## `commands`

The programs a plugin may run in an account. An administrator approves these
by name and by argument, and the panel builds every command line from its own
copy of them.

```json
"commands": [
    {
        "name": "artisan",
        "label": "Run an Artisan command",
        "program": "php",
        "args": ["artisan", "{command}", "--no-interaction"],
        "params": {
            "command": { "type": "enum", "values": ["migrate:status", "optimize"] }
        },
        "cwd": "required",
        "timeout": 120
    }
]
```

| Field | Default | Notes |
| --- | --- | --- |
| `name` | required | Lower-case kebab-case. What the plugin passes to `cli()->run()`. |
| `label` | the name | What the administrator reads on the approval screen. |
| `program` | | A program name such as `php` or `composer`, never a path. Shorthand for a one-entry `steps`. |
| `args` | `[]` | The argument template. `{name}` placeholders must be declared in `params`. |
| `steps` | | Several programs run in order, stopping at the first non-zero exit. Up to twelve. |
| `params` | `{}` | Typed values the plugin supplies at runtime. |
| `cwd` | `required` | `required`, `optional` or `none`: whether the caller names a directory inside the account home. |
| `timeout` | `60` | Seconds, up to 1800. |
| `async` | `false` | Run it as a job the plugin polls, for anything that outlives a request. |

Parameter types are `enum`, `path`, `token`, `pattern` and `int`. There is no
free string type, and no value may begin with a hyphen.

Declaring commands without `client:cli:execute` fails validation: none of them
could run, and the scope is what the administrator actually approves.

Full detail, including what the panel checks before it spawns anything, is in
[commands.md](commands.md).

## `ui`

Pages the panel renders for the plugin, served by the plugin itself and
embedded in the panel's navigation.

```json
"ui": [
    { "panel": "client", "slug": "backups", "title": "Backups",
      "icon": "heroicon-o-archive-box" }
]
```

| Field | Default | Notes |
| --- | --- | --- |
| `panel` | required | `admin`, `client` or `reseller`. The panel enforces it: a page declared for admin is not routable from the client area. |
| `slug` | required | Lower-case kebab-case. Becomes part of the panel URL. |
| `title` | required | The navigation entry. |
| `icon` | no | A Heroicon name. |
| `group` | no | The navigation group to sit under. |
| `render` | `declarative` | `declarative` means the plugin describes the page and the panel draws it. `iframe` means the plugin serves a built front end, which the panel embeds. |
| `sort` | no | Order within the group. |

The panel passes the signed identity of the viewer, so the page knows which
account it is being viewed for without trusting a query parameter.

## A complete example

```json
{
    "$schema": "https://raw.githubusercontent.com/AdminBolt/plugin-sdk/main/schema/plugin.schema.json",
    "id": "cloudflare-dns",
    "name": "Cloudflare DNS",
    "version": "1.0.0",
    "description": "Mirrors panel DNS zones into Cloudflare.",
    "author": { "name": "AdminBolt", "url": "https://adminbolt.com" },
    "license": "MIT",
    "sdk": "^1.0",
    "panel": ">=1.9.0",
    "runtime": { "php": "^8.2", "entrypoint": "public/index.php", "transport": "http" },
    "api": { "scopes": ["client:dns-records:read", "client:domains:read"] },
    "hooks": [
        "domain.created",
        "dns_record.created",
        "dns_record.updated",
        "dns_record.deleted",
        "plugin.configured"
    ],
    "settings": [
        { "key": "api_token", "type": "secret", "label": "Cloudflare API token", "required": true },
        { "key": "proxied", "type": "bool", "label": "Proxy records through Cloudflare", "default": false }
    ],
    "ui": [
        { "panel": "admin", "slug": "cloudflare", "title": "Cloudflare", "path": "/ui/admin" }
    ]
}
```
