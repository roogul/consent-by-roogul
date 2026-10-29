/*!
 * Consent by ROOGUL — client runtime.
 * All decisions happen here so pages stay identical for every visitor (cache-safe).
 *
 * Gate scripts:   <script type="text/plain" data-consent="analytics" src="…"></script>
 * Gate iframes:   <iframe data-consent="marketing" data-src="…"></iframe>
 * Re-open prefs:  <a href="#" data-consent-open>Cookie settings</a>
 * JS API:         window.Consent.has('analytics'), .get(), .open(), .accept(), .reject(), .set([...])
 * Event:          document.addEventListener('consent:change', e => e.detail.categories)
 */
(() => {
  'use strict';

  const cfgEl = document.getElementById('consent-config');
  if (!cfgEl) return;

  const cfg = JSON.parse(cfgEl.textContent);
  const root = document.getElementById('consent');
  const banner = root && root.querySelector('[data-consent-banner]');
  const dialog = root && root.querySelector('[data-consent-dialog]');

  const required = cfg.categories.filter((c) => c.required).map((c) => c.handle);
  const optional = cfg.categories.filter((c) => !c.required).map((c) => c.handle);

  // Cookie names --------------------------------------------------------------

  const escapeRe = (s) => s.replace(/[.+?^${}()|[\]\\]/g, '\\$&');
  const toRe = (pattern) => new RegExp('^' + pattern.split('*').map(escapeRe).join('.*') + '$');
  const cookieNames = () =>
    document.cookie.split('; ').filter(Boolean).map((c) => c.split('=')[0]);

  // State ---------------------------------------------------------------------

  const read = () => {
    const m = document.cookie.match(new RegExp('(?:^|; )' + escapeRe(cfg.cookieName) + '=([^;]*)'));
    if (!m) return null;
    try {
      const s = JSON.parse(decodeURIComponent(m[1]));
      return s && s.v === cfg.version && Array.isArray(s.c) ? s : null;
    } catch (e) {
      return null;
    }
  };

  const write = (cats) => {
    const s = { v: cfg.version, c: cats, t: Date.now() };
    const expires = new Date(Date.now() + cfg.cookieDays * 864e5).toUTCString();
    const secure = location.protocol === 'https:' ? '; Secure' : '';
    document.cookie =
      `${cfg.cookieName}=${encodeURIComponent(JSON.stringify(s))}; expires=${expires}; path=/; SameSite=Lax${secure}`;
    return s;
  };

  let state = read();

  const has = (handle) => required.includes(handle) || (!!state && state.c.includes(handle));
  const allowed = (attr) => attr.trim().split(/\s+/).every(has);
  const granted = () => [...required, ...(state ? state.c : [])];

  // Effects -------------------------------------------------------------------

  const activate = () => {
    document.querySelectorAll('script[type="text/plain"][data-consent]').forEach((old) => {
      if (!allowed(old.dataset.consent)) return;
      const s = document.createElement('script');
      for (const a of old.attributes) {
        if (a.name === 'type' || a.name === 'data-consent' || a.name === 'data-type') continue;
        s.setAttribute(a.name, a.value);
      }
      if (old.dataset.type) s.type = old.dataset.type; // e.g. data-type="module"
      if (s.src && !old.hasAttribute('async')) s.async = false; // keep document order
      if (!s.src) s.text = old.text;
      old.replaceWith(s);
    });

    document.querySelectorAll('[data-consent][data-src]:not(script)').forEach((el) => {
      if (!allowed(el.dataset.consent)) return;
      el.src = el.dataset.src;
      el.removeAttribute('data-src');
    });
  };

  const gtagUpdate = () => {
    if (!cfg.consentMode || typeof window.gtag !== 'function') return;
    const u = {};
    cfg.categories.forEach((c) => c.gtag.forEach((g) => (u[g] = has(c.handle) ? 'granted' : 'denied')));
    if (Object.keys(u).length) window.gtag('consent', 'update', u);
  };

  // Best effort: removes first-party cookies we can see. HttpOnly and third-party
  // cookies can't be touched from JS, which is why revoking also reloads the page.
  const clearCookies = (cats) => {
    const patterns = cfg.cookies.filter((c) => cats.includes(c.category)).map((c) => toRe(c.name));
    if (!patterns.length) return;
    const parts = location.hostname.split('.');
    const domains = [''];
    for (let i = 0; i < parts.length - 1; i++) domains.push('; domain=.' + parts.slice(i).join('.'));
    cookieNames()
      .filter((n) => patterns.some((re) => re.test(n)))
      .forEach((n) =>
        domains.forEach((d) => (document.cookie = `${n}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/${d}`))
      );
  };

  // UI ------------------------------------------------------------------------

  const showBanner = () => banner && (banner.hidden = false);
  const hideBanner = () => banner && (banner.hidden = true);

  const openPrefs = () => {
    if (!dialog) return;
    dialog.querySelectorAll('input[name="consent-category"]').forEach((i) => {
      if (!i.disabled) i.checked = has(i.value);
    });
    dialog.showModal();
  };

  const apply = (cats) => {
    const previous = state ? state.c : [];
    const next = cats.filter((h) => optional.includes(h));
    state = write(next);

    const revoked = previous.filter((h) => !next.includes(h));

    gtagUpdate();
    hideBanner();
    if (dialog && dialog.open) dialog.close();

    document.dispatchEvent(
      new CustomEvent('consent:change', { detail: { categories: granted(), revoked } })
    );

    if (revoked.length) {
      // Scripts that already ran can't be unloaded — clean up and start fresh.
      clearCookies(revoked);
      location.reload();
      return;
    }

    activate();
  };

  document.addEventListener('click', (e) => {
    const t = e.target.closest('[data-consent-action], [data-consent-open]');
    if (!t) return;

    if (t.hasAttribute('data-consent-open')) {
      e.preventDefault();
      openPrefs();
      return;
    }

    switch (t.dataset.consentAction) {
      case 'accept':
        apply(optional);
        break;
      case 'reject':
        apply([]);
        break;
      case 'manage':
        openPrefs();
        break;
      case 'save':
        apply(
          [...dialog.querySelectorAll('input[name="consent-category"]:checked')]
            .filter((i) => !i.disabled)
            .map((i) => i.value)
        );
        break;
      case 'close':
        dialog.close();
        break;
    }
  });

  // Dev: flag cookies that aren't declared in settings ---------------------------

  if (cfg.dev) {
    const declared = [cfg.cookieName, ...cfg.cookies.map((c) => c.name)].map(toRe);
    const warned = new Set();
    const check = () =>
      cookieNames().forEach((n) => {
        if (warned.has(n) || declared.some((re) => re.test(n))) return;
        warned.add(n);
        console.warn(`[consent] Undeclared cookie "${n}" — add it under Settings → Plugins → Consent by ROOGUL.`);
      });
    check();
    setInterval(check, 5000);
  }

  // Boot ----------------------------------------------------------------------

  // First in tab order without stealing focus.
  if (root && document.body.firstElementChild !== root) document.body.prepend(root);

  window.Consent = {
    get: granted,
    has,
    open: openPrefs,
    accept: () => apply(optional),
    reject: () => apply([]),
    set: apply,
  };

  if (state) {
    gtagUpdate();
    activate();
  } else {
    showBanner();
  }
})();
