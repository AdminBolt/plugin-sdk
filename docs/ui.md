# Plugin pages

A plugin can put its own pages in the panel, in the admin, client or reseller
area. There are two ways, and the first is almost always the right one.

**Declarative.** The plugin returns a description of the page and the panel
draws it with its own components. The page looks like the rest of the panel,
follows the operator's theme, works on a phone, and is translated and escaped
by the panel. The plugin never emits HTML.

**Iframe.** The plugin serves its own HTML and the panel embeds it. Reach for
this only when you need something the components cannot express, such as a
terminal, a canvas or an existing single-page app.

Both are declared the same way and both are proxied by the panel, so a plugin
never needs a public port either way.

There is a third thing a plugin can draw, which is not a page at all: a
[slot](slots.md), something in the panel's own footer, sidebar or top bar that
a customer meets without going anywhere.

## A page

Declare it in `plugin.json`, which is what puts it in the navigation:

```json
"ui": [
    {
        "panel": "client",
        "slug": "zones",
        "title": "Cloudflare",
        "icon": "heroicon-o-globe-alt",
        "group": "Domains"
    }
]
```

Then register what is on it:

```php
use AdminBolt\Plugin\Ui\{Page, Stat, Table, Column, Action, UiRequest};

$plugin->page('zones', function (UiRequest $request) use ($plugin) {
    $zones = $plugin->clientFor($request)->domains()->all();

    return Page::make('Cloudflare')
        ->subheading('Zones mirrored for this account')
        ->stats(
            Stat::make('Zones', count($zones))->icon('heroicon-o-globe-alt'),
            Stat::make('Pending', 1)->color('warning'),
        )
        ->headerActions(Action::make('sync', 'Sync now')->primary())
        ->add(
            Table::make()
                ->columns(
                    Column::make('domain', 'Domain'),
                    Column::make('status', 'Status')->badge(['live' => 'success', 'pending' => 'warning']),
                    Column::make('synced_at', 'Last sync')->dateTime(),
                )
                ->rows($rows)
                ->keyedBy('domain')
                ->rowActions(Action::make('purge', 'Purge cache'))
                ->emptyState('No zones are mirrored yet.'),
        );
});
```

## Actions

A button calls back into the plugin. Register the handler by name:

```php
use AdminBolt\Plugin\Ui\UiResponse;

$plugin->action('purge', function (UiRequest $request) use ($plugin) {
    $domain = (string) $request->argument('key');

    // The row key came back through the browser, so check the viewer may act
    // on it rather than trusting that they were shown it.
    if (!$this->accountOwns($request, $domain)) {
        return UiResponse::error('That domain is not on this account.');
    }

    $this->purge($domain);

    return UiResponse::notify(sprintf('Cache purged for %s.', $domain));
});
```

The panel only ever invokes an action the plugin registered. An action name
that is not registered is refused before any plugin code runs, so an action is
not callable just because someone guessed its name.

Responses:

| | |
| --- | --- |
| `UiResponse::notify($message)` | toast, then re-render |
| `UiResponse::error($message)` | the same in the panel's danger colour |
| `UiResponse::refresh()` | re-render, say nothing |
| `UiResponse::redirect($path)` | send the viewer elsewhere **in the panel**: a path starting with a single slash, never another site |
| `UiResponse::replace($page)` | swap in a different page |
| `UiResponse::invalid([...])` | reject a form, errors under the fields |

## Forms

```php
use AdminBolt\Plugin\Ui\{Form, Field, Section};

Section::make('Credentials')->add(
    Form::make('save')
        ->fields(
            Field::secret('api_token', 'API token')->placeholder('Set, leave blank to keep'),
            Field::select('mode', 'Mode', ['proxy' => 'Proxied', 'dns_only' => 'DNS only'])->value('proxy'),
            Field::toggle('auto_sync', 'Sync automatically')->value(true),
        )
        ->submitLabel('Save credentials'),
);
```

A `secret` field is write-only. Whatever value the plugin sets on it is
dropped before the page is serialised, so a stored credential is never served
back to a browser. Show that one is set with the placeholder.

To send somebody to the service the plugin integrates with, write a link into
a `Text::markdown()` component rather than redirecting. The panel follows only
its own paths, so an external redirect is dropped and logged; and a link shows
the viewer where it goes before they take it.

`required` on a field is a courtesy to the person filling the form in. Validate
on arrival: the values reach the plugin over HTTP and nothing stops a crafted
submission omitting one.

## Who is looking

Read the viewer from the request and nowhere else:

