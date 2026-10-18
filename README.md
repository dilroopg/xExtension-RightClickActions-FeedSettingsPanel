# Right Click Actions - Feed Settings Panel

Open a feed's settings in a floating panel over the page you are already on,
instead of navigating away to the subscription page.

Right-click a feed, change what you need, save, and carry on reading.

> **Requires [Right Click Actions](https://github.com/featurecreep-cron/freshrss-extensions/tree/main/xExtension-RightClickActions).**
> This extension adds one item to that extension's right-click menu. It builds no
> menu of its own for the sidebar — with Right Click Actions disabled there is no
> sidebar menu at all, so nothing appears missing.

## What it does

- One centered panel over the page; **Esc** or a click outside closes it.
- The page behind it does not reload, so your reading position is kept.
- Save writes only the fields you switched on, in place.
- **Open full page** keeps the stock subscription page one click away.

## Opening it

| Where | How |
|---|---|
| Sidebar feed | Right-click → **Feed settings (panel)** |
| Article list | Right-click the article → the feed's panel opens |
| This extension's settings page | **Open panel for…** picker |

The settings-page picker exists so the panel is reachable even with Right Click
Actions turned off.

## Choosing fields

The settings page groups every available field, each with its own switch, and
redraws a **live preview** on the right as you toggle — so you see exactly what a
field adds before you save. Each group has a *toggle all* shortcut.

| Group | Fields |
|---|---|
| **Feed** | Title, Feed URL, Website URL, Description, Category, Priority |
| **Refresh** | Refresh interval, Mute |
| **Display** | Show unread count, Show enclosure links, Sort order |
| **Behaviour** | Updated articles become unread, Keep at most N articles, Keep articles for |
| **Access** | HTTP username, HTTP password |
| **Info** | Unread / total count (read only) |

Defaults are Title, Feed URL, Website URL and Description; everything else starts
off.

Only the fields switched on are rendered **and** sent on save. The enabled list is
read server-side, so a hand-made request cannot write a field you turned off.

## Things worth knowing

- **Feed URL** is written as given, but changing it does not re-fetch or validate
  the feed until the next refresh. A URL that does not answer shows up as a feed
  error then.
- **Priority** writes FreshRSS's own `feed.priority`. *Do not show* (`-10`) removes
  the feed from the web stream and from the API, exactly like the stock setting.
  If another extension manages feed visibility, leave this switched off.
- **Retention** writes core's single nested `archiving` attribute, merging with its
  sibling values rather than replacing them.
- **HTTP password** left blank means "keep the stored password".
- Nothing is written unless you press Save, and only to the feed you opened.

## Disabling

Enabling this extension writes nothing to FreshRSS's data. Disabling it requests
no script and adds no markup, so the stock pages are exactly as they were. Your
field selection is kept, so re-enabling returns to the same state.

## Installation

Copy `xExtension-RightClickActions-FeedSettingsPanel` into your FreshRSS
`extensions/` directory, or install it through Extension Manager, then enable it
in **Settings → Extensions**. Enable Right Click Actions too.

## Requirements

- FreshRSS 1.28 or later
- [Right Click Actions](https://github.com/featurecreep-cron/freshrss-extensions/tree/main/xExtension-RightClickActions)

## License

MIT — see [LICENSE](LICENSE).
