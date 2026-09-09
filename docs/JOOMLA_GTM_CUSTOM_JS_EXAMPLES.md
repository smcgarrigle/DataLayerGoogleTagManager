# Joomla GTM Custom JavaScript Examples

A collection of production-grade Custom JavaScript snippets designed to be deployed via **Google Tag Manager (GTM)** as **Custom HTML Tags** on a Joomla site.

These snippets listen for Joomla core DOM structures (`com_content` articles, the Cassiopeia template, core search, and the native print/email/PDF article tools), extract clean context without capturing PII, and push structured events to `window.dataLayer`.

Selectors below target Joomla **core** (`com_content`, `com_search`/`com_finder`) and the default Cassiopeia template. Joomla core ships no comment system and no shopping cart, so recipes 6 and 8 cover the nearest native/common-extension equivalents instead — check the notes on each.

---

## Table of Contents

1. [Deployment Instructions in GTM](#deployment-instructions-in-gtm)
2. [Recipe 1: CTA & Button Tracking](#recipe-1-cta--button-tracking)
3. [Recipe 2: In-Page Anchor & Read More Jumps](#recipe-2-in-page-anchor--read-more-jumps)
4. [Recipe 3: Category & Tag Metadata Clicks](#recipe-3-category--tag-metadata-clicks)
5. [Recipe 4: Site Search Form Listener](#recipe-4-site-search-form-listener)
6. [Recipe 5: Main & Footer Navigation Menu Tracking](#recipe-5-main--footer-navigation-menu-tracking)
7. [Recipe 6: Print, Email & PDF Article Tool Tracking](#recipe-6-print-email--pdf-article-tool-tracking)
8. [Recipe 7: Form Plugin Native Event Listener (RSForm!Pro, Contact)](#recipe-7-form-plugin-native-event-listener-rsformpro-contact)
9. [Recipe 8: Shopping Cart Add-to-Cart Tracking (VirtueMart / J2Store)](#recipe-8-shopping-cart-add-to-cart-tracking-virtuemart--j2store)
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
6. Save and test using **GTM Preview Mode**. GTM itself doesn't know or care which CMS rendered the page — only the selectors below are Joomla-specific.

---

## Recipe 1: CTA & Button Tracking

### Purpose
Captures clicks on Bootstrap-based buttons rendered by the Cassiopeia template, custom modules, and the article "Read more" control.

### Target Selectors
- `.btn`, `.button`, `.readmore a`

### GTM Custom HTML Code
```html
<script>
(function() {
  'use strict';
  window.dataLayer = window.dataLayer || [];

  document.addEventListener('click', function(e) {
    var btn = e.target.closest('.btn, .button, .readmore a');
    if (!btn) return;

    var moduleWrapper = btn.closest('.moduletable, .module');
    var isExternal = btn.hostname && btn.hostname !== window.location.hostname;
    var btnText = (btn.textContent || '').trim().slice(0, 100);

    window.dataLayer.push({
      event: 'cta_click',
      cta_text: btnText,
      cta_url: btn.href || undefined,
      cta_style: btn.className,
      cta_module: moduleWrapper ? moduleWrapper.className : undefined,
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
  "cta_text": "Get a Quote",
  "cta_url": "https://example.com/contact",
  "cta_style": "btn btn-primary",
  "cta_module": "moduletable moduletable-cta",
  "is_external": false
}
```

---

## Recipe 2: In-Page Anchor & Read More Jumps

### Purpose
Tracks manually authored in-page anchor links inside article body content, plus "Read more" expansion clicks on category/blog layouts.

### Target Selectors
- `.item-page a[href^="#"]`, `.blog a[href^="#"]`, `.readmore a`

### GTM Custom HTML Code
```html
<script>
(function() {
  'use strict';
  window.dataLayer = window.dataLayer || [];

  document.addEventListener('click', function(e) {
    var link = e.target.closest('.item-page a[href^="#"], .blog a[href^="#"]');
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
  "section_id": "pricing",
  "section_title": "Pricing",
  "link_text": "Jump to Pricing",
  "page_path": "/plans"
}
```

---

## Recipe 3: Category & Tag Metadata Clicks

### Purpose
Captures engagement with article metadata — category links from blog/category-blog layouts and `com_tags` tag links.

### Target Selectors
- `.category-name a`, `a[href*="/component/categories/"]`
- `a[href*="/component/tags/"]`, `.tag-links a`

### GTM Custom HTML Code
```html
<script>
(function() {
  'use strict';
  window.dataLayer = window.dataLayer || [];

  document.addEventListener('click', function(e) {
    var link = e.target.closest('.category-name a, a[href*="/component/categories/"], a[href*="/component/tags/"], .tag-links a');
    if (!link) return;

    var metaType = link.href.indexOf('/component/tags/') !== -1 ? 'tag' : 'category';

    window.dataLayer.push({
      event: 'meta_click',
      meta_type: metaType,
      meta_name: (link.textContent || '').trim(),
      meta_url: link.href,
      article_title: document.title
    });
  }, true);
})();
</script>
```

### Expected `dataLayer` Output
```json
{
  "event": "meta_click",
  "meta_type": "category",
  "meta_name": "Tutorials",
  "meta_url": "http://localhost/component/categories/tutorials",
  "article_title": "Getting Started with Joomla Templates"
}
```

---

## Recipe 4: Site Search Form Listener

### Purpose
Hooks into the core search module (`mod_search`) and Smart Search (`com_finder`) forms to log search submissions before the results page navigation occurs.

### Target Selectors
- `form.search`, `input[name="searchword"]`, `input[name="q"]`

### GTM Custom HTML Code
```html
<script>
(function() {
  'use strict';
  window.dataLayer = window.dataLayer || [];

  document.addEventListener('submit', function(e) {
    var form = e.target.closest('form.search, form');
    if (!form) return;

    var input = form.querySelector('input[name="searchword"], input[name="q"]');
    if (!input) return;

    var query = (input.value || '').trim();
    if (!query) return;

    var locationType = 'header';
    if (form.closest('footer')) locationType = 'footer';
    else if (form.closest('aside, .module-sidebar')) locationType = 'sidebar';

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
  "search_term": "gtm integration",
  "search_length": 16,
  "search_location": "header"
}
```

---

## Recipe 5: Main & Footer Navigation Menu Tracking

### Purpose
Tracks link clicks inside Cassiopeia's main navigation (`mod_menu`) and footer menu modules, with hierarchy context for submenu items.

### Target Selectors
- `#nav-main`, `.nav-menu`, `.navbar-nav`, `footer nav`

### GTM Custom HTML Code
```html
<script>
(function() {
  'use strict';
  window.dataLayer = window.dataLayer || [];

  document.addEventListener('click', function(e) {
    var link = e.target.closest('#nav-main a, .nav-menu a, .navbar-nav a, footer nav a');
    if (!link) return;

    var navLocation = 'main_nav';
    if (link.closest('footer')) navLocation = 'footer_nav';
    else if (link.closest('.offcanvas, .mobile-nav')) navLocation = 'mobile_nav';

    var parentLi = link.closest('li');
    var isSubmenu = parentLi && parentLi.parentElement && parentLi.parentElement.closest('ul ul, .dropdown-menu');

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
  "menu_text": "Services",
  "menu_url": "http://localhost/services",
  "is_submenu": false
}
```

---

## Recipe 6: Print, Email & PDF Article Tool Tracking

### Purpose
Joomla core ships no native comment system, so this recipe tracks the article tools Joomla *does* ship natively: the Print, Email-a-Friend, and PDF icons rendered by `com_content`'s `icons.php`.

### Target Selectors
- `a[href*="tmpl=component&print=1"]`, `.icons .print-icon a`
- `a[href*="option=com_mailto"]`, `.icons .email-icon a`
- `a[href*="format=pdf"]`, `.icons .pdf-icon a`

### GTM Custom HTML Code
```html
<script>
(function() {
  'use strict';
  window.dataLayer = window.dataLayer || [];

  document.addEventListener('click', function(e) {
    var link = e.target.closest('a[href*="tmpl=component&print=1"], a[href*="option=com_mailto"], a[href*="format=pdf"], .icons a');
    if (!link) return;

    var toolType = 'unknown';
    if (link.href.indexOf('print=1') !== -1) toolType = 'print';
    else if (link.href.indexOf('com_mailto') !== -1) toolType = 'email';
    else if (link.href.indexOf('format=pdf') !== -1) toolType = 'pdf';
    if (toolType === 'unknown') return;

    window.dataLayer.push({
      event: 'article_tool_click',
      tool_type: toolType,
      article_title: document.title,
      page_path: window.location.pathname
    });
  }, true);
})();
</script>
```

### Expected `dataLayer` Output
```json
{
  "event": "article_tool_click",
  "tool_type": "pdf",
  "article_title": "Migrating from Joomla 3 to 5",
  "page_path": "/blog/migrating-joomla"
}
```

---

## Recipe 7: Form Plugin Native Event Listener (RSForm!Pro, Contact)

### Purpose
Hooks into the core Contact form (`com_contact`) submit and the custom DOM event dispatched by RSForm!Pro (`rsform.submitted`), the most widely used open-source-adjacent Joomla form extension, to record lead conversions reliably.

### Events Handled
- `submit` on `com_contact`'s form (native AJAX-free fallback)
- `rsform.submitted` (RSForm!Pro)

### GTM Custom HTML Code
```html
<script>
(function() {
  'use strict';
  window.dataLayer = window.dataLayer || [];

  // 1. Core Contact component
  document.addEventListener('submit', function(e) {
    var form = e.target.closest('form.contact-form, #contact-form');
    if (!form) return;

    window.dataLayer.push({
      event: 'generate_lead',
      form_plugin: 'com_contact',
      form_id: form.id || undefined
    });
  }, true);

  // 2. RSForm!Pro
  document.addEventListener('rsform.submitted', function(e) {
    window.dataLayer.push({
      event: 'generate_lead',
      form_plugin: 'rsform_pro',
      form_id: e.detail ? e.detail.formId : undefined
    });
  }, false);
})();
</script>
```

### Expected `dataLayer` Output
```json
{
  "event": "generate_lead",
  "form_plugin": "rsform_pro",
  "form_id": 7
}
```

---

## Recipe 8: Shopping Cart Add-to-Cart Tracking (VirtueMart / J2Store)

### Purpose
Joomla core has no built-in shopping cart; VirtueMart and J2Store are the most common open-source options. Both render a recognizable "Add to Cart" submit button, which this recipe targets generically.

### Target Selectors
- `.addtocart-bar button[type="submit"]`, `.product-add-to-cart button`, `.add-to-cart`

### GTM Custom HTML Code
```html
<script>
(function() {
  'use strict';
  window.dataLayer = window.dataLayer || [];

  document.addEventListener('click', function(e) {
    var btn = e.target.closest('.addtocart-bar button[type="submit"], .product-add-to-cart button, .add-to-cart');
    if (!btn) return;

    var productContainer = btn.closest('.product, .product-details') || document;
    var titleElem = productContainer.querySelector('.product-title, h1.page-title, .item-title');
    var priceElem = productContainer.querySelector('.PricesalesPrice, .product-price, .price');
    var qtyElem = productContainer.querySelector('input[name="quantity"], input.quantity-input');

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
    "value": 39.99,
    "items": [
      {
        "item_name": "Joomla Hosting Plan",
        "price": 39.99,
        "quantity": 1
      }
    ]
  }
}
```

---

## Recipe 9: Code Snippet & Content Copy Tracking

### Purpose
Detects when a visitor copies code snippets or article text from `com_content` article bodies.

### Target Selectors
- `.item-page pre`, `pre`, `code`, `.item-page`

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
    var codeBlock = parentElem ? parentElem.closest('.item-page pre, pre, code') : null;

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
  "copied_length": 73,
  "is_code_block": false,
  "page_path": "/blog/joomla-templating-tips"
}
```

---

## Best Practices & Security Rules

1. **Always Null the Ecommerce Object**: Before pushing any GA4 ecommerce payload, execute `window.dataLayer.push({ ecommerce: null });` to prevent parameter contamination from previous events.
2. **Never Push PII**: Exclude raw input values from text fields, email addresses, names, and phone numbers. Measure length, validation state, field name, or length buckets (`value_length_bucket`).
3. **Use Delegated Event Listeners**: Always bind listeners to `document`/`window` with `e.target.closest(...)` so dynamically injected content (module chrome, AJAX-loaded modules, K2/VirtueMart widgets) is captured seamlessly.
4. **Use Number Types for Monetary Values**: Ensure `price` and `value` fields in ecommerce pushes are numeric (e.g. `24.50`, not `"24.50"`).
5. **Confirm Which Extensions Are Installed**: Joomla core ships no comments and no cart — recipes 6 and 8 above assume the most common open-source choices (native article tools, VirtueMart/J2Store). If the site uses a different extension (Komento, HikaShop, etc.), its markup and events will differ.
