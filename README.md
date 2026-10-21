# TeamsLogic for Nextcloud

The server side of [TeamsLogic](https://github.com/Aegean-Logic/teamslogic), Aegean Logic's
Teams-style desktop app for Nextcloud. A Nextcloud app (Nextcloud 32–35, PHP 8.2+) that lets
each user connect their **Google** or **Microsoft 365** account once:

- **Calendars** sync both ways, live: every remote calendar becomes a Nextcloud calendar named
  like "Google · Work" or "Outlook · Calendar", and changes on either side reach the other within
  the sync interval (5 minutes by default) or straight away with "Sync now".
- **Contacts** sync both ways: Google Contacts becomes "Google · Contacts"; each Outlook contact
  folder becomes an address book ("Outlook · Contacts", …).
- **Tasks** sync both ways: each Microsoft To Do or Google Tasks list becomes a Nextcloud task
  list ("Outlook · Tasks", "Google · My Tasks"), which the Nextcloud Tasks app, phones and
  TeamsLogic's To Do app use.
- **Mail**: the same sign-in sets up the account in [Nextcloud Mail](https://apps.nextcloud.com/apps/mail)
  (IMAP/SMTP with OAuth 2, no app password), so a user connects once for mail, calendar and contacts.

The synced copies are ordinary Nextcloud calendars and address books: the Calendar and Contacts
apps, CalDAV/CardDAV clients and TeamsLogic all see and edit them.

## Admin setup

Administration settings › **Groupware** › *TeamsLogic: Google and Microsoft 365*. Each provider
needs an OAuth client registered by you. The settings page shows the exact redirect URI to
register; it is

```
https://<your-nextcloud>/apps/teamslogic/oauth/google
https://<your-nextcloud>/apps/teamslogic/oauth/microsoft
```

(with `/index.php` before `/apps` if your server doesn't have pretty URLs). Use the value the
settings page shows. The URI is built from the address the request came in on, so people must
reach Nextcloud at the same address the admin page was opened at; behind a proxy, or with more
than one address, set `overwritehost` / `overwriteprotocol` (or `overwrite.cli.url`) in
`config.php` so it's always the same.

### Google Cloud

1. In the [Google Cloud console](https://console.cloud.google.com/), create (or pick) a project.
2. **APIs & Services › Library**: enable the **Google Calendar API**, the **People API** and the
   **Google Tasks API**
   (and the **Gmail API** if mail is on; IMAP needs no API, but Google lists Gmail scopes under it).
3. **OAuth consent screen**: user type *External* (or *Internal* for a Workspace-only setup). Add
   the scopes:
   - `openid`, `email` (to know which account was connected)
   - `https://www.googleapis.com/auth/calendar`
   - `https://www.googleapis.com/auth/contacts`
   - `https://www.googleapis.com/auth/tasks`
   - `https://mail.google.com/` (only with "Also connect mail")

   Calendar, contacts and mail are *sensitive / restricted* scopes: until Google verifies the app,
   only the test users you add on the consent screen can connect, and Google shows an
   "unverified app" warning.
4. **Credentials › Create credentials › OAuth client ID**, application type **Web application**.
   Add the Google redirect URI above under *Authorised redirect URIs*.
5. Enter the client ID and secret in the TeamsLogic settings.

### Microsoft Entra (Microsoft 365)

1. In the [Microsoft Entra admin center](https://entra.microsoft.com/): **App registrations ›
   New registration**. Supported account types: as you need (any organisation and personal
   accounts goes with tenant `common`). Redirect URI: platform **Web**, the Microsoft redirect
   URI above.
2. **Certificates & secrets › New client secret**. Enter the secret's *Value* (not its ID) in the
   TeamsLogic settings, with the **Application (client) ID**. Note when the secret expires.
3. **API permissions › Add a permission**, all **delegated**:
   - Microsoft Graph: `Calendars.ReadWrite`, `Contacts.ReadWrite`, `Tasks.ReadWrite`, `User.Read`,
     `offline_access`
   - Only with "Also connect mail", also under Microsoft Graph: `IMAP.AccessAsUser.All`,
     `SMTP.Send` (requested as `https://outlook.office.com/…`, which is what Exchange accepts)

   A tenant admin can *Grant admin consent* so users aren't asked. SMTP AUTH must be allowed
   for the mailboxes (Exchange admin center), as for any IMAP/SMTP client.
4. **Tenant** in the TeamsLogic settings: `common` (default), `organizations`, `consumers`, or
   your tenant ID / domain to allow only your organisation.

### Sync interval and mail

- **Sync every (minutes)**: how often the background job syncs each connection (default 5).
  Background jobs must run from **cron** for an interval this short.
- **Also connect mail** (on by default): connecting an account also creates the Nextcloud Mail
  account, and saving the settings writes the same Google / Microsoft clients into Mail's own
  integration settings (Mail refreshes its tokens itself), so the admin enters them once. Needs
  Nextcloud Mail 5.x; with Mail missing, disabled or another major version, mail is skipped and
  calendars and contacts still sync.

## For the desktop app (OCS API)

All under `/ocs/v2.php/apps/teamslogic/api/v1`, signed in as the user (app password), with
`OCS-APIRequest: true`.

| Request | What it does |
| --- | --- |
| `POST /connect/{google\|microsoft}` (`mail`, `calendar`, `contacts`: optional booleans) | Returns `{url, state, redirectUri, scopes, mail}`. Open `url` in a browser; the provider sends the user to `/apps/teamslogic/oauth/{provider}`, which stores the account, sets up Mail, queues the first sync and shows "Connected… you can close this window". 412 if the admin hasn't set the provider up. |
| `GET /connections` | `[{id, provider, email, mail, calendar, contacts, tasks, tasksGranted, mailAccountId, lastSync, lastError}]` (`lastSync`: Unix time; `tasksGranted` false: connected before tasks were added, connect again to sync them) |
| `GET /calendars` | The synced calendars: `[{connectionId, provider, email, calendarUri, name, readOnly, onlineMeeting}]`, where `onlineMeeting` is `teams`, `meet` or null: what the provider adds to a new event that asks for one (below). |
| `PATCH /connections/{id}` (`mail`, `calendar`, `contacts`, `tasks`) | Switches features. Off: calendars/contacts stop syncing and keep their local copies; mail removes the Mail account. Mail on for an account connected without mail answers 409 `{reconnect: true}`: connect again with mail. |
| `POST /connections/{id}/sync` | Syncs now; answers the connection plus `summary: {pulled, pushed, conflicts, errors}`. |
| `DELETE /connections/{id}?keepData=0\|1` | Disconnects: revokes the Google grant, removes the Mail account, and removes the synced calendars and address books (`keepData=1` leaves them as plain local ones). |

The callback page works in any browser (signed in to Nextcloud or not): the OAuth `state`
names the user, and only a hash of its random part is kept for 15 minutes and one use. PKCE
(S256) is used with both providers. Tokens are stored encrypted with the server secret.

## How sync works

- **Remote → Nextcloud**: Google Calendar `events.list` with `syncToken` (series masters and
  changed instances, `showDeleted`), Google People `connections.list` with `syncToken`, Graph
  `/me/calendars/{id}/events/delta` and `/me/contactFolders/{id}/contacts/delta`. An expired
  token (Google 410, Graph 410) starts a full listing; anything known but no longer listed was
  deleted.
- **Nextcloud → remote**: changes since the calendar's / address book's sync token
  (`getChangesForCalendar`, `getChangesForAddressBook`) are pushed with POST / PATCH / DELETE,
  carrying the remote etag (`If-Match`; Google People takes it in the body). On **412** the item
  is re-read and the **remote version wins**; that's logged (`TeamsLogic: … remote wins`).
- **Both sides changed** in the same round: remote wins, logged. Our own writes coming back are
  recognised by their ETag and not sent again.
- **A new etag isn't an edit**: providers change an item's etag without changing the item
  (Outlook does it a few seconds after an event is created). Each item keeps a hash of what the
  remote copy said last round (ignoring DTSTAMP, LAST-MODIFIED, SEQUENCE, REV…); if a "changed"
  remote item still says the same, nothing is pulled, and a local edit made meanwhile is sent
  instead of being overwritten.
- **Tasks**: Graph `/me/todo/lists/{id}/tasks/delta`; Google Tasks has no sync token, so a
  list is asked for the tasks updated since the last round began (`updatedMin`, less a minute),
  deleted ones included. Title, notes, due and start days, done (and when), importance
  (`PRIORITY` 1/5/9), categories, a reminder; To Do's "waiting on others" and "deferred" are kept
  in `X-TEAMSLOGIC-TODO-STATUS`; a Google subtask's parent comes across as `RELATED-TO`.
- **Read-only** remote calendars (Google reader / free-busy, Outlook `canEdit: false`) sync one
  way; a local edit is undone at the next round.
- A remote calendar or address book that's deleted takes its local copy with it.
- Tables: `teamslogic_connections` (account, encrypted tokens, features, per-collection sync
  state, last sync, last error) and `teamslogic_sync_items` (local URI ⇄ remote id, both etags).

### Teams and Meet meetings

A new event in a synced calendar can ask the provider for an online meeting with
`X-TEAMSLOGIC-ONLINE-MEETING:teams` (Outlook: `isOnlineMeeting` with the calendar's provider,
usually `teamsForBusiness`) or `:meet` (Google: `conferenceData.createRequest`, `hangoutsMeet`).
When it's pushed, the provider makes the meeting and the join link is written straight back into
the Nextcloud copy (`X-TEAMSLOGIC-JOIN-URL`, a "Join online: …" line in the description, and
the location if it was empty). Pulled events that already have a Teams or Meet meeting carry the
same two properties. If the event's attendees are `SCHEDULE-AGENT=CLIENT`, the provider sends the
invitations (Google with `sendUpdates=all`; Outlook always does) and Nextcloud doesn't.
The join line isn't sent back to the provider, which keeps the link itself.

### What converts

| | Google | Microsoft Graph |
| --- | --- | --- |
| Events | title, description, location, start/end with time zone, all-day, RRULE/EXDATE (as is), changed and cancelled instances, attendees (status, optional, rooms), organiser, reminders (popup/email; "default reminders" kept), Meet link, free/busy, visibility, colour | subject, body (HTML to text), location, start/end in the organiser's zone (Windows ⇄ IANA names), all-day, RRULE ⇄ `patternedRecurrence` (daily, weekly, absolute/relative monthly and yearly; end date, count, no end), changed occurrences, cancelled occurrences, attendees, organiser, reminder, Teams link, show-as (`X-MICROSOFT-CDO-BUSYSTATUS`), sensitivity, categories |
| Contacts | names, nickname, emails (home/work, primary), phones (mobile, home, work, fax, pager), addresses, organisation, title, birthday (with or without year), website, note, photo (Google's link one way; an embedded photo is uploaded) | names, nickname, three emails, mobile, business and home phones, home/business/other address, company, department, job title, birthday, web page, notes, categories, photo (both ways) |

## Limits

- Tasks: To Do's steps (checklist items), recurrence and attachments stay in To Do; a subtask made
  in Nextcloud becomes a top-level Google task; a list made in Nextcloud isn't created in To Do or
  Google Tasks (make it there and it comes across). Accounts connected before tasks were added
  need connecting again (Microsoft refuses a token for a permission the user hasn't granted).

- **Graph events delta is a Graph beta endpoint.** Graph v1.0 has event delta only for a date
  range of the primary calendar, so `/me/calendars/{id}/events/delta` comes from `beta`. If
  Microsoft refuses it for a calendar, that calendar is listed in full each round (v1.0) and
  compared by etag instead (slower, same result). Everything else uses v1.0.
- Outlook changed occurrences are read for one year back and two years ahead.
- RRULEs Outlook can't express (hourly, several days of the month, `BYHOUR`, …) aren't pushed to
  Microsoft; the event stays local and the reason is logged. Google takes RRULEs as they are.
- Creating an event with attendees in Outlook sends Outlook's invitations; Nextcloud may send its
  own iMIP invitations too if the organiser is the Nextcloud user's address. Google events are
  created without notifications.
- A photo removed locally isn't removed at Google; Outlook keeps three email addresses and one
  address of each kind.
- Microsoft offers no way for an app to revoke a delegated grant: disconnecting drops the tokens,
  and the user can remove the app at https://myapps.microsoft.com (work) or
  https://account.live.com/consent/Manage (personal).
- Nextcloud Mail refreshes the Microsoft mail token itself without naming the Outlook scopes; that
  relies on the refresh token Mail is given having been issued for Outlook (it is: TeamsLogic
  redeems it for Outlook before handing it over).
- On-premises Exchange (EWS) is out of scope. Microsoft is switching EWS off for Microsoft 365
  (from 1 October 2026, gone 1 April 2027), which is why this uses Microsoft Graph.

## Development

```
composer install
vendor/bin/phpunit
```

The unit tests cover the converters (iCalendar ⇄ Google event, iCalendar ⇄ Graph event,
RRULE ⇄ Graph recurrence, vCard ⇄ Google person, vCard ⇄ Graph contact) and the sync planner.
They need only sabre/vobject, not a Nextcloud server. The JSON fixtures in `tests/fixtures` are
modelled on the providers' documented responses; replace them with recorded ones as real
accounts become available.

To try it on a server, copy the app (without `vendor/` and `tests/`) to `custom_apps/teamslogic`
and run `occ app:enable teamslogic`.

## Licence

AGPL-3.0-or-later. © 2026 Aegean Logic.
