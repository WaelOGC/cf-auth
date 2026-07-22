# CF-Auth Plugin — Documentation Log

## Purpose
This file tracks every feature, fix, and pending item implemented in the cf-auth plugin, so any developer (including future Cursor sessions) can understand the full history without re-reading all code.

## Completed Features

### Authentication & Registration
- Manual registration (email/password) via `class-cf-registration.php` — sets `cf_account_status = pending` and `cf_email_verified = 0`, sends a verification email, no auto-login on register
- Email verification required before login (`class-cf-login.php` blocks login while `cf_email_verified !== '1'`)
- Social login (Google, Facebook, Discord, Twitter — each independently togglable) via `class-cf-social-auth.php`; social sign-in auto-verifies email since the provider already confirmed ownership
- Password reset flow via `class-cf-password.php`
- All five sensitive frontend forms (login, register, forgot-password, reset-password, change-password) in `class-cf-shortcodes.php` use `method="post"`

### Email System (`class-cf-email.php`)
- `send_verification($user_id)` — verification email, 24h token
- `send_password_reset($user_id)` — reset email, 1h token
- `send_welcome($user_id)` — sent after successful email verification
- `send_feedback_confirmation($user_id)` — sent after FAQ platform review submission
- `send_review_published($user_id)` — sent when an admin approves a pending review comment
- All templates use table-based HTML with fully inline `style=""` attributes (required for Gmail, which strips `<style>` blocks)

### Notifications System (Polling-based)
- Table: `wp_cf_notifications` (id, user_id, type, title, message, link, is_read, created_at)
- `class-cf-notifications.php` — singleton, AJAX handlers `cf_get_notifications` and `cf_mark_notifications_read`
- Triggered via `transition_post_status` hook in `class-cf-core.php` when a track, album, or article is published
- Real-time (WebSocket/push) delivery is a planned future phase, not yet built

### User Profile (`class-cf-profile.php`)
- Profile field updates, avatar upload (JPG/PNG/GIF/WEBP, 2MB max, old avatar auto-deleted on replace), favorites toggle, listening history logging/retrieval, nonce refresh, and account deletion — all via AJAX handlers guarded by `check_ajax_referer` + `is_user_logged_in`
- **Rewards tab** on `cf_user_profile` (between Playlists and Settings): server-rendered Xfinity balance + referral link with client-side Copy; AJAX `cf_get_xfinity_summary` (in `class-cf-xfinity.php`) loads referral stats (maps `flagged_fake` → "Under review") and last 20 ledger entries with human-readable source labels; JS mirrors `loadHistory()` once-per-page-load pattern in `cf-auth.js`

### Playlists (`class-cf-playlists.php`)
- Users can create, rename, delete, and toggle public/private visibility on playlists; add/remove tracks or albums; fetch their own playlists (optionally filtered by whether a given item is already in them); fetch a public playlist by share token
- **Ownership enforcement**: every mutating/reading handler (except the public share endpoint) resolves the playlist through a single private helper, `get_owned_playlist()`, which returns null (surfaced to the client as a generic "Playlist not found") unless the playlist's `user_id` matches the currently logged-in user — this prevents one user from reading or modifying another user's playlist by guessing/tampering with a `playlist_id`
- Public playlist sharing (`handle_get_public_playlist`) checks `is_playlist_visible()` (public flag OR the requester is the owner) before returning any data, and `resolve_item()` only returns posts with `post_status = publish`, so drafts are never leaked via a shared playlist
- **Rate limiting on playlist creation** (added to prevent scripted spam):
  - Constants at the top of the class: `CREATE_RATE_LIMIT = 5` playlists per `CREATE_RATE_WINDOW = 60` seconds (sliding window via a per-user transient), and a hard cap `MAX_PLAYLISTS_PER_USER = 200`
  - The hard cap is checked first via a direct DB count; the rate-limit transient only increments after a successful insert, so failed attempts are never counted against the user

### User Menu & Frontend Account Access (`class-cf-user-menu.php`)
- Renders the logged-in/logged-out account menu, injects it into nav, blocks non-admin users from `wp-admin`, and hides the admin bar on the frontend for regular members

### Donations (`class-cf-donations.php`)
- PayPal order creation and capture (`handle_create_order`, `handle_capture_order`), donation activity logging, and a REST webhook route (`cf-auth/v1/paypal-webhook`)
- Webhook signature is verified server-side against PayPal's `verify-webhook-signature` API before any donation is marked completed — failed verification attempts are logged via `CF_Activity_Log`

