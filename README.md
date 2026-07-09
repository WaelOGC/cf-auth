# CF Auth — Collective Finity Authentication Plugin
**Version:** 2.0.1  
**Author:** Collective Finity  
**Requires:** WordPress 6.0+, PHP 7.4+

---

## 📦 Installation

1. Upload the `cf-auth` folder to `/wp-content/plugins/`
2. Activate in **WordPress → Plugins**
3. The plugin auto-creates these pages with shortcodes:
   - `/cf-login` — Login page
   - `/cf-register` — Register page
   - `/cf-forgot-password` — Forgot password
   - `/cf-reset-password` — Password reset
   - `/cf-profile` — User profile
   - `/cf-verify-email` — Email verification

---

## ⚙️ Configuration

Go to **WordPress Admin → CF Auth → Settings**

### General Settings
| Setting | Description |
|---------|-------------|
| Login Redirect | Where to send users after login (default: `/cf-profile`) |
| Logout Redirect | Where to send users after logout (default: home) |
| After Register | Where to send users after registration (default: `/cf-verify-email`) |
| Email Verification | Require email verification before login |

---

## 🔐 Social Auth Setup

### Google OAuth
1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a project → Enable **Google+ API** or **Google Identity**
3. Create **OAuth 2.0 Credentials** (Web Application)
4. Add Authorized redirect URI:
   ```
   https://yourdomain.com/cf-login?cf_oauth=google
   ```
5. Copy **Client ID** and **Client Secret** → CF Auth Settings → Social Auth

### Facebook App
1. Go to [Meta Developers](https://developers.facebook.com/)
2. Create App → Add **Facebook Login** product
3. Add Valid OAuth Redirect URI:
   ```
   https://yourdomain.com/cf-login?cf_oauth=facebook
   ```
4. Copy **App ID** and **App Secret** → CF Auth Settings → Social Auth

### Discord OAuth
1. Go to [Discord Developer Portal](https://discord.com/developers/applications)
2. Create Application → OAuth2 section
3. Add Redirect:
   ```
   https://yourdomain.com/cf-login?cf_oauth=discord
   ```
4. Copy **Client ID** and **Client Secret** → CF Auth Settings → Social Auth

### X / Twitter OAuth 2.0
1. Go to [Twitter Developer Portal](https://developer.twitter.com/)
2. Create Project + App → Enable **OAuth 2.0**
3. Set Type to **Web App** (for PKCE)
4. Add Callback URI:
   ```
   https://yourdomain.com/cf-login?cf_oauth=twitter
   ```
5. Request scopes: `tweet.read users.read offline.access`
6. Copy **Client ID (API Key)** and **Client Secret** → CF Auth Settings → Social Auth

---

## 🎵 Shortcodes

| Shortcode | Page | Description |
|-----------|------|-------------|
| `[cf_login_form]` | Login | Full login form with social buttons |
| `[cf_register_form]` | Register | Registration form with social buttons |
| `[cf_forgot_password]` | Forgot Password | Request password reset email |
| `[cf_reset_password]` | Reset Password | Set new password via token |
| `[cf_user_profile]` | Profile | Full profile page with tabs |
| `[cf_verify_email]` | Verify Email | Email verification landing |

---

## 🎧 Integration with your Music Player

In your music player JavaScript, call these CF Auth APIs:

```javascript
// Log when user listens to a track
CF_Auth.logListening(trackId);

// Toggle favorite track
CF_Auth.toggleFavorite(trackId, 'track').then(result => {
    console.log(result.is_favorite); // true / false
});

// Toggle favorite album
CF_Auth.toggleFavorite(albumId, 'album');
```

---

## 🛡️ Admin Dashboard

**WordPress Admin → CF Auth**

| Page | Features |
|------|----------|
| Overview | Total members, active/pending stats, recent registrations, provider breakdown |
| Members | Full list with search/filter, suspend/activate, delete, resend verification |
| Settings | General, Social OAuth credentials, Email configuration |

---

## 🗃️ Database Tables Created

| Table | Purpose |
|-------|---------|
| `wp_cf_email_tokens` | Email verification tokens |
| `wp_cf_reset_tokens` | Password reset tokens |
| `wp_cf_social_connections` | Social OAuth provider links |
| `wp_cf_listening_history` | User listening history |

---

## 👤 User Meta Fields

| Meta Key | Description |
|----------|-------------|
| `cf_email_verified` | `1` = verified, `0` = pending |
| `cf_account_status` | `active`, `pending`, `suspended` |
| `cf_social_provider` | `manual`, `google`, `facebook`, `discord`, `twitter` |
| `cf_social_avatar` | Social provider avatar URL |
| `cf_avatar_url` | Custom uploaded avatar URL |
| `cf_bio` | User bio text |
| `cf_favorite_tracks` | Array of track post IDs |
| `cf_favorite_albums` | Array of album post IDs |
| `cf_member_since` | Registration date |
| `cf_last_active` | Last login date |

---

## 🔧 Fonts

The plugin uses the same fonts as Collective Finity:
- **Mulish** — Headings
- **Space Mono** — Body text

Make sure these are loaded by your theme (they likely are since they're already on your site).

---

## 🔜 Planned for v2

- Premium membership tier
- Exclusive content gating
- Member community features
- Email campaign integration
- Listening statistics dashboard
- Artist profile pages

---

## 📞 Support

This is a personal plugin for Collective Finity. For issues, review the code comments or check WordPress debug log (`wp-content/debug.log`).

Enable debug logging:
```php
// In wp-config.php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
```
