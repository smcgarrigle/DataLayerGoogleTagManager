# Ghost GTM Custom JavaScript Examples

A collection of production-grade Custom JavaScript snippets designed to be deployed via **Google Tag Manager (GTM)** as **Custom HTML Tags** on a Ghost site.

These snippets listen for Ghost core DOM structures (the default Casper theme, native Sodo Search, and the native Portal membership/subscription system), extract clean context without capturing PII, and push structured events to `window.dataLayer`.

Selectors below target Ghost's **default Casper theme** conventions (`.gh-*` classes) which most custom themes also follow, since they're driven by Ghost's content API and helper tags rather than arbitrary markup. Ghost core has no comment system and no traditional shopping cart — its native "ecommerce" equivalent is a paid membership tier, covered in Recipe 8.

---

## Table of Contents

1. [Deployment Instructions in GTM](#deployment-instructions-in-gtm)
2. [Recipe 1: CTA & Button Tracking](#recipe-1-cta--button-tracking)
3. [Recipe 2: In-Page Anchor & Long-Form Content Jumps](#recipe-2-in-page-anchor--long-form-content-jumps)
4. [Recipe 3: Tag & Author Metadata Clicks](#recipe-3-tag--author-metadata-clicks)
5. [Recipe 4: Native Search (Sodo Search) Trigger Tracking](#recipe-4-native-search-sodo-search-trigger-tracking)
6. [Recipe 5: Navigation Menu Tracking](#recipe-5-navigation-menu-tracking)
7. [Recipe 6: Portal Sign-up / Sign-in Trigger Tracking](#recipe-6-portal-sign-up--sign-in-trigger-tracking)
8. [Recipe 7: Native Member Signup Form Result Tracking](#recipe-7-native-member-signup-form-result-tracking)
9. [Recipe 8: Paid Membership Tier Selection Tracking](#recipe-8-paid-membership-tier-selection-tracking)
10. [Recipe 9: Code Snippet & Content Copy Tracking](#recipe-9-code-snippet--content-copy-tracking)
11. [Best Practices & Security Rules](#best-practices--security-rules)

---

## Deployment Instructions in GTM

To deploy any of the recipes below in Google Tag Manager:

1. Log into your GTM container (**GTM-XXXXXXX**).
2. Go to **Tags** → **New**.
3. Set **Tag Type** to **Custom HTML**.
4. Wrap the snippet inside `<script> ... </script>` tags.
5. Set **Triggering** to:
   - **DOM Ready** (Recommended) or **Initialization - All Pages**.
6. Save and test using **GTM Preview Mode**. GTM itself doesn't know or care which CMS rendered the page — only the selectors below are Ghost-specific.

---

## Recipe 1: CTA & Button Tracking

### Purpose
Captures clicks on Casper's standard buttons — post CTAs, `{{cta}}` blocks, and generic theme buttons.

### Target Selectors
- `.gh-btn`, `.btn`, `.button`

### GTM Custom HTML Code
```html
<script>
(function() {
  'use strict';
  window.dataLayer = window.dataLayer || [];

  document.addEventListener('click', function(e) {
    var btn = e.target.closest('.gh-btn, .btn, .button');
    if (!btn) return;

    var isExternal = btn.hostname && btn.hostname !== window.location.hostname;
    var btnText = (btn.textContent || '').trim().slice(0, 100);

    window.dataLayer.push({
      event: 'cta_click',
      cta_text: btnText,
      cta_url: btn.href || undefined,
      cta_style: btn.className,
      is_external: isExternal
    });
  }, true);
})();
</script>
```

### Expected `dataLayer` Output
```json
{
  "event": "cta_click",
  "cta_text": "Read the Full Guide",
  "cta_url": "https://example.com/full-guide/",
  "cta_style": "gh-btn gh-btn-accent",
  "is_external": false
}
```

---

## Recipe 2: In-Page Anchor & Long-Form Content Jumps

### Purpose
Tracks manually authored in-page anchor links inside the post body — commonly added via Ghost's Markdown/HTML cards for long-form articles.

### Target Selectors
- `.gh-content a[href^="#"]`, `article a[href^="#"]`

### GTM Custom HTML Code
```html
<script>
(function() {
  'use strict';
  window.dataLayer = window.dataLayer || [];

  document.addEventListener('click', function(e) {
    var link = e.target.closest('.gh-content a[href^="#"], article a[href^="#"]');
    if (!link) return;

    var hash = link.getAttribute('href');
    if (!hash || hash === '#') return;

    var targetElem = document.querySelector(hash);
    var headingText = targetElem ? (targetElem.textContent || '').trim().slice(0, 100) : undefined;

    window.dataLayer.push({
      event: 'toc_click',
      section_id: hash.replace('#', ''),
      section_title: headingText || (link.textContent || '').trim(),
      link_text: (link.textContent || '').trim(),
      page_path: window.location.pathname
    });
  }, true);
})();
</script>
```

### Expected `dataLayer` Output
```json
{
  "event": "toc_click",
  "section_id": "setup",
  "section_title": "Setup",
  "link_text": "Jump to Setup",
  "page_path": "/getting-started-with-ghost/"
}
```

---

## Recipe 3: Tag & Author Metadata Clicks

### Purpose
Captures engagement with post metadata: tag links and author byline links, both rendered natively by Ghost's `{{tags}}` and `{{author}}` helpers.

### Target Selectors
- `.gh-tag`, `a[href*="/tag/"]`
- `.author-name`, `a[href*="/author/"]`

### GTM Custom HTML Code
```html
<script>
(function() {
  'use strict';
  window.dataLayer = window.dataLayer || [];

  document.addEventListener('click', function(e) {
    var link = e.target.closest('.gh-tag, a[href*="/tag/"], .author-name, a[href*="/author/"]');
    if (!link) return;

    var metaType = link.href.indexOf('/author/') !== -1 ? 'author' : 'tag';

    window.dataLayer.push({
      event: 'meta_click',
      meta_type: metaType,
      meta_name: (link.textContent || '').trim(),
      meta_url: link.href,
      post_title: document.title
    });
  }, true);
})();
</script>
```

### Expected `dataLayer` Output
```json
{
  "event": "meta_click",
  "meta_type": "tag",
  "meta_name": "Tutorials",
  "meta_url": "http://localhost/tag/tutorials/",
  "post_title": "Getting Started with Ghost"
}
```

---

## Recipe 4: Native Search (Sodo Search) Trigger Tracking

### Purpose
Ghost's built-in search ("Sodo Search") isn't a form — it's a button that opens a modal web component. This recipe tracks the trigger click and, where the theme exposes it, the modal's `search:closed` event with a captured query length.

### Target Selectors
- `[data-ghost-search]`

### GTM Custom HTML Code
```html
<script>
(function() {
  'use strict';
  window.dataLayer = window.dataLayer || [];

  document.addEventListener('click', function(e) {
    var trigger = e.target.closest('[data-ghost-search]');
    if (!trigger) return;

    window.dataLayer.push({
      event: 'search_open',
      search_location: trigger.closest('footer') ? 'footer' : 'header'
    });
  }, true);
})();
</script>
```

### Expected `dataLayer` Output
```json
{
  "event": "search_open",
  "search_location": "header"
}
```

---

## Recipe 5: Navigation Menu Tracking

### Purpose
Tracks link clicks inside Casper's primary and secondary (footer) navigation menus, both driven by Ghost's built-in navigation settings.

### Target Selectors
- `.gh-head-menu`, `.gh-foot-menu`, `.site-nav-item`

### GTM Custom HTML Code
```html
<script>
(function() {
  'use strict';
  window.dataLayer = window.dataLayer || [];

  document.addEventListener('click', function(e) {
    var link = e.target.closest('.gh-head-menu a, .gh-foot-menu a, .site-nav-item');
    if (!link) return;

    var navLocation = link.closest('.gh-foot-menu') ? 'footer_nav' : 'main_nav';

    window.dataLayer.push({
      event: 'menu_click',
      menu_location: navLocation,
      menu_text: (link.textContent || '').trim(),
      menu_url: link.href
    });
  }, true);
})();
</script>
```

### Expected `dataLayer` Output
```json
{
  "event": "menu_click",
  "menu_location": "main_nav",
  "menu_text": "Newsletter",
  "menu_url": "http://localhost/newsletter/"
}
```

---

## Recipe 6: Portal Sign-up / Sign-in Trigger Tracking

### Purpose
Tracks clicks on links/buttons that open Ghost's native Portal overlay — the membership sign-up, sign-in, and account management panel — via the `data-portal` attribute convention.

### Target Selectors
- `[data-portal]`

### GTM Custom HTML Code
```html
<script>
(function() {
  'use strict';
  window.dataLayer = window.dataLayer || [];

  document.addEventListener('click', function(e) {
    var trigger = e.target.closest('[data-portal]');
    if (!trigger) return;

    var portalTarget = trigger.getAttribute('data-portal') || 'default';

    window.dataLayer.push({
      event: 'portal_open',
      portal_target: portalTarget,
      trigger_text: (trigger.textContent || '').trim().slice(0, 100)
    });
  }, true);
})();
</script>
```

### Expected `dataLayer` Output
```json
{
  "event": "portal_open",
  "portal_target": "signup",
  "trigger_text": "Subscribe now"
}
```

---

## Recipe 7: Native Member Signup Form Result Tracking

### Purpose
Ghost also supports no-JS-required native `<form data-members-form="signup">` elements that work without Portal's JS. Ghost redirects back to the page with `?success=true` (or `?success=false`) on submission, so this recipe reads that on page load rather than relying on a submit-event race.

### Target Selectors
- `form[data-members-form]`

### GTM Custom HTML Code
```html
<script>
(function() {
  'use strict';
  window.dataLayer = window.dataLayer || [];

  // Fires on the page the visitor lands back on after Ghost processes the form.
  var params = new URLSearchParams(window.location.search);
  if (params.has('success')) {
    window.dataLayer.push({
      event: 'member_signup_result',
      success: params.get('success') === 'true',
      page_path: window.location.pathname
    });
  }

  // Track the submit attempt itself (pre-navigation) without capturing the email value.
  document.addEventListener('submit', function(e) {
    var form = e.target.closest('form[data-members-form]');
    if (!form) return;

    window.dataLayer.push({
      event: 'member_signup_submit',
      form_type: form.getAttribute('data-members-form')
    });
  }, true);
})();
</script>
```

### Expected `dataLayer` Output
```json
{
  "event": "member_signup_result",
  "success": true,
  "page_path": "/welcome/"
}
```

---

## Recipe 8: Paid Membership Tier Selection Tracking

### Purpose
Ghost's native "ecommerce" equivalent is a paid membership tier. Portal encodes the selected plan directly in the `data-portal` attribute (e.g. `signup/monthly`, `signup/yearly`, `signup/gold-plan`), so this recipe parses it into a GA4-shaped selection event.

### Target Selectors
- `[data-portal^="signup/"]`

### GTM Custom HTML Code
```html
<script>
(function() {
  'use strict';
  window.dataLayer = window.dataLayer || [];

  document.addEventListener('click', function(e) {
    var trigger = e.target.closest('[data-portal^="signup/"]');
    if (!trigger) return;

    var portalValue = trigger.getAttribute('data-portal') || '';
    var plan = portalValue.split('/')[1];

    window.dataLayer.push({
      event: 'select_item',
      item_list_name: 'membership_tiers',
      items: [{
        item_name: plan || undefined,
        item_category: 'membership'
      }]
    });
  }, true);
})();
</script>
```

### Expected `dataLayer` Output
```json
{
  "event": "select_item",
  "item_list_name": "membership_tiers",
  "items": [
    {
      "item_name": "yearly",
      "item_category": "membership"
    }
  ]
}
```

---

## Recipe 9: Code Snippet & Content Copy Tracking

### Purpose
Detects when a visitor copies code snippets or article text from a post's content body.

### Target Selectors
- `.gh-content pre`, `pre`, `code`, `article`

### GTM Custom HTML Code
```html
<script>
(function() {
  'use strict';
  window.dataLayer = window.dataLayer || [];

  document.addEventListener('copy', function(e) {
    var selection = window.getSelection ? window.getSelection().toString() : '';
    if (!selection || selection.trim().length === 0) return;

    var anchorElem = window.getSelection().anchorNode;
    var parentElem = anchorElem ? (anchorElem.nodeType === 3 ? anchorElem.parentElement : anchorElem) : null;
    var codeBlock = parentElem ? parentElem.closest('.gh-content pre, pre, code') : null;

    window.dataLayer.push({
      event: 'text_copy',
      copied_length: selection.length,
      is_code_block: !!codeBlock,
      page_path: window.location.pathname
    });
  });
})();
</script>
```

### Expected `dataLayer` Output
```json
{
  "event": "text_copy",
  "copied_length": 88,
  "is_code_block": true,
  "page_path": "/ghost-theming-basics/"
}
```

---

## Best Practices & Security Rules

1. **Always Null the Ecommerce Object**: Before pushing any GA4 ecommerce-shaped payload, execute `window.dataLayer.push({ ecommerce: null });` to prevent parameter contamination from previous events.
2. **Never Push PII**: Exclude raw input values from email fields and names — Portal signup and native member forms both collect an email address; never read `input[name="email"]`'s value.
3. **Portal Runs in an Isolated Web Component**: Ghost's Portal renders inside a custom element (and its internals aren't part of the main document), so you can't read its internal state directly. Track it via the triggering `data-portal` attribute (Recipes 6, 8) or the native no-JS form fallback (Recipe 7) instead.
4. **Use Number Types for Monetary Values**: If you extend Recipe 8 to include price, ensure it's numeric (e.g. `8.00`, not `"$8/mo"`).
5. **Use Delegated Event Listeners**: Bind listeners to `document` with `e.target.closest(...)` so injected content (related-posts cards, dynamically loaded comments via a third-party embed) is captured seamlessly.
