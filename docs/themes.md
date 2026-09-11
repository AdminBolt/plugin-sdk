# Themes

A plugin can ship a stylesheet and offer the panel a different look.

The panel keeps its own stylesheet and loads the plugin's after it. That order
is the design: almost everything about how the panel looks is carried by
custom properties, the `--ab-*` tokens and the colour ramps, so a theme
usually redefines the handful it cares about and inherits the rest. A theme
that is half written, or whose file has gone missing, costs a few colours
rather than every style the panel has.

## Declaring one

```json
{
    "id": "sharp-theme",
    "name": "Sharp",
    "version": "1.0.0",
    "theme": {
        "name": "Sharp",
        "description": "Square corners, hairline borders, no soft shadows.",
        "css": "dist/theme.css",
        "panels": ["admin", "client", "reseller"]
    }
}
```

| Field | |
| --- | --- |
| `name` | What an operator picks from the plugins list. |
| `css` | The stylesheet, relative to the plugin root. It has to be a `.css` file inside the plugin. |
| `panels` | Which panels it repaints. All three when left out, so a theme for the client area alone is one line. |
| `description` | One line about the look, shown on the install screen. |

A plugin whose manifest has nothing else in it needs no `runtime` block and no
entrypoint. A theme is a file, not a program: there is no listener for the
panel to call, no secret to hold, and nothing to configure. It is the one kind
of plugin that installs with no credentials at all.

## Being chosen

Installing a theme makes it available and nothing else. An operator picks one
on the plugins page, and that choice is a setting rather than a property of
the plugin, so two installed themes are a choice rather than a fight and going
back to the panel's own look is one click.

A theme stops being worn the moment its plugin is deactivated, or its new
version asks for something nobody has approved. The paint follows the
approval.

## What to write in it

Start with the tokens. Redefining these changes the whole panel, in both
colour modes, without touching a single component:

```css
:root {
    --ab-card-radius: 0px;
    --ab-control-radius: 0px;
    --ab-line: #d9d6d0;
    --ab-shadow: 0 1px 0 rgba(40, 30, 20, 0.06);
}

:root.dark {
    --ab-line: #333;
}
```

Then, only where a token cannot reach, the panel's own classes. Filament names
everything it renders with an `fi-` prefix, and your file loads last, so a
single class selector is enough and `!important` almost never is.

Colour, elevation, radius, type and motion are safe ground, and so is a
density change to the chrome or a table row. What is not safe is anything a
page lays out against: widths, grid columns, and the padding of the content
container. That is where a theme stops being a theme and starts breaking
layouts on a phone.

## Fonts

A theme can change the typeface, and there are two ways to get one.

The panel already fetches its own from Google Fonts, so a theme on a box with
outbound access can do the same. The `@import` has to be the first thing in
the file that is not a comment:

```css
@import url('https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;600;700&display=swap');

:root {
    --ab-font-heading: 'IBM Plex Sans', sans-serif;
}

body,
.fi-body {
    font-family: 'IBM Plex Sans', ui-sans-serif, system-ui, sans-serif;
}
```

Or ship the file. Put it beside the stylesheet and point at it with a relative
URL:

```css
@font-face {
    font-family: 'Sharp Grotesk';
    src: url('fonts/sharp-grotesk.woff2') format('woff2');
    font-display: swap;
}
```

A relative URL in a stylesheet resolves against the stylesheet's own address,
which is `/plugin-theme/<plugin>/theme.css`, so the browser asks the panel for
`/plugin-theme/<plugin>/fonts/sharp-grotesk.woff2` and the panel serves it out
of the plugin, from beside the CSS file. Nothing to declare in the manifest.

The same holds for a background image or a logo the theme draws itself. What
may be served is an allowlist: `woff2`, `woff`, `ttf`, `otf`, `png`, `jpg`,
`webp`, `avif` and `gif`. SVG is deliberately not on it, because an SVG can
carry script, and a theme that needs a drawing can ship a PNG.

Self-hosting is the better answer on a box with no outbound access, or where
an operator would rather their administrators' browsers did not talk to a font
CDN on every page.

## What the panel will not do

The stylesheet is served as `text/css` with `X-Content-Type-Options: nosniff`,
from a URL that carries a fingerprint of the file, and only while the theme is
the one in use. A plugin sitting on disk unchosen serves nothing.

It is still CSS somebody else wrote, reaching a browser as they wrote it. The
install screen says so in those words, because an operator installing a theme
is agreeing to how their panel looks.
