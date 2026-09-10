# Running commands

A plugin can run programs in a hosting account: `php artisan migrate`,
`composer install`, `git pull`. It cannot run *a* command. It declares the
commands it needs, an administrator approves them by name and by argument,
and the panel builds every command line from its own copy of what was
approved.

That distinction is the whole design, so it is worth being blunt about why.

A gateway that took a program and an argument list would be a shell. A plugin
bug, or a stolen plugin key, would then be code execution in every account
that ever opened the plugin's page. Declared commands make the blast radius
"the things on the approval screen, in that account's own directory", which
is something an operator can reason about and refuse.

## Declaring one

```json
"api": { "scopes": ["client:cli:execute"] },

"commands": [
    {
        "name": "artisan",
        "label": "Run an Artisan command",
        "program": "php",
        "args": ["artisan", "{command}", "--no-interaction"],
        "params": {
            "command": {
                "type": "enum",
                "values": ["migrate:status", "cache:clear", "optimize", "storage:link"]
            }
        },
        "cwd": "required",
        "timeout": 120
    }
]
```

```php
$cli = $plugin->clientFor($request)->cli();

$result = $cli->run('artisan', ['command' => 'cache:clear'], cwd: 'shop');

$result->ok();        // exit code 0
$result->exitCode;    // 1, when the command failed
$result->output();    // both streams, in the order a person reads them
```

`program` is a name, never a path. The panel resolves it, and it resolves
`php` to the version that account is on, with the CLI overrides that let
composer and artisan work under a hosting PHP configuration. An account on
8.1 and an account on 8.3 run the same declared command correctly, and the
plugin never knows.

## Parameters

The values a plugin supplies at runtime are the only part of an invocation it
controls, so they are typed and there is no free string type.

| Type | Accepts |
| --- | --- |
| `enum` | one of a fixed list in the manifest |
| `path` | a relative path, resolved inside the account home |
| `token` | `[A-Za-z0-9._/-]`, up to 128 characters |
| `pattern` | a regex from the manifest, anchored and capped by the panel |
| `int` | a whole number, optionally between `min` and `max` |

One rule applies to every type: **a value may not begin with a hyphen.** A
value that starts a new flag is how a command limited to one program stops
being limited to one behaviour, and `--define`, `--require` and `--path` all
take an argument.

A placeholder may be a whole argument or sit inside one:

```json
"args": ["migrate", "--path={path}"]
```

Either way it stays inside a single argument, because nothing here goes
through a shell.

## Several steps, one command

A deploy is four programs and should be one approval, one invocation and one
collected output:

```json
{
    "name": "deploy",
    "label": "Deploy: pull, install, migrate, optimise",
    "steps": [
        { "program": "git",      "args": ["pull", "--ff-only"] },
        { "program": "composer", "args": ["install", "--no-dev", "--optimize-autoloader"] },
        { "program": "php",      "args": ["artisan", "migrate", "--force"] },
        { "program": "php",      "args": ["artisan", "optimize"] }
    ],
    "cwd": "required",
    "timeout": 1800,
    "async": true
}
```

The steps run in order and stop at the first non-zero exit: a deploy whose
install failed must not go on to migrate. The panel sequences them itself, so
there is still no shell and still nothing concatenated.

## Commands that outlive a request

`composer install` on a cold cache takes minutes. Mark it `async` and start
it instead:

```php
$job = $cli->start('deploy', cwd: 'shop');

// ...on a later render of a polling page
$job = $cli->job($id);
$job->isRunning();
$job->progress();    // "2/4"
$job->output;        // grows while it runs
```

One at a time per account: the panel refuses a second while the first is in
flight, because two composer installs in one vendor directory corrupt it.

After a reload the id is gone with the request that held it, so ask what is
in flight:

```php
$job = $cli->runningJob();
```

Pair it with a polling page and an `Output` component, which is what makes a
deploy watchable:

```php
Page::make('Deploy')->poll(5)->add(
    Output::make($job->output)->follow()->status($job->failed() ? 'danger' : null),
);
```

## What the panel checks

In this order, before anything is spawned:

1. The key belongs to an installed, active plugin. A customer's own API key
   has no catalogue and reaches nothing here.
2. The command is in that plugin's **approved** catalogue, read from the
   panel's record rather than from the manifest on disk.
3. Every parameter matches its declared type, is under its cap, and does not
   begin with a hyphen.
4. The program resolves through the panel's own list.
5. `cwd` resolves inside the account's home directory, checked again by the
   agent at the moment it spawns.
6. The invocation is written to the audit log, with the argv the panel built.

Then it runs as the account's own user, with a timeout that kills the whole
process group, and the output is capped.

## Two things worth knowing

**Changing a command needs approving again.** A command that keeps its name
while gaining an argument is a different command; the plugin falls inactive
until an administrator has read the change. Ship command changes in a release
and say so in the changelog.

**`php artisan` runs the customer's own code.** Every artisan invocation
executes whatever is in that application, so the catalogue protects the panel
and the other accounts on the server, not the account from itself. What it
does give you is a small, readable list of the ways your plugin can touch an
account, which is what an operator is agreeing to.
