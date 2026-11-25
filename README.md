# Email Editor for Flarum 2.0

![License](https://img.shields.io/badge/license-MIT-blue.svg) ![Flarum](https://img.shields.io/badge/flarum-2.0-orange.svg)

Change the wording of every email your forum sends, and how the emails look, from the admin panel.

It changes **emails only**. Nothing on the forum itself is touched, and no core or extension files are edited, so Flarum and extension updates don't undo your changes.

## Features

- **Every email in one list.** Account activation, password reset, email change, notifications (replies, mentions, new posts, suspensions, private discussions, …) and the shared parts (greeting, sign-off, footers), grouped by extension.
- **Automatically includes future extensions.** The list is not hard-coded. In Flarum 2.0, core and extensions keep the words of their emails in translations named `<extension>.email.…`, and Email Editor finds every one of them each time you open it. Install a new extension that sends email and its emails appear, ready to edit.
- **Edit the subject and body** of each email, with:
  - an **Insert tag** button inside each text box: pick a placeholder (`{title}`, `{url}`, …) from one list that says what each one is, and it goes in at the cursor
  - an **HTML | Text** switch where a text has separate HTML-email and plain-text wording, so each pair is one box
  - the **original wording** shown next to your version, with a one-click "use original"
  - a check that refuses text with a broken placeholder, because that would make the email fail to send
  - **reset** per email, back to the original
- **Tags that work in every email**, on top of each text's own placeholders: `{forumTitle}` `{forumUrl}` `{settingsLink}` `{userEmail}` `{username}` `{unsubscribeLink}` `{type}` `{date}` `{year}`. They work in subjects and in the extra footer text too. `{unsubscribeLink}` and `{type}` only have a value in notification emails and are empty in account emails. For example, the notification footer can be one line: `Sent to {userEmail} · [Unsubscribe]({unsubscribeLink}) · [Notification settings]({settingsLink})`.
- **Show or hide** the title, the greeting ("Hey name,") and the sign-off, with a switch next to each text (Flarum core → General texts, Notification). Works with Flarum's standard layout and the custom one.
- **Layout options** (optional; off by default, so emails keep Flarum's standard layout until you switch it on):
  - logo position
  - colours for the page background, email background, border, text, and links and title
  - extra footer text (Markdown) on every email
- **Preview first.** Pick an email and it opens on its preview, with **HTML**, **Plain text** and **Edit** tabs. Unsaved changes show everywhere they're used, the greeting, sign-off and footers included.
  - **The real email.** A notification email is built from its own template, exactly as it sends (post excerpt box included), using the newest public post on the forum. Shared parts (greeting, sign-off, footers) are shown inside a real email. Account emails use sample values.
  - **Highlight** rings the texts you're editing in red, so you can see where they land. It's only in the preview, never in an email that's sent.
  - A **Light / Dark** switch shows the HTML email the way mail apps display it in dark mode.
- **Send test to me**: emails the preview to your own address.
- **Multiple languages**: if you have language packs installed, pick which language's texts to edit.
- Edited texts are kept per language in your database. Texts you haven't edited keep following the original, including when an update changes it.
- Admin-only, and works with light and dark mode.

## Requirements

- Flarum **2.0** or later
- PHP 8.2+ (whatever your Flarum 2.0 install needs) with the `intl` extension (Flarum needs it anyway)

## Installation

```sh
composer require mrcaspan/flarum2-email-editor
php flarum migrate
php flarum cache:clear
```

Then go to *Admin → Extensions → Email Editor* and enable it.

## Updating

```sh
composer update mrcaspan/flarum2-email-editor
php flarum migrate
php flarum cache:clear
```

## Uninstalling

Disable the extension, and every email goes back to its original wording and layout. To also drop its table (your edited texts), run `php flarum migrate:reset --extension=mrcaspan-flarum2-email-editor`. Then run `composer remove mrcaspan/flarum2-email-editor`.

## How it works

- **Wording.** Edits are stored in the `email_editor_overrides` table and handed to Flarum's translator as one more translation source, added after every extension's own, so they win. When you save, the extension bumps Flarum's `locale_revision` setting (core's built-in signal for "translations stored outside the files changed"), so every server rebuilds its translation cache.
- **Layout.** Every Flarum 2.0 email is wrapped in core's `mail::html` and `mail::plain` layouts. Email Editor registers its own versions of those two views in front of core's. While **Use the custom layout** is off they simply render core's original layout, so nothing changes. When it's on they render Email Editor's layout with your options.
- **Which texts are listed.** Every translation key of the form `<namespace>.email.<…>`. Keys are grouped into emails by their name (`….subject`, `….html.body`, `….plain.body` and so on).

### Limits

- An extension that writes its email wording straight into its view instead of using translations can't have that wording edited. Its emails still get your layout options.
- The preview builds notification emails from real forum content (the newest post guests can see), so the forum needs at least one public post for that. If a notification can't be built that way, or for account emails, the preview shows the email's text inside Flarum's standard email with sample values, and the real email can differ slightly.
- If an extension update adds a new placeholder to an email you've edited, your version keeps working. Click "Show original" to see what changed and add the new placeholder yourself.

### API (admin only)

| Method | Endpoint                     | Does                                                   |
| ------ | ---------------------------- | ------------------------------------------------------ |
| `GET`  | `/api/email-editor?locale=`  | Every email text, grouped                              |
| `POST` | `/api/email-editor`          | Save `{ locale, values: { key: text \| null } }`       |
| `POST` | `/api/email-editor/preview`  | Render a preview                                       |
| `POST` | `/api/email-editor/test`     | Render a preview and email it to you                   |

## Development

The compiled JavaScript (`js/dist`) is committed. To change the admin page:

```sh
cd js
npm install
npm run build      # or: npm run dev
```

Translations are in `resources/locale/en.yml`. Pull requests that add other languages are welcome.

## Credits

Built by [MrCaspan](https://github.com/MrCaspan) for [PokerForum.ca](https://pokerforum.ca).

**[Claude Code](https://claude.com/claude-code)** (Anthropic's AI coding assistant) was used to help vibe code this extension. It was tested on a real Flarum 2.0 forum. Issues and pull requests are welcome.

## License

[MIT](LICENSE)
