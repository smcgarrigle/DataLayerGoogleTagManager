# WordPress GTM Custom JavaScript Examples

A collection of production-grade Custom JavaScript snippets designed to be deployed via **Google Tag Manager (GTM)** as **Custom HTML Tags**.

These snippets listen for generic WordPress DOM structures (Gutenberg block themes, classic WP themes, WooCommerce, and popular plugins), extract clean context without capturing PII, and push structured events to `window.dataLayer`.

---

## Table of Contents

1. [Deployment Instructions in GTM](#deployment-instructions-in-gtm)
2. [Recipe 1: Gutenberg & Theme Button Tracking](#recipe-1-gutenberg--theme-button-tracking)
3. [Recipe 2: Table of Contents & In-Page Anchor Jumps](#recipe-2-table-of-contents--in-page-anchor-jumps)
4. [Recipe 3: Author, Category & Tag Metadata Clicks](#recipe-3-author-category--tag-metadata-clicks)
5. [Recipe 4: WordPress Site Search Form Listener](#recipe-4-wordpress-site-search-form-listener)
6. [Recipe 5: Header, Footer & Navigation Menu Tracking](#recipe-5-header-footer--navigation-menu-tracking)
7. [Recipe 6: WordPress Comments & Reply Interactions](#recipe-6-wordpress-comments--reply-interactions)
8. [Recipe 7: Form Plugin Native Event Listener (CF7, WPForms, Gravity Forms)](#recipe-7-form-plugin-native-event-listener-cf7-wpforms-gravity-forms)
9. [Recipe 8: WooCommerce Generic Add-to-Cart & Gallery Tracking](#recipe-8-woocommerce-generic-add-to-cart--gallery-tracking)
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
6. Save and test using **GTM Preview Mode** and the dataLayer Lab inspector panel.

---

## Recipe 1: Gutenberg & Theme Button Tracking

### Purpose
Captures user clicks on WordPress buttons (`.wp-block-button`, `.btn`, `.button`, `.theme-button`) across Gutenberg block themes and traditional themes.

### Target Selectors
- `.wp-block-button__link`
- `.wp-block-button a`
- `.btn`, `.button`, `a.button`

### GTM Custom HTML Code
```html
<script>
(function() {
  'use strict';
  window.dataLayer = window.dataLayer || [];

  document.addEventListener('click', function(e) {
    var btn = e.target.closest('.wp-block-button__link, .wp-block-button a, .btn, .button, a.button');
    if (!btn) return;

    var parentBlock = btn.closest('.wp-block-button');
    var isExternal = btn.hostname && btn.hostname !== window.location.hostname;
    var btnText = (btn.textContent || '').trim().slice(0, 100);

    window.dataLayer.push({
      event: 'cta_click',
      cta_text: btnText,
      cta_url: btn.href || undefined,
      cta_style: parentBlock ? parentBlock.className : btn.className,
      is_external: isExternal,
      click_target: e.target.tagName.toLowerCase()
    });
  }, true);
})();
</script>
```

### Expected `dataLayer` Output
```json
{
  "event": "cta_click",
  "cta_text": "Download Guide",
  "cta_url": "https://example.com/guide.pdf",
  "cta_style": "wp-block-button is-style-outline",
  "is_external": true,
  "click_target": "span"
}
```

---

## Recipe 2: Table of Contents & In-Page Anchor Jumps

### Purpose
Tracks clicks on automatically generated Table of Contents links (`.toc`, `.wp-block-table-of-contents`, Easy TOC, Rank Math TOC) and in-page anchor links (`#heading-id`).

### Target Selectors
- `.toc a`, `.wp-block-table-of-contents a`, `.ez-toc-link`, `a[href^="#"]`

### GTM Custom HTML Code
```html
<script>
(function() {
  'use strict';
  window.dataLayer = window.dataLayer || [];

  document.addEventListener('click', function(e) {
    var link = e.target.closest('.toc a, .wp-block-table-of-contents a, .ez-toc-link, article a[href^="#"]');
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
  "section_id": "installation-guide",
  "section_title": "1. Installation Guide",
  "link_text": "1. Installation Guide",
  "page_path": "/wordpress-gtm-setup/"
}
```

---

## Recipe 3: Author, Category & Tag Metadata Clicks

### Purpose
Captures engagement with WordPress post metadata links (author bio links, category pills, tag links, and taxonomy archives) without interrupting navigation.

### Target Selectors
- `.author`, `[rel="author"]`, `.vcard a`
- `.cat-links a`, `.tags-links a`, `.entry-taxonomy a`, `[class*="taxonomy-"] a`

### GTM Custom HTML Code
```html
<script>
(function() {
  'use strict';
  window.dataLayer = window.dataLayer || [];

  document.addEventListener('click', function(e) {
    var link = e.target.closest('.author a, [rel="author"], .cat-links a, .tags-links a, .entry-taxonomy a, [class*="taxonomy-"] a');
    if (!link) return;

    var container = link.closest('.cat-links, .tags-links, .author, .entry-meta') || link.parentElement;
    var taxonomyType = 'meta';

    if (link.closest('.cat-links') || link.href.indexOf('/category/') !== -1) {
      taxonomyType = 'category';
    } else if (link.closest('.tags-links') || link.href.indexOf('/tag/') !== -1) {
      taxonomyType = 'tag';
    } else if (link.getAttribute('rel') === 'author' || link.href.indexOf('/author/') !== -1) {
      taxonomyType = 'author';
    }

    window.dataLayer.push({
      event: 'meta_click',
      meta_type: taxonomyType,
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
  "meta_type": "category",
  "meta_name": "Tutorials",
  "meta_url": "http://localhost:8888/category/tutorials/",
  "post_title": "Getting Started with GTM on WordPress"
}
```

---

## Recipe 4: WordPress Site Search Form Listener

### Purpose
Hooks into standard WordPress search forms (`form.search-form`, `input[name="s"]`) to log search attempts cleanly. Note: GA4 Enhanced Measurement captures `?s=` page views automatically, but this script logs the *submit event* before navigation occurs.

### Target Selectors
- `form.search-form`, `form[role="search"]`, `form:has(input[name="s"])`

### GTM Custom HTML Code
```html
<script>
(function() {
  'use strict';
  window.dataLayer = window.dataLayer || [];

  document.addEventListener('submit', function(e) {
    var form = e.target.closest('form.search-form, form[role="search"], form');
    if (!form) return;

    var input = form.querySelector('input[name="s"], input[type="search"]');
    if (!input) return;

    var query = (input.value || '').trim();
    if (!query) return;

    var locationType = 'header';
    if (form.closest('footer')) locationType = 'footer';
    else if (form.closest('aside, .widget')) locationType = 'sidebar';

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
  "search_term": "google tag manager",
  "search_length": 18,
  "search_location": "header"
}
```

---

## Recipe 5: Header, Footer & Navigation Menu Tracking

### Purpose
Tracks link clicks inside WordPress navigation menus, mobile drawer menus, and footer navigation blocks with hierarchy/depth context.

### Target Selectors
- `.nav-menu`, `.main-navigation`, `.wp-block-navigation`, `nav.menu`

### GTM Custom HTML Code
```html
<script>
(function() {
  'use strict';
  window.dataLayer = window.dataLayer || [];

  document.addEventListener('click', function(e) {
    var link = e.target.closest('.nav-menu a, .main-navigation a, .wp-block-navigation a, nav a');
    if (!link) return;

    var nav = link.closest('nav, .nav-menu, .main-navigation');
    var navLocation = 'main_nav';

    if (link.closest('footer')) navLocation = 'footer_nav';
    else if (link.closest('.mobile-menu, .drawer')) navLocation = 'mobile_nav';

    var parentLi = link.closest('li, .wp-block-navigation-item');
    var isSubmenu = parentLi && parentLi.parentElement.closest('ul, .wp-block-navigation__container');

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
  "menu_url": "http://localhost:8888/services/",
  "is_submenu": false
}
```

---

## Recipe 6: WordPress Comments & Reply Interactions

### Purpose
Tracks engagement with the native WordPress comment section (`#commentform`, `.comment-reply-link`) safely **without capturing PII** (comment author name, email, or message body are excluded).

### Target Selectors
- `.comment-reply-link`
- `#commentform`

### GTM Custom HTML Code
```html
<script>
(function() {
  'use strict';
  window.dataLayer = window.dataLayer || [];

  // Track Reply link clicks
  document.addEventListener('click', function(e) {
    var replyBtn = e.target.closest('.comment-reply-link');
    if (!replyBtn) return;

    var commentElem = replyBtn.closest('.comment, article');
    var parentCommentId = commentElem ? commentElem.id : undefined;

    window.dataLayer.push({
      event: 'comment_reply_click',
      parent_comment_id: parentCommentId
    });
  }, true);

  // Track Comment Submissions (no PII pushed)
  document.addEventListener('submit', function(e) {
    var form = e.target.closest('#commentform');
    if (!form) return;

    var commentBox = form.querySelector('textarea[name="comment"]');
    var msgLen = commentBox ? (commentBox.value || '').length : 0;

    window.dataLayer.push({
      event: 'comment_submit',
      comment_length: msgLen,
      has_parent: !!form.querySelector('input[name="comment_parent"]')?.value
    });
  }, true);
})();
</script>
```

### Expected `dataLayer` Output
```json
{
  "event": "comment_submit",
  "comment_length": 142,
  "has_parent": true
}
```

---

## Recipe 7: Form Plugin Native Event Listener (CF7, WPForms, Gravity Forms)

### Purpose
Hooks into custom DOM events dispatched by popular WordPress form plugins (Contact Form 7, WPForms, Gravity Forms) to record successful lead conversions reliably without relying on URL redirects or form submit events.

### Events Handled
- `wpcf7mailsent` (Contact Form 7)
- `wpformsSubmitSuccess` (WPForms)
- `gform_confirmation_loaded` (Gravity Forms)

### GTM Custom HTML Code
```html
<script>
(function() {
  'use strict';
  window.dataLayer = window.dataLayer || [];

  // 1. Contact Form 7
  document.addEventListener('wpcf7mailsent', function(e) {
    window.dataLayer.push({
      event: 'generate_lead',
      form_plugin: 'contact_form_7',
      form_id: e.detail ? e.detail.contactFormId : undefined
    });
  }, false);

  // 2. WPForms
  document.addEventListener('wpformsSubmitSuccess', function(e) {
    var formId = e.detail && e.detail.formId;
    window.dataLayer.push({
      event: 'generate_lead',
      form_plugin: 'wpforms',
      form_id: formId
    });
  }, false);

  // 3. Gravity Forms
  document.addEventListener('gform_confirmation_loaded', function(e, formId) {
    window.dataLayer.push({
      event: 'generate_lead',
      form_plugin: 'gravity_forms',
      form_id: formId
    });
  }, false);
})();
</script>
```

### Expected `dataLayer` Output
```json
{
  "event": "generate_lead",
  "form_plugin": "contact_form_7",
  "form_id": 42
}
```

---

## Recipe 8: WooCommerce Generic Add-to-Cart & Gallery Tracking

### Purpose
Captures add-to-cart clicks on standard WooCommerce product pages (`.single_add_to_cart_button`) and shop loop AJAX buttons (`.ajax_add_to_cart`), as well as product gallery image clicks.

### Target Selectors
- `.single_add_to_cart_button`, `.ajax_add_to_cart`
- `.woocommerce-product-gallery__image`

### GTM Custom HTML Code
```html
<script>
(function() {
  'use strict';
  window.dataLayer = window.dataLayer || [];

  // Add to Cart listener
  document.addEventListener('click', function(e) {
    var btn = e.target.closest('.single_add_to_cart_button, .ajax_add_to_cart, .add_to_cart_button');
    if (!btn) return;

    var productContainer = btn.closest('.product, .type-product') || document;
    var productId = btn.value || btn.getAttribute('data-product_id') || productContainer.getAttribute('data-product-id');
    var titleElem = productContainer.querySelector('.product_title, .woocommerce-loop-product__title');
    var priceElem = productContainer.querySelector('.price .amount, .price');
    var qtyElem = productContainer.querySelector('input.qty');

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
          item_id: productId || undefined,
          item_name: titleElem ? titleElem.textContent.trim() : undefined,
          price: price,
          quantity: qty
        }]
      }
    });
  }, true);

  // Gallery view listener
  document.addEventListener('click', function(e) {
    var galleryImg = e.target.closest('.woocommerce-product-gallery__image');
    if (!galleryImg) return;

    window.dataLayer.push({
      event: 'select_content',
      content_type: 'product_gallery_image',
      item_id: galleryImg.getAttribute('data-thumb-alt') || undefined
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
    "value": 24.99,
    "items": [
      {
        "item_id": "99",
        "item_name": "WordPress T-Shirt",
        "price": 24.99,
        "quantity": 1
      }
    ]
  }
}
```

---

## Recipe 9: Code Snippet & Content Copy Tracking

### Purpose
Detects when a visitor copies code snippets or article text from WordPress posts (`.wp-block-code`, `pre`, `code`, `article`).

### Target Selectors
- `.wp-block-code`, `pre`, `code`, `article`

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
    var codeBlock = parentElem ? parentElem.closest('.wp-block-code, pre, code') : null;

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
  "copied_length": 84,
  "is_code_block": true,
  "page_path": "/lab-clicks/"
}
```

---

## Best Practices & Security Rules

1. **Always Null the Ecommerce Object**: Before pushing any GA4 ecommerce payload, execute `window.dataLayer.push({ ecommerce: null });` to prevent parameter contamination from previous events.
2. **Never Push PII**: Exclude raw input values from text fields, email addresses, names, and phone numbers. Measure length, validation state, field name, or length buckets (`value_length_bucket`).
3. **Use Delegated Event Listeners**: Always bind listeners to `document` or `window` with `e.target.closest(...)` so dynamically injected elements (AJAX loads, modal popups, Gutenberg blocks) are captured seamlessly.
4. **Use Number Types for Monetary Values**: Ensure `price` and `value` fields in ecommerce pushes are numeric (e.g. `24.50`, not `"24.50"`).
5. **Verify with the dataLayer Lab Inspector**: Open the inspector panel on the bottom-right of your lab page to verify `window.dataLayer` pushes and GA4 collect hits in real time.