### Data Migration (`class-cf-migration.php`)
- One-time migration of legacy favorites data into the current favorites structure

### Shared Confirmation Modal (Frontend, replacing native `confirm()`)
- `assets/js/cf-auth.js`: a single reusable `cfConfirm(options)` function (Promise-based) replaces the three user-facing native browser confirm dialogs — sign out, delete playlist, delete account
- Supports title/message/confirm-text/cancel-text/danger styling; dismissible via Cancel, clicking the backdrop, or Escape; danger actions (delete playlist, delete account) use a red/danger button style
- Styled via `.cf-modal` rules in `assets/css/cf-auth.css` (dark theme, matches the site) — kept separate from the unrelated `.cf-modal` rules in `assets/css/cf-admin.css`, which power the light-themed WP-admin member management modals and were intentionally left untouched
- Admin-side `confirm()` dialogs in `assets/js/cf-admin.js` (suspend/activate member, delete member) remain native browser confirms by design — this change was scoped to the user-facing frontend only

### Orphan Data Cleanup on User Deletion
- `cleanup_user_on_delete()` in `class-cf-core.php`, hooked to `delete_user`, removes orphaned rows/meta (social connections, email/reset tokens, activity log entries, and related user meta) so a deleted user's email can be re-registered cleanly — verified working end-to-end

### Activity Logging
- `class-cf-activity-log.php` tracks login attempts, registrations, and failures with reasons; also used by the donations webhook to log verification failures
- Singleton pattern (`get_instance()` / private `__construct()`) aligned with other CF classes; bootstrapped from `cf_auth_init()`

### Xfinity Engagement, Referrals & Milestones
- Currency **"Xfinity"** tracked via append-only ledger table `cf_xfinity_ledger` + cached `cf_xfinity_balance` user meta (`class-cf-xfinity.php`)
- Earn rates (constants): listening 0.1/min, reading 0.05/min, referral 5+5 (referrer + new user)
- Engagement pings (`class-cf-engagement-tracker.php` + `assets/js/cf-engagement-tracker.js`): AJAX `cf_track_listening_ping` / `cf_track_reading_ping` every 60s; server anti-cheat rate-limits to 1 ping per 55s per user+activity; reading requires scroll/mousemove/keydown activity in the window
- Referrals (`class-cf-referral.php`): unique 8-char code per user, `?ref=` cookie (30 days), pending row on signup, confirm on email verify or first engagement; same-IP referrer/referred → `flagged_fake` (no award)
- Milestones at 1000 / 5000 / 10000 (filterable via `cf_xfinity_milestone_thresholds`): insert `pending_review` only — admin sends rewards manually (no auto-send)
- DB tables created via `CF_Install` dbDelta upgrade (`DB_VERSION` 5): `cf_xfinity_ledger`, `cf_engagement_sessions`, `cf_referrals`, `cf_referral_codes`, `cf_xfinity_milestones`

### Hook Registration Fixes (v2.0.2-fix1)
- Removed nested `wp_delete_user()` / re-`add_action( 'delete_user' )` inside `cleanup_user_on_delete()` — cleanup stays registered once in `register_hooks()` and runs a single time when WordPress fires `delete_user`
- Removed duplicate `wp_ajax_cf_logout` / `wp_ajax_nopriv_cf_logout` registrations from `CF_User_Menu`; logout is handled only by `CF_Login::handle_logout()`

## Known Pending Items
- Admin bulk email sending is still deferred — occasional emails are currently sent manually via the hosting provider's email panel
- No rate limiting yet on playlist item add/remove or rename (only playlist *creation* is rate-limited); not flagged as urgent, revisit if abuse patterns appear

## Future Features (Planned, Not Yet Built)
- **Real-time notifications** (WebSocket/push) — current system is polling-based
- **Messages system**: admin-to-user direct messaging, data layer exists but not exposed in the current UI
- **Admin email broadcast tool**: dashboard page to compose/send templated emails (with attachments) to individual or grouped users

## Working Rules for This Project
- Plugin and Theme are separate repos, developed in separate Cursor windows
- Never reference Theme files/paths inside Plugin work, and vice versa
- Update this log whenever a feature is completed or a new one is planned