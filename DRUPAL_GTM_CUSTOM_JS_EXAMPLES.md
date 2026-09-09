# Drupal GTM Custom JavaScript Examples

A collection of production-grade Custom JavaScript snippets designed to be deployed via **Google Tag Manager (GTM)** as **Custom HTML Tags** on a Drupal site.

These snippets listen for Drupal core DOM structures (Olivero/Claro theme regions, core Comment module, core Search, the Webform module, and Drupal Commerce), extract clean context without capturing PII, and push structured events to `window.dataLayer`.

Selectors below target Drupal **core** markup and the default Olivero/Claro themes. A heavily customized theme or a contrib module (e.g. a different form builder) may use different class names — inspect your own markup before deploying.

---

## Table of Contents

1. [Deployment Instructions in GTM](#deployment-instructions-in-gtm)
2. [Recipe 1: CTA & Button Tracking](#recipe-1-cta--button-tracking)
3. [Recipe 2: In-Page Anchor & Long-Form Content Jumps](#recipe-2-in-page-anchor--long-form-content-jumps)
4. [Recipe 3: Taxonomy Term & Author Metadata Clicks](#recipe-3-taxonomy-term--author-metadata-clicks)
5. [Recipe 4: Drupal Core Search Form Listener](#recipe-4-drupal-core-search-form-listener)
6. [Recipe 5: Main & Footer Navigation Menu Tracking](#recipe-5-main--footer-navigation-menu-tracking)
7. [Recipe 6: Comment & Reply Interactions](#recipe-6-comment--reply-interactions)
8. [Recipe 7: Webform AJAX Submission Listener](#recipe-7-webform-ajax-submission-listener)
9. [Recipe 8: Drupal Commerce Add-to-Cart Tracking](#recipe-8-drupal-commerce-add-to-cart-tracking)
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
6. Save and test using **GTM Preview Mode**. GTM itself doesn't know or care which CMS rendered the page — only the selectors below are Drupal-specific.

---

## Recipe 1: CTA & Button Tracking

### Purpose
Captures clicks on buttons rendered by Layout Builder, Views action links, and core Link-field CTAs across Olivero/Claro-based themes.

### Target Selectors
- `.button`, `.btn`, `a.button--primary`, `a.button--action`
- `.field--name-field-cta a`, `.action-link`

### GTM Custom HTML Code
```html
<script>
(function() {
  'use strict';
  window.dataLayer = window.dataLayer || [];

  document.addEventListener('click', function(e) {
    var btn = e.target.closest('.button, .btn, a.button--primary, a.button--action, .field--name-field-cta a, .action-link');
    if (!btn) return;

    var fieldWrapper = btn.closest('[class*="field--name-field-cta"], .block');
    var isExternal = btn.hostname && btn.hostname !== window.location.hostname;
    var btnText = (btn.textContent || '').trim().slice(0, 100);

    window.dataLayer.push({
      event: 'cta_click',
      cta_text: btnText,
      cta_url: btn.href || undefined,
      cta_style: btn.className,
      cta_region: fieldWrapper ? fieldWrapper.className : undefined,
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
  "cta_text": "Download the Report",
  "cta_url": "https://example.com/report.pdf",
  "cta_style": "button button--primary button--action",
  "cta_region": "field field--name-field-cta field--type-link",
  "is_external": true
}
```

---

## Recipe 2: In-Page Anchor & Long-Form Content Jumps

### Purpose
Drupal core has no built-in table-of-contents generator, so this tracks manually authored in-page anchor links inside the body field — a common pattern in long-form articles built with Layout Builder or CKEditor anchors.

### Target Selectors
- `.field--name-body a[href^="#"]`, `article a[href^="#"]`

### GTM Custom HTML Code
```html
<script>
(function() {
  'use strict';
  window.dataLayer = window.dataLayer || [];

  document.addEventListener('click', function(e) {
    var link = e.target.closest('.field--name-body a[href^="#"], article a[href^="#"]');
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
  "section_id": "installation",
  "section_title": "Installation",
  "link_text": "Jump to Installation",
  "page_path": "/blog/setting-up-drupal"
}
```

---

## Recipe 3: Taxonomy Term & Author Metadata Clicks

### Purpose
Captures engagement with node metadata: referenced taxonomy terms (tags/categories) and author reference links, without interrupting navigation.

### Target Selectors
- `.field--name-field-tags a`, `a[href*="/taxonomy/term/"]`
- `.field--name-uid a`, `.username`, `a[href*="/user/"]`

### GTM Custom HTML Code
```html
<script>
(function() {
  'use strict';
  window.dataLayer = window.dataLayer || [];

  document.addEventListener('click', function(e) {
    var link = e.target.closest('.field--name-field-tags a, a[href*="/taxonomy/term/"], .field--name-uid a, .username, a[href*="/user/"]');
    if (!link) return;

    var metaType = 'taxonomy';
    if (link.href.indexOf('/user/') !== -1 || link.closest('.field--name-uid')) {
      metaType = 'author';
    }

    window.dataLayer.push({
      event: 'meta_click',
      meta_type: metaType,
      meta_name: (link.textContent || '').trim(),
      meta_url: link.href,
      node_title: document.title
    });
  }, true);
})();
</script>
```

### Expected `dataLayer` Output
```json
{
  "event": "meta_click",
  "meta_type": "taxonomy",
  "meta_name": "Case Studies",
  "meta_url": "http://localhost/taxonomy/term/12",
  "node_title": "How We Migrated to Drupal 10"
}
```

---

## Recipe 4: Drupal Core Search Form Listener

### Purpose
Hooks into the core Search block (`#search-block-form`) to log search submissions before the results page navigation occurs.

### Target Selectors
- `#search-block-form`, `form[id^="search-block-form"]`, `input[name="keys"]`

### GTM Custom HTML Code
```html
<script>
(function() {
  'use strict';
  window.dataLayer = window.dataLayer || [];

  document.addEventListener('submit', function(e) {
    var form = e.target.closest('form[id^="search-block-form"], form');
    if (!form) return;

    var input = form.querySelector('input[name="keys"]');
    if (!input) return;

    var query = (input.value || '').trim();
    if (!query) return;

    var locationType = 'header';
    if (form.closest('footer, #block-olivero-footermenu')) locationType = 'footer';

    window.dataLayer.push({
      event: 'search_submit',
      search_term: query.slice(0, 100),
      search_length: query.length,
      search_location: locationType
    });
  }, true);
})();
</script>
```

### Expected `dataLayer` Output
```json
{
  "event": "search_submit",
  "search_term": "webform api",
  "search_length": 11,
  "search_location": "header"
}
```

---

## Recipe 5: Main & Footer Navigation Menu Tracking

### Purpose
Tracks link clicks inside the core main navigation and footer menu blocks, with hierarchy context for submenu items.

### Target Selectors
- `#block-olivero-main-menu`, `.main-menu`, `nav.navigation`, `#block-olivero-footermenu`

### GTM Custom HTML Code
```html
<script>
(function() {
  'use strict';
  window.dataLayer = window.dataLayer || [];

  document.addEventListener('click', function(e) {
    var link = e.target.closest('#block-olivero-main-menu a, .main-menu a, nav.navigation a, #block-olivero-footermenu a');
    if (!link) return;

    var navLocation = 'main_nav';
    if (link.closest('#block-olivero-footermenu, footer')) navLocation = 'footer_nav';
    else if (link.closest('.mobile-nav, [data-drupal-selector="mobile-nav"]')) navLocation = 'mobile_nav';

    var parentLi = link.closest('li');
    var isSubmenu = parentLi && parentLi.parentElement && parentLi.parentElement.closest('ul ul');

    window.dataLayer.push({
      event: 'menu_click',
      menu_location: navLocation,
      menu_text: (link.textContent || '').trim(),
      menu_url: link.href,
      is_submenu: !!isSubmenu
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
  "menu_text": "Solutions",
  "menu_url": "http://localhost/solutions",
  "is_submenu": false
}
```

---

## Recipe 6: Comment & Reply Interactions

### Purpose
Tracks engagement with the core Comment module — reply link clicks and comment form submissions — **without capturing PII** (name, email, or message body are excluded).

### Target Selectors
- `.comment-reply a`, `a.comment-reply`
- `.comment-form`, `form[id^="comment-form"]`

### GTM Custom HTML Code
```html
<script>
(function() {
  'use strict';
  window.dataLayer = window.dataLayer || [];

  // Track Reply link clicks
  document.addEventListener('click', function(e) {
    var replyBtn = e.target.closest('.comment-reply a, a.comment-reply');
    if (!replyBtn) return;

    var commentElem = replyBtn.closest('.comment, article.comment');
    var parentCommentId = commentElem ? commentElem.id : undefined;

    window.dataLayer.push({
      event: 'comment_reply_click',
      parent_comment_id: parentCommentId
    });
  }, true);

  // Track Comment Submissions (no PII pushed)
  document.addEventListener('submit', function(e) {
    var form = e.target.closest('.comment-form, form[id^="comment-form"]');
    if (!form) return;

    var commentBox = form.querySelector('textarea[name="comment_body[0][value]"]');
    var msgLen = commentBox ? (commentBox.value || '').length : 0;

    window.dataLayer.push({
      event: 'comment_submit',
      comment_length: msgLen,
      has_parent: !!form.querySelector('input[name="pid"]')?.value
    });
  }, true);
})();
</script>
```

### Expected `dataLayer` Output
```json
{
  "event": "comment_submit",
  "comment_length": 118,
  "has_parent": false
}
```

---

## Recipe 7: Webform AJAX Submission Listener

### Purpose
Drupal's Webform module doesn't dispatch a plain custom DOM event on success the way WordPress form plugins do — its AJAX handler swaps the form markup for a `.webform-confirmation-message` node in place. This recipe watches for that swap with a `MutationObserver` so lead conversions are captured reliably regardless of confirmation type (inline message, modal, or redirect handled elsewhere).

### Target Selectors
- `.webform-submission-form`, `.webform-confirmation-message`

### GTM Custom HTML Code
```html
<script>
(function() {
  'use strict';
  window.dataLayer = window.dataLayer || [];

  var observer = new MutationObserver(function(mutations) {
    mutations.forEach(function(mutation) {
      mutation.addedNodes.forEach(function(node) {
        if (node.nodeType !== 1) return;
        var confirmation = node.classList && node.classList.contains('webform-confirmation-message')
          ? node
          : node.querySelector && node.querySelector('.webform-confirmation-message');
        if (!confirmation) return;

        var wrapper = confirmation.closest('[data-webform-id]');
        window.dataLayer.push({
          event: 'generate_lead',
          form_plugin: 'webform',
          form_id: wrapper ? wrapper.getAttribute('data-webform-id') : undefined
        });
      });
    });
  });

  observer.observe(document.body, { childList: true, subtree: true });
})();
</script>
```

### Expected `dataLayer` Output
```json
{
  "event": "generate_lead",
  "form_plugin": "webform",
  "form_id": "contact"
}
```

---

## Recipe 8: Drupal Commerce Add-to-Cart Tracking

### Purpose
Captures add-to-cart submissions on standard Drupal Commerce product pages, pushing a GA4-shaped ecommerce payload.

### Target Selectors
- `.commerce-order-item-add-to-cart-form`, `form[id^="commerce-order-item-add-to-cart-form"]`

### GTM Custom HTML Code
```html
<script>
(function() {
  'use strict';
  window.dataLayer = window.dataLayer || [];

  document.addEventListener('submit', function(e) {
    var form = e.target.closest('.commerce-order-item-add-to-cart-form, form[id^="commerce-order-item-add-to-cart-form"]');
    if (!form) return;

    var productContainer = form.closest('.commerce-product-variation-add-to-cart-form, article') || document;
    var productId = form.querySelector('input[name="purchased_entity[0][variation]"]');
    var titleElem = document.querySelector('.field--name-title, h1.page-title');
    var priceElem = productContainer.querySelector('.field--name-price .field__item, .price');
    var qtyElem = form.querySelector('input[name="quantity[0][value]"]');

    var rawPrice = priceElem ? priceElem.textContent.replace(/[^0-9.]/g, '') : '0';
    var price = parseFloat(rawPrice) || 0;
    var qty = qtyElem ? parseInt(qtyElem.value, 10) || 1 : 1;

    window.dataLayer.push({ ecommerce: null });
    window.dataLayer.push({
      event: 'add_to_cart',
      ecommerce: {
        currency: 'USD',
        value: price * qty,
        items: [{
          item_id: productId ? productId.value : undefined,
          item_name: titleElem ? titleElem.textContent.trim() : undefined,
          price: price,
          quantity: qty
        }]
      }
    });
  }, true);
})();
</script>
```

### Expected `dataLayer` Output
```json
{
  "event": "add_to_cart",
  "ecommerce": {
    "currency": "USD",
    "value": 49.98,
    "items": [
      {
        "item_id": "17",
        "item_name": "Drupal Mug",
        "price": 24.99,
        "quantity": 2
      }
    ]
  }
}
```

---

## Recipe 9: Code Snippet & Content Copy Tracking

### Purpose
Detects when a visitor copies code snippets or article text from a node's body field.

### Target Selectors
- `.field--name-body pre`, `pre`, `code`, `article`

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
    var codeBlock = parentElem ? parentElem.closest('.field--name-body pre, pre, code') : null;

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
  "copied_length": 96,
  "is_code_block": true,
  "page_path": "/blog/theming-with-twig"
}
```

---

## Best Practices & Security Rules

1. **Always Null the Ecommerce Object**: Before pushing any GA4 ecommerce payload, execute `window.dataLayer.push({ ecommerce: null });` to prevent parameter contamination from previous events.
2. **Never Push PII**: Exclude raw input values from text fields, email addresses, names, and phone numbers. Measure length, validation state, field name, or length buckets (`value_length_bucket`).
3. **Use Delegated Event Listeners (and a MutationObserver where needed)**: Drupal's Ajax Framework and BigPipe both swap DOM after page load, so bind listeners to `document`/`window` with `e.target.closest(...)`, and fall back to `MutationObserver` for modules (like Webform) that don't fire a plain custom event.
4. **Use Number Types for Monetary Values**: Ensure `price` and `value` fields in ecommerce pushes are numeric (e.g. `24.50`, not `"24.50"`).
5. **Check Your Theme's Markup First**: Class names above match Olivero/Claro core defaults — a custom theme or a contrib module (Views, Paragraphs, a different commerce cart widget) can rename them. Inspect the live DOM before deploying.
