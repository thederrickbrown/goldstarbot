# GoldStarBot ⭐

A Slack company-recognition / merit system. Teammates give each other "gold
stars" from any channel with a slash command; the bot posts the recognition
publicly, keeps a per-user tally, and awards milestone achievements.

```
/gs @teammate setting up my beta test environment
```

Built as a PHP Slim 4 app so it deploys to the same SiteGround shared hosting as
the existing dplybt.com bots (drop-in front controller + MySQL, no long-running
Node process required).

---

## How it works

1. Slack sends the slash command as a form POST to `https://goldstarbot.com/star`.
2. `StarAction` verifies the Slack signature, parses the mention(s) and reason.
3. Each star is written to MySQL; the recipient's new total is computed and any
   newly crossed achievement tiers are awarded — all in one transaction.
4. The recognition message is posted publicly (see `post_target` below).

### Commands

| Command | Does |
| --- | --- |
| `/gs @teammate why they earned it` | Give a gold star |
| `/gs @a @b great teamwork` | Star multiple people at once |
| `/gs leaderboard` | Top 10 receivers (private to you) |
| `/gs stats [@teammate]` | Stars given/received + achievements |
| `/gs help` | Usage |

### Achievements (stars received)

🌟 First Star (1) · ✨ Rising Star (5) · ⭐ Star Player (10) · 🌠 Superstar (25) ·
💫 All-Star (50) · 🏆 Legend (100)

---

## Layout

```
goldstar/
├── app/                 settings, DI, routes, middleware
├── src/
│   ├── Application/Actions/StarAction.php   slash-command handler
│   ├── Slack/           SlackSignature (verify), SlackClient (post)
│   └── Support/         StarService (DB), AchievementCatalog
├── public_html/         web root: index.php + .htaccess  <-- domain docroot
├── sql/schema.sql       MySQL tables
├── config.example.php   copy -> config.local.php and fill in secrets
└── composer.json
```

`app/`, `src/`, `vendor/`, and `config.local.php` live **above** `public_html`,
so secrets are never web-accessible.

---

## Setup

### 1. Install dependencies

```bash
cd goldstar
composer install
```

### 2. Create the database

In SiteGround Site Tools → MySQL, create a database + user, then import
`sql/schema.sql` via phpMyAdmin.

### 3. Configure secrets

```bash
cp config.example.php config.local.php
```

Fill in MySQL creds and Slack values (see next section). `config.local.php` is
git-ignored.

### 4. Deploy

Upload so that the domain's document root points at `goldstar/public_html`, with
`app/`, `src/`, `vendor/`, and `config.local.php` one level above it (same shape
as `deploybot_small`). Match the PHP version in `public_html/.htaccess` to your
plan.

---

## Slack app configuration (api.slack.com/apps)

The easiest path is the in-app guide at **`/admin/setup`**, which gives you a
ready-to-paste app manifest. Manual equivalent:

1. **Create an app** → From a manifest → install into your workspace.
2. **Slash Commands** (set by the manifest)
   - Command: `/gs`  (note: `/star` is reserved by Slack and rejected)
   - Request URL: `https://goldstarbot.com/star`
   - ✅ **Escape channels, users, and links sent to your app** (required — this
     is what turns `@jeff` into `<@U123|jeff>` so the bot can resolve the user).
3. **OAuth & Permissions → Bot Token Scopes**: add `chat:write` (and
   `chat:write.public` if the bot should post to channels it hasn't been invited
   to). Install/reinstall the app, then copy the **Bot User OAuth Token**
   (`xoxb-…`) into `config.local.php`.
4. **Basic Information → Signing Secret** → copy into `config.local.php`.
5. Invite the bot to your recognition channel, and put that channel's **ID**
   (not name) into `recognition_channel`.

### `post_target` options

- `channel` (default) — acknowledge the giver privately, post the star to one
  central `recognition_channel`. Requires the bot token.
- `in_channel` — post the star back into whatever channel `/gs` was run in.
  No bot token needed.
- `both` — post in the current channel **and** mirror to the central channel.

---

## Admin & workspace allowlist

Every Slack workspace that touches the bot is recorded in `gsb_teams`. New teams
start as **`pending`** and cannot give stars until approved — this stops any
un-invited workspace from writing into the system (all data is also `team_id`-scoped).

Manage workspaces at **`https://goldstarbot.com/admin`** (session login + CSRF):

- Approve / Block / set-Pending any workspace
- Add & approve a workspace before it ever runs `/gs`
- See per-workspace star and people counts

Admin credentials live in `config.local.php` under `admin` (`username` +
bcrypt `password_hash`). Generate a hash with:

```bash
php -r "echo password_hash('YOUR_PASSWORD', PASSWORD_DEFAULT), PHP_EOL;"
```

Typical onboarding: install the Slack app in a workspace → someone runs `/gs`
→ it shows "not enabled yet" and the workspace appears as **pending** in `/admin`
→ you click **Approve** → stars start flowing.

## Notes

- Self-stars are rejected; duplicate mentions are de-duplicated.
- Signature verification rejects requests older than 5 minutes (replay guard).
- All tallies are scoped per Slack workspace (`team_id`).
