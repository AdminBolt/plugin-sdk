# Compatibility promise

A plugin that works on AdminBolt today keeps working on newer AdminBolt
versions. When we have to change something a plugin relies on in a way that
would break it, we announce it first, and the old way keeps working for **at
least 12 months** after the announcement and through **the next major
version of AdminBolt**. It is removed no earlier than the second major
version after the announcement, and only when both have passed.

For example: something announced as deprecated in AdminBolt 2.3, in March,
keeps working in every 2.x and 3.x release, and goes no earlier than 4.0 and
no earlier than the following March.

## What the promise covers

Everything a plugin talks to the panel through, as these documents describe
it:

| Part | Covered |
| --- | --- |
| [plugin.json](manifest.md) | Every documented field and what it means. |
| [Hooks](hooks.md) | Hook names, when they fire, the envelope fields, the `v1` signature, and the responses a plugin may send (ok, reject, mutate). |
| [Panel API](api.md) | The endpoints a scope grants, their fields and errors, and the scope names. |
| [Pages](ui.md) and [slots](slots.md) | The components a page or slot description may use, frame pages and the calls a frame may make. |
| [Commands](commands.md) and setup scripts | How they are declared, approved and run, and what they receive. |
| The SDK | The public classes and methods of `adminbolt/plugin-sdk`, under [semantic versioning](https://semver.org): nothing breaks within a major version. |

## What is not a breaking change

So that the panel can grow, these happen in any release without notice, and a
plugin must cope with them:

- a new hook, a new field in an envelope or an API response, a new error code;
- a new component for pages and slots, a new optional field in `plugin.json`;
- a new scope, endpoint or position for slots;
- a hook firing in a case where it did not fire before, for an operation the
  panel gained.

Read what you know and ignore the rest. A plugin that refuses a delivery
because it has an extra field breaks itself.

## What the promise does not cover

- Anything not documented here: the panel's database, files, internal routes,
  its own pages' HTML and CSS, and fields that happen to be in a response but
  are not in these documents.
- A fix that makes the panel do what these documents already say, even when a
  plugin relied on the wrong behaviour.
- A security problem in the contract itself. Then we may change it sooner, as
  little as is needed, and we write to the developers of every plugin on the
  store it affects, with the reason and what to do.
- Hooks, fields and endpoints marked **experimental** in these documents.
  They may change in any minor release; we say so in the release notes.
- Services outside AdminBolt that a plugin uses.

## How a breaking change happens

1. **Announced.** It is listed under *Deprecated* in the panel's release notes
   and in the SDK's release notes, with the date, what replaces it, and the
   earliest version it may be removed in. The documents here mark it
   deprecated from that day. We write to the developers of the plugins on the
   store that use it, which the store can tell from their `plugin.json`.
2. **Side by side.** The replacement ships in the same release, so a plugin can
   move to it at once and still run on the panels that have not updated.
   Where the change is to how the panel and a plugin talk, the new way gets a
   new contract number (the panel sends `X-Bolt-Contract: 1` today), and the
   panel keeps speaking the old one to plugins written for it.
3. **Removed** only in a major version of AdminBolt, and only when the 12
   months and the next major version have both passed.

## Versions

AdminBolt versions are `major.minor.patch`. Patch and minor releases never
remove anything a plugin relies on. Removals happen only in a major release,
under the rules above.

An SDK major version is supported, with fixes, for at least 12 months after
the next one is released. A plugin built on SDK 1.x keeps working on every
AdminBolt version that speaks contract 1.

## Your side

- Set `panel` in `plugin.json` to the oldest AdminBolt version you test with.
  A panel too old for it then refuses to install it, which is better than a
  hook that never fires.
- Use only what these documents describe.
- Ignore fields you do not know.
- Read the *Deprecated* sections of the release notes, or watch your email:
  we write before anything you use goes away.
