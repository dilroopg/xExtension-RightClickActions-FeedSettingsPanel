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
| **Behaviour** | Updated articles become unread |
| **Archiving** | Keep at most N articles, Keep articles for |
| **Access** | HTTP username, HTTP password |
| **Info** | Unread / total count (read only) |
| **Actions** | Update feed now, Reload articles, Delete all articles, Export articles (OPML), Remove feed |

22 switches in total — 17 fields and 5 action buttons. Defaults are Title, Feed
URL, Website URL and Description; everything else, including every action, starts
off.

Only the items switched on are rendered **and** acted on. The enabled list is
read server-side, so a hand-made request cannot write a field or run an action
you turned off.

### The five actions

| Switch | What it does |
|---|---|
| **Update feed now** | Fetches the feed immediately instead of waiting for the next scheduled refresh. |
| **Reload articles** | Empties the feed's stored articles and fetches them again from the source. The feed stays subscribed. |
| **Delete all articles** | Deletes the feed's stored articles. The feed stays subscribed and will refill on the next refresh. |
| **Export articles (OPML)** | Downloads the feed's articles as an OPML file. Nothing is changed. |
| **Remove feed** | Unsubscribes the feed and deletes its articles. This cannot be undone. |

Every action runs in the panel and reports back in it: a spinner while the call
is in flight, then a tick or a warning with the result. Buttons and the form dim
while a call runs. The three destructive ones (Reload, Delete, Remove) ask for
confirmation first.

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

## Changelog

**1.3.0** — the panel runs the feed's actions, not just its fields, and says so
while it works.

- **Five new switches** in an Actions group, all off by default: *Update feed now*,
  *Reload articles* (empties the feed and fetches it again), *Delete all articles*
  (keeps the feed), *Export articles* (OPML download) and *Remove feed*. That brings
  the settings page to **22 switches** — 17 fields plus 5 actions — each with its
  own toggle and live preview.
- **Immediate feedback.** Every action and Save shows a status line in the panel
  the moment it is pressed — a spinner while it runs, then a tick or a warning
  with the result. The buttons and the form dim while a call is in flight, and
  the destructive actions ask for confirmation first. Previously the only feedback
  was whatever the page showed after a reload.
- **Removing a feed** takes its row out of the sidebar without a reload.
- Article retention moved into its own **Archiving** group, beside the new
  Delete/Reload actions it relates to.
- Refresh and Reload use FreshRSS's own actualizing path (reset `lastUpdate`,
  then `actualizeFeedsAndCommit`), so they fetch real articles rather than trusting
  the stored copy.
- Actions are gated twice: the button is only drawn if it is switched on, and the
  request is refused server-side if it is not.

**1.2.0** — the panel covers the whole feed, and the settings page shows you what
you are switching on.

- **17 fields across six groups** instead of four: Feed (title, URLs, description,
  category, priority), Refresh (interval, mute), Display (unread count, enclosures,
  sort order), Behaviour (updated-articles-unread, article retention), Access (HTTP
  username/password) and Info (unread/total, read only). Each group has a *toggle all*
  shortcut.
- **A live preview** beside the switches redraws as you toggle, so a field's effect is
  visible before you save it. Built from the same field definitions the panel uses, so
  the two cannot drift.
- **A dependency banner** on the settings page states plainly that this needs Right
  Click Actions, and says so out loud when that extension is disabled.
- **An Open panel for… picker** on the settings page, so the panel is reachable even
  with Right Click Actions turned off.
- Retention is written into core's single nested `archiving` attribute, merged with its
  sibling values rather than replacing them.

**1.1.0** — the extension is named `Right Click Actions - Feed Settings Panel` and its
directory sorts next to Right Click Actions, so the two appear together in the
extension list.

**1.0.0** — first release. Floating panel over the current page for Title, Feed URL,
Description and Website URL, opened by right-clicking a sidebar feed (through Right
Click Actions) or a feed in the article list. Field selection saved per user.

## License

MIT — see [LICENSE](LICENSE).