```php
$request->hostingAccountUsername();  // the account in scope, on the client panel
$request->hostingAccountId();
$request->viewerId();
$request->isAdmin();
$request->locale;
```

The panel signs this envelope the same way it signs a hook delivery, so it is
the panel's word for who is looking rather than the browser's. This matters
more here than it does for hooks, because it is what a plugin scopes its data
by.

Never take an account id from an action argument, a form field or a query
parameter. Those travel through the browser and whoever is looking at the page
can change them. `$plugin->clientFor($request)` scopes an API call to the
account the page is being viewed for, which is the safe default.

Who may open a page at all is the `panel` field in the manifest, and the panel
enforces it. A page declared for the admin panel cannot be opened by a
customer, so a plugin does not have to check.

## Components

| Component | For |
| --- | --- |
| `Stat` | a number in the card row across the top |
| `Table` | rows, with typed columns and row actions |
| `Form` | inputs and a submit button |
| `Section` | a titled card grouping other components |
| `Alert` | a coloured callout, optionally with a button |
| `Text` | prose, plain or restricted Markdown |
| `Output` | what a command printed, with its line breaks kept |

Colours are the panel's semantic names, `primary`, `success`, `warning`,
`danger`, `info` and `gray`, not hex values. A plugin cannot fight the
operator's theme or produce something unreadable in dark mode.

Column types (`badge`, `boolean`, `number`, `dateTime`, `bytes`) tell the panel
how to format a value. Send an ISO 8601 string for a date and let the panel
render it in the viewer's timezone and locale.

## Command output

`Output` is for what a program printed. Line breaks and indentation carry the
meaning, so the panel keeps them, scrolls the block rather than growing the
page, and can pin it to the newest line while something is still running.

```php
Output::make($job->output)
    ->title('Deploy')
    ->status($job->failed() ? 'danger' : 'success')
    ->follow()
    ->lines(24);
```

Its content is the one thing on a page that did not come from the plugin: it
came from a command, which means it came from the customer's own application.
The panel escapes it like everything else.

## Live pages

```php
Page::make('Migration')->poll(10);
```

The panel re-renders every ten seconds. Every poll is a request to the plugin,
so the floor is five seconds. Use it for something genuinely in progress, not
as a substitute for a page that reports its own state.

## Pages a plugin draws itself

```json
{ "panel": "client", "slug": "console", "title": "Console", "render": "iframe" }
```

```php
$plugin->app('console', __DIR__ . '/../ui/dist');
```

That is a built front end: a directory with an `index.html` and its assets.
The panel serves it inside a frame and carries its calls back, so the plugin
still needs no public port.

There is nothing to configure. The bundle is served at `/ui/{slug}` on the
plugin's listener and the panel proxies exactly that.

### How it talks back

The frame is **sandboxed out of the panel's origin**. Its scripts cannot read
the panel's DOM, its cookies or its storage, and it is sent no session cookie.
What authenticates its calls is a token the panel mints when it draws the
page, which sits in the frame's own URL.

So the front end calls its actions relatively, and gets the same actions a
declarative page uses:

```js
await fetch('./action/scan', { method: 'POST' })
```

Which needs somewhere to read state from, and that is what `data()` is for:

```php
$plugin->action('state', fn (UiRequest $request) => UiResponse::data([
    'applications' => $this->applications($request),
]));
```

One request for the whole page rather than one per section: every call is a
round trip through the panel to the plugin and back.

### Two things to be careful about

**Secrets.** On a declarative page the panel drops a secret field's value
before it serialises the page. A front end gets exactly what the plugin sends
it, so masking is the plugin's job. Send whether a password is set, never the
password.

**Everything else the panel was doing for you.** The operator's theme, dark
mode, the phone layout, translation, and having no markup to escape. All of
that is now the plugin's, in a page that sits inside a panel that has already
made those decisions. Follow `prefers-color-scheme` at the very least.

### When to take the trade

When the components genuinely cannot express it: output that streams while
something runs, a canvas, an editor. Not because a declarative page is a few
components short. Adding the component is usually the smaller job, and every
page that takes it keeps looking like the panel.

Both can be registered at once. `render` in the manifest decides which the
panel asks for, so switching back is one field.

## Testing

Pages and actions are ordinary functions of a request, so there is nothing to
stand up:

```php
$result = $plugin->httpRuntime()->handle('POST', '/ui/zones', $headers, $body);

self::assertSame('Cloudflare', $result->json()['page']['heading']);
```

`bolt-plugin page zones` renders a page against a running plugin and prints
what the panel would draw.
