# Slots

A page is somewhere a customer goes. A slot is something they meet without
going anywhere: a line in the footer of every page, a note under the
navigation, a card above the content, a badge next to the global search.

Every place the panel will draw something for a plugin has a name, and
`AdminBolt\Plugin\Ui\SlotPosition` is the whole list. The names are the
contract: the panel keeps its own map from them to wherever it currently
draws, so a plugin that declares `footer` keeps drawing in the footer whatever
the panel is built on next year.

What a slot returns is a page description, and the panel renders it with its
own components. A slot carries no markup, exactly like a page.

## Declaring one

```json
"slots": [
    {
        "panel": "client",
        "position": "sidebar.nav.end",
        "slug": "policy-note",
        "label": "A note under the navigation",
        "cache": 120
    }
]
```

| Field | |
| --- | --- |
| `panel` | `admin`, `client` or `reseller`. Who sees it. |
| `position` | One of the names in `SlotPosition`. |
| `slug` | What the handler is registered under. |
| `label` | What the install screen calls it. Optional, and worth writing: without one the administrator reads a Filament identifier. |
| `sort` | Order among this plugin's own slots in the same position. |
| `cache` | Seconds the panel may reuse the answer. 60 by default, 3600 at most, 0 for never. |

## Answering

```php
use AdminBolt\Plugin\Ui\Page;
use AdminBolt\Plugin\Ui\Text;
use AdminBolt\Plugin\Ui\UiRequest;

$plugin->slot('policy-note', function (UiRequest $request) use ($plugin): Page {
    $blocked = $plugin->store()->get('blocked_today', 0);

    return Page::make('')->add(
        Text::make(sprintf('%d domains were refused today.', $blocked))
    );
});
```

The heading of the page is ignored: a slot is a corner of somebody else's
page, not a page of its own. Only the components are drawn, and at most five
of them.

## Where you can draw

Sixty-two positions, listed in `SlotPosition`. These are the ones worth
knowing:

| Position | Where it lands |
| --- | --- |
| `footer` | under the content, on every page |
| `content.start` | above the content of every page |
| `content.end` | below the content of every page |
| `sidebar.nav.start` | above the navigation |
| `sidebar.nav.end` | under the navigation |
| `sidebar.footer` | the bottom of the sidebar |
| `topbar.start` | the top bar, beside the title |
| `topbar.end` | the top bar, by the user menu |
| `body.start` | the first thing inside the document body |
| `body.end` | the last thing inside the document body |
| `page.start` | above a page's header |
| `page.end` | below a page's content |
| `global-search.after` | beside the search box |
| `user-menu.before` | above the user menu |
| `auth.login.form.after` | under the login form |
| `resource.pages.list-records.table.before` | above every resource table |

The rest are the remaining halves of these pairs, the sub-navigation of a
page, the relation managers and tabs of a resource, the tenant menu, and the
register and password-reset forms. Use the constants and let your editor
finish them:

```php
use AdminBolt\Plugin\Ui\SlotPosition;

SlotPosition::FOOTER;            // 'footer'
SlotPosition::SIDEBAR_NAV_END;   // 'sidebar.nav.end'
```

There is no name for the document head, the script block or the stylesheet
block. Those take a script tag, a stylesheet link or a meta element, and a
plugin sends a description rather than markup, so there is nothing it could
put in any of them.

A position the catalogue does not have is dropped when the plugin is installed
and never drawn. `bolt-plugin validate` catches it first, and suggests the
name you probably meant.


## What a slot may contain

`Stat`, `Table`, `Section`, `Alert`, `Text` and `Output`. Anything with a
button on it is dropped, including a table's row actions and an alert's
action: an action is wired to the page rendering it, and in a footer there is
no page to wire it to. A slot that wants a button links to one of the plugin's
own pages instead, which `Text::markdown()` will do.

## What it costs

A slot renders while somebody is waiting for a page that has nothing to do
with the plugin, so three things are true of it that are not true of a page:

- **The answer is cached**, for `cache` seconds, keyed on who is looking. The
  default of a minute turns a slot into one round trip a minute per viewer
  rather than one per request.
- **The timeout is short**, three seconds by default, set by the operator.
- **A plugin that fails is left alone.** After one failure the panel stops
  calling that slot for a minute. Without it, a plugin whose listener is down
  would add its timeout to every request in the panel.

A slot is also told who is viewing but not what they are looking at: it is
chrome, the same on every page, and a slot that varied by page would be a
round trip per page.

## Being installed

Slots appear on the install screen in their own tab, under the label the
manifest gives them. An administrator approving a plugin sees "it draws in
two places in your panel" before they say yes, which is the reason `label` is
worth writing.
