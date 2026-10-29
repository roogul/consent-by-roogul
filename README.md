# Consent by ROOGUL

Lightweight cookie consent for Craft 5. No scanner, no external service, no database tables. About 4 KB of JS.

All decisions happen in the browser, so pages are identical for every visitor and safe behind Blitz, Craft Cloud's edge cache or any CDN.

## Requirements

- Craft CMS 5.0+
- PHP 8.2+

## Installation

```bash
composer require roogul/consent-by-roogul
php craft plugin/install consent-by-roogul
```

## Setup

**1. Consent Mode defaults (if you use GTM or gtag.js).** Place this in `<head>`, *before* your GTM or gtag snippet:

```twig
{{ craft.consent.head() }}
```

**2. The banner** is injected before `</body>` automatically. To place it yourself, turn off *Auto-inject banner* and add:

```twig
{{ craft.consent.banner() }}
```

**3. Gate scripts** by changing their `type` and naming a category:

```html
<script type="text/plain" data-consent="analytics" src="https://example.com/analytics.js"></script>

<script type="text/plain" data-consent="marketing">
  // inline code runs once "marketing" is granted
</script>

<!-- ES modules -->
<script type="text/plain" data-type="module" data-consent="analytics" src="/js/tracking.js"></script>

<!-- Embeds -->
<iframe data-consent="marketing" data-src="https://www.youtube-nocookie.com/embed/…"></iframe>
```

Multiple categories are space-separated and *all* must be granted: `data-consent="analytics marketing"`.

If you load GTM with Consent Mode, GTM itself can load ungated and handle tag-level consent. Gate it instead if you want nothing Google-side to load before consent.

**4. Cookie policy page:**

```twig
{{ craft.consent.table() }}
```

Renders every declared cookie grouped by category, plus the consent cookie itself and a "change your settings" link.

**5. "Cookie settings" link** (footer etc.). Withdrawal must be as easy as giving consent:

```html
<a href="#" data-consent-open>Cookie settings</a>
```

## Declaring cookies

Add every cookie the site sets under **Settings → Plugins → Consent by ROOGUL**, including Craft's own (`CraftSessionId`, `CRAFT_CSRF_TOKEN`) under *Strictly necessary*. `*` works as a wildcard (`_ga_*`).

With `devMode` on, the browser console warns about any visible cookie that isn't declared.

## Re-consent

Bump **Consent version** whenever you add a cookie to an optional category or change what a category covers. Visitors with an older version see the banner again.

## Withdrawal

If a visitor removes a category they'd previously granted, the plugin deletes matching first-party cookies it can see and reloads the page, since scripts that already ran can't be unloaded. HttpOnly and third-party cookies can't be removed from JavaScript.

## JavaScript API

```js
Consent.has('analytics');      // boolean
Consent.get();                 // ['necessary', 'analytics']
Consent.open();                // open preferences
Consent.accept();
Consent.reject();
Consent.set(['analytics']);    // explicit set of optional categories

document.addEventListener('consent:change', (e) => {
  console.log(e.detail.categories, e.detail.revoked);
});
```

## Theming

**Settings → Plugins → Consent by ROOGUL → Theme** sets the background, text, secondary text, border, button and button-text colours, plus corner radius, with a live preview. Blank fields keep the defaults. *Follow visitor dark mode* controls whether blank fields switch to dark defaults on dark-mode devices.

For anything beyond that, override the CSS custom properties in your own stylesheet:

```css
.consent {
  --consent-bg: #0f0f0f;
  --consent-fg: #fff;
  --consent-btn-bg: #e4ff3c;
  --consent-btn-fg: #000;
  --consent-radius: 0;
  --consent-font: "Your Font", sans-serif;
}
```

## Multi-site

All copy goes through the `site` translation category. Add translations in `translations/<language>/site.php`, keyed by the English text in settings.

## Config file

Any setting can be overridden per environment in `config/consent-by-roogul.php`:

```php
<?php

return [
    '*' => ['consentMode' => true],
    'dev' => ['enabled' => false],
];
```

## Storage

One first-party cookie (default name `consent`, `SameSite=Lax`, `Secure` on HTTPS):

```json
{ "v": 1, "c": ["analytics"], "t": 1761742800000 }
```

`v` is the consent version, `c` the granted optional categories, `t` when the choice was made.
