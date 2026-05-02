# reCAPTCHA Verification Checklist

The Contacts form (and any other public form on the site) uses Google reCAPTCHA v3 for invisible bot scoring. This doc covers configuration + verification.

## Pre-flight

- [ ] reCAPTCHA v3 site key + secret key are issued for fishotel.com (production)
  - Site key: stored in FisHotel Settings → reCAPTCHA → Site Key
  - Secret key: stored in `wp-config.php` as `define('FH_RECAPTCHA_SECRET', '...')` (NOT in the database — keep it out of admin-facing settings)
- [ ] Staging keys are separate from production keys (Google's recaptcha admin lets you register multiple domains per key, but separate keys per env is cleaner)

## Theme integration (one-time code work — do this first if not already shipped)

- [ ] Form template enqueues `https://www.google.com/recaptcha/api.js?render={SITE_KEY}` only on pages that contain the contact form (conditional, not site-wide)
- [ ] Form has `<input type="hidden" name="fh_recaptcha_token" id="fh-recaptcha-token">` field
- [ ] On form submit, JS calls `grecaptcha.execute(SITE_KEY, {action: 'contact_submit'}).then(token => { document.getElementById('fh-recaptcha-token').value = token; form.submit(); })`
- [ ] Server handler validates token via POST to `https://www.google.com/recaptcha/api/siteverify` with secret + token; rejects submissions where `success: false` OR `score < 0.5`
- [ ] On rejection, show generic error ("Submission failed — please try again"), don't tip off bots that they were caught

## Verification on production

- [ ] Open https://fishotel.com/contacts/ in an incognito window
- [ ] Confirm `https://www.google.com/recaptcha/api.js` loads in DevTools Network tab
- [ ] Confirm the reCAPTCHA badge appears bottom-right on the page (or is suppressed via CSS with attribution per Google ToS)
- [ ] Fill out form with real values, submit → expect success message
- [ ] Check inbox: email arrives at the address configured in FisHotel Settings → Contacts Page → Email
- [ ] Check Google reCAPTCHA admin (https://www.google.com/recaptcha/admin) → site stats show 1+ requests in the last hour
- [ ] Verify scores trend high (>0.7) for legitimate submissions

## Negative test

- [ ] Try submitting with JS disabled → form submission falls back to no-token, server rejects with generic error
- [ ] Use a basic curl POST without a token → server rejects with generic error
- [ ] Manually post a known-bad token (e.g., expired) → server rejects with generic error

## Edge cases

- [ ] If reCAPTCHA service is down (Google outage), the form should still submit and pass through honeypot + time-based checks. Don't hard-block on reCAPTCHA failure — log + warn but allow through. (Or do hard-block; Jeff's call. Default recommendation: soft-block with a flag in admin.)
- [ ] Domain mismatch: if site key is registered for fishotel.com only, www.fishotel.com or staging URLs will fail. Make sure all domains are registered.

## Rollback

If reCAPTCHA causes legitimate users to be rejected (false positives), set `FH_RECAPTCHA_SECRET` to empty string in `wp-config.php`. Server handler should detect empty secret and skip the reCAPTCHA check entirely (falls back to honeypot + time-based only).
