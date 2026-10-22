# GoldStarBot — Slack App Directory Listing (copy-paste ready)

Submit at: **api.slack.com/apps → GoldStarBot → Manage Distribution → Submit for review**
(App ID `A0BJ9UMBUAW`). Fill each field below.

---

## App name
```
GoldStarBot
```

## Short description / tagline (≤ ~140 chars — shows in search)
```
Peer recognition for Slack. Give a teammate a gold star with /gs — tallies, leaderboards & achievements. Flat $19/mo, unlimited users.
```

## Categories (pick the closest Slack offers)
1. **HR & Team Culture** (primary)
2. Productivity
3. Communication

## Long description

> **GoldStarBot — peer recognition that lives right in Slack**
>
> Great work deserves a shout-out where your team already is. GoldStarBot lets anyone
> recognize a teammate with a single command — `/gs @teammate for crushing the launch` —
> and instantly celebrates it in Slack.
>
> **How it works**
> Type `/gs @teammate why they earned it` in any channel. GoldStarBot posts a gold-star
> shout-out, keeps a running tally for everyone, and unlocks achievement badges as people
> rack up stars. Check standings any time with `/gs leaderboard`.
>
> **Why teams love it**
> - ⭐ **One-command recognition** — nothing new to learn, it's just Slack.
> - 📊 **Automatic tallies & leaderboards** — see who's spreading the love.
> - 🏆 **Achievements** — six tiers from First Star to Legend keep it fun.
> - 📣 **Central recognition channel** — celebrate wins where the whole team sees them.
> - 💸 **Flat pricing** — $19/month for your **entire team, unlimited users**. No per-seat
>   fees. (Most recognition tools charge ~$3/user — a 50-person team pays $150+. GoldStarBot is $19.)
>
> **Perfect for** remote and hybrid teams, People Ops, and team leads who want a lightweight,
> genuine way to build morale and make recognition a daily habit.
>
> Start a free 7-day trial — add it to your workspace in under a minute.

## Feature bullets (if a separate field)
- Give recognition in any channel with `/gs @teammate reason`
- Automatic per-person star tallies
- `/gs leaderboard` — team standings
- `/gs stats` — a person's stars + achievements
- 6 achievement tiers (First Star → Legend)
- Posts to a central recognition channel or in-channel
- Flat $19/mo, unlimited users, 7-day free trial

## Slash command
- **Command:** `/gs`
- **Usage hint:** `@teammate why they earned it`
- **Description:** Give a teammate a gold star. Also: `/gs leaderboard`, `/gs stats [@user]`, `/gs achievements`, `/gs help`.

## Bot scopes — justification (Slack review REQUIRES a reason per scope)
| Scope | Why we need it |
|---|---|
| `commands` | Provides the `/gs` slash command members type to give recognition. |
| `chat:write` | Posts the recognition message and achievement-unlock notices into Slack. |
| `chat:write.public` | Lets the bot post to the recognition channel without needing to be manually invited first, so recognition works immediately after install. |
| `users:read` | Resolves Slack user IDs to display names for leaderboards and shout-out messages. |

## Data & security (for the review questionnaire)
- OAuth v2 install; each workspace gets its own bot token (no shared tokens).
- All inbound Slack requests are verified via v0 HMAC signing-secret signature.
- We store: workspace ID/name, Slack user IDs + display names, and star messages/tallies.
- **No message content beyond the recognition the user explicitly sends.** No card data (billing is via Stripe).
- Data handling: see Privacy Policy.

## Links
- **Website:** https://goldstarbot.com
- **Privacy Policy:** https://goldstarbot.com/privacy
- **Terms of Service:** https://goldstarbot.com/terms
- **Setup guide:** https://goldstarbot.com/setup
- **Support email:** support@goldstarbot.com

## Getting started (install instructions for the listing)
1. Click **Add to Slack** and approve.
2. You'll land on your GoldStarBot dashboard — start your free 7-day trial.
3. In Slack, type `/gs @teammate great work on the launch` to give your first gold star.

---

## Pre-submission checklist
- [x] OAuth v2 install flow + valid redirect URL (`/slack/oauth/callback`)
- [x] Request signature verification (signing secret)
- [x] Privacy Policy + Terms live
- [x] Only necessary scopes requested (4)
- [x] Per-workspace tokens, error handling, no hardcoded secrets
- [ ] **Confirm `support@goldstarbot.com` receives mail** (reviewers may test it)
- [ ] **Listing screenshots** — Slack wants a few (app in action: a `/gs` shout-out, the leaderboard, achievements). Sizes per Slack's current spec.
- [ ] Optional: a short demo video / GIF

**Review takes ~1–3 weeks — submit early.**
