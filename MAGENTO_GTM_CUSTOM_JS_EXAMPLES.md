# Magento Open Source GTM Custom JavaScript Examples

A collection of production-grade Custom JavaScript snippets designed to be deployed via **Google Tag Manager (GTM)** as **Custom HTML Tags** on a Magento Open Source (Luma theme) storefront.

These snippets listen for Magento core DOM structures (product pages, layered navigation, the mini-cart, wishlist/compare, and Magento's own AJAX cart events), extract clean context without capturing PII, and push structured events to `window.dataLayer`.

Selectors below target the default **Luma** theme. A custom storefront theme, or a PWA/headless frontend, will use different markup — inspect your own DOM before deploying.

---

## Table of Contents

1. [Deployment Instructions in GTM](#deployment-instructions-in-gtm)
2. [Recipe 1: CTA & Button Tracking](#recipe-1-cta--button-tracking)
3. [Recipe 2: Product Info Tab Tracking](#recipe-2-product-info-tab-tracking)
4. [Recipe 3: Layered Navigation Filter Clicks](#recipe-3-layered-navigation-filter-clicks)
5. [Recipe 4: Site Search Form Listener](#recipe-4-site-search-form-listener)
6. [Recipe 5: Main Navigation Menu Tracking](#recipe-5-main-navigation-menu-tracking)
7. [Recipe 6: Wishlist & Compare Interactions](#recipe-6-wishlist--compare-interactions)
8. [Recipe 7: Native AJAX Add-to-Cart Event Listener](#recipe-7-native-ajax-add-to-cart-event-listener)
9. [Recipe 8: Product Gallery (Fotorama) Interaction Tracking](#recipe-8-product-gallery-fotorama-interaction-tracking)
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
6. Save and test using **GTM Preview Mode**. GTM itself doesn't know or care which CMS/platform rendered the page — only the selectors below are Magento-specific.

---

## Recipe 1: CTA & Button Tracking

### Purpose
Captures clicks on Luma's standard action buttons across category, CMS, and account pages.

### Target Selectors
- `.action.primary`, `.button`, `a.action`

### GTM Custom HTML Code
```html
<script>
(function() {
  'use strict';
  window.dataLayer = window.dataLayer || [];

  document.addEventListener('click', function(e) {
    var btn = e.target.closest('.action.primary, .button, a.action');
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
  "cta_text": "View Details",
  "cta_url": "https://example.com/catalog/product/view/id/42",
  "cta_style": "action primary tocart",
  "is_external": false
}
```

---

## Recipe 2: Product Info Tab Tracking

### Purpose
Tracks clicks on the product page's data tabs (Description, Additional Information, Reviews) — a good proxy for purchase-intent engagement.

### Target Selectors
- `.product.data.items .data.switch`, `#tab-label-description-title`, `#tab-label-reviews-title`

### GTM Custom HTML Code
```html
<script>
(function() {
  'use strict';
  window.dataLayer = window.dataLayer || [];

  document.addEventListener('click', function(e) {
    var tab = e.target.closest('.product.data.items .data.switch, [id^="tab-label-"]');
    if (!tab) return;

    var productTitle = document.querySelector('.page-title .base, h1.page-title');

    window.dataLayer.push({
      event: 'product_tab_click',
      tab_name: (tab.textContent || '').trim(),
      product_name: productTitle ? productTitle.textContent.trim() : undefined,
      page_path: window.location.pathname
    });
  }, true);
})();
</script>
```

### Expected `dataLayer` Output
```json
{
  "event": "product_tab_click",
  "tab_name": "Reviews",
  "product_name": "Fusion Backpack",
  "page_path": "/fusion-backpack.html"
}
```

---

## Recipe 3: Layered Navigation Filter Clicks

### Purpose
Captures clicks on layered navigation facets (category attribute filters, swatches) on category listing pages.

### Target Selectors
- `.filter-options-item a`, `.swatch-option`, `#layered-filter-block a`

### GTM Custom HTML Code
```html
<script>
(function() {
  'use strict';
  window.dataLayer = window.dataLayer || [];

  document.addEventListener('click', function(e) {
    var filterLink = e.target.closest('.filter-options-item a, #layered-filter-block a');
    var swatch = e.target.closest('.swatch-option');
    if (!filterLink && !swatch) return;

    var filterGroup = (filterLink || swatch).closest('.filter-options-item');
    var groupTitle = filterGroup ? filterGroup.querySelector('.filter-options-title') : null;

    window.dataLayer.push({
      event: 'filter_select',
      filter_group: groupTitle ? groupTitle.textContent.trim() : undefined,
      filter_value: filterLink ? (filterLink.textContent || '').trim() : (swatch.getAttribute('aria-label') || swatch.getAttribute('option-label')),
      page_path: window.location.pathname
    });
  }, true);
})();
</script>
```

### Expected `dataLayer` Output
```json
{
  "event": "filter_select",
  "filter_group": "Color",
  "filter_value": "Blue",
  "page_path": "/men/bags.html"
}
```

---

## Recipe 4: Site Search Form Listener

### Purpose
Hooks into Magento's header quick-search form (`#search_mini_form`) to log search attempts before the results page navigation occurs.

### Target Selectors
- `#search_mini_form`, `input[name="q"]`

### GTM Custom HTML Code
```html
<script>
(function() {
  'use strict';
  window.dataLayer = window.dataLayer || [];

  document.addEventListener('submit', function(e) {
    var form = e.target.closest('#search_mini_form');
    if (!form) return;

    var input = form.querySelector('input[name="q"]');
    if (!input) return;

    var query = (input.value || '').trim();
    if (!query) return;

    window.dataLayer.push({
      event: 'search_submit',
      search_term: query.slice(0, 100),
      search_length: query.length,
      search_location: 'header'
    });
  }, true);
})();
</script>
```

### Expected `dataLayer` Output
```json
{
  "event": "search_submit",
  "search_term": "backpack",
  "search_length": 8,
  "search_location": "header"
}
```

---

## Recipe 5: Main Navigation Menu Tracking

### Purpose
Tracks link clicks inside Luma's main navigation, including top-level category links and flyout submenu items.

### Target Selectors
- `.navigation`, `nav.navigation`

### GTM Custom HTML Code
```html
<script>
(function() {
  'use strict';
  window.dataLayer = window.dataLayer || [];

  document.addEventListener('click', function(e) {
    var link = e.target.closest('.navigation a, nav.navigation a');
    if (!link) return;

    var parentLi = link.closest('li.level0, li.level1, li.level2');
    var depth = parentLi ? (parentLi.className.match(/level(\d)/) || [])[1] : undefined;

    window.dataLayer.push({
      event: 'menu_click',
      menu_location: 'main_nav',
      menu_text: (link.textContent || '').trim(),
      menu_url: link.href,
      menu_depth: depth ? parseInt(depth, 10) : undefined
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
  "menu_text": "Bags",
  "menu_url": "http://localhost/men/bags.html",
  "menu_depth": 0
}
```

---

## Recipe 6: Wishlist & Compare Interactions

### Purpose
Magento core has no comment system, so this recipe covers two of its native, purchase-intent-signal interactions instead: adding a product to the wishlist and adding it to the compare list.

### Target Selectors
- `.action.towishlist`, `.action.tocompare`

### GTM Custom HTML Code
```html
<script>
(function() {
  'use strict';
  window.dataLayer = window.dataLayer || [];

  document.addEventListener('click', function(e) {
    var btn = e.target.closest('.action.towishlist, .action.tocompare');
    if (!btn) return;

    var actionType = btn.classList.contains('towishlist') ? 'wishlist_add' : 'compare_add';
    var productContainer = btn.closest('.product-item, .product-info-main') || document;
    var titleElem = productContainer.querySelector('.product-item-link, .page-title .base');

    window.dataLayer.push({
      event: actionType,
      product_name: titleElem ? titleElem.textContent.trim() : undefined,
      product_id: btn.getAttribute('data-product-id') || undefined
    });
  }, true);
})();
</script>
```

### Expected `dataLayer` Output
```json
{
  "event": "wishlist_add",
  "product_name": "Fusion Backpack",
  "product_id": "42"
}
```

---

## Recipe 7: Native AJAX Add-to-Cart Event Listener

### Purpose
Magento's own JS (`Magento_Catalog/js/catalog-add-to-cart`) dispatches native jQuery custom events — `ajax:addToCart` and `ajax:addToCartError` — on the add-to-cart form after the AJAX round trip completes. This is more reliable than a click or submit listener because it fires only on confirmed success.

### Events Handled
- `ajax:addToCart` on `[data-role="tocart-form"]`

### GTM Custom HTML Code
```html
<script>
(function() {
  'use strict';
  window.dataLayer = window.dataLayer || [];

  // Magento dispatches this via jQuery.trigger, which also fires as a native
  // DOM event on the form element — no jQuery dependency required here.
  document.addEventListener('ajax:addToCart', function(e) {
    var form = e.target.closest ? e.target : (e.target && e.target.form);
    var productContainer = (form && form.closest('.product-info-main, .product-item')) || document;
    var titleElem = productContainer.querySelector('.page-title .base, .product-item-link');
    var priceElem = productContainer.querySelector('.price-wrapper .price, [data-price-type="finalPrice"] .price');
    var qtyElem = form ? form.querySelector('input[name="qty"]') : null;

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
    "value": 89.97,
    "items": [
      {
        "item_name": "Fusion Backpack",
        "price": 29.99,
        "quantity": 3
      }
    ]
  }
}
```

---

## Recipe 8: Product Gallery (Fotorama) Interaction Tracking

### Purpose
Tracks engagement with Luma's default product image gallery, built on the Fotorama library — thumbnail clicks and main-stage zoom interactions.

### Target Selectors
- `.fotorama__nav__frame`, `.fotorama__stage__frame`

### GTM Custom HTML Code
```html
<script>
(function() {
  'use strict';
  window.dataLayer = window.dataLayer || [];

  document.addEventListener('click', function(e) {
    var thumb = e.target.closest('.fotorama__nav__frame');
    var stage = e.target.closest('.fotorama__stage__frame');
    if (!thumb && !stage) return;

    window.dataLayer.push({
      event: 'select_content',
      content_type: thumb ? 'product_gallery_thumbnail' : 'product_gallery_zoom',
      item_index: thumb ? thumb.getAttribute('data-gallery-role') && Array.prototype.indexOf.call(thumb.parentElement.children, thumb) : undefined
    });
  }, true);
})();
</script>
```

### Expected `dataLayer` Output
```json
{
  "event": "select_content",
  "content_type": "product_gallery_thumbnail",
  "item_index": 2
}
```

---

## Recipe 9: Code Snippet & Content Copy Tracking

### Purpose
Detects when a visitor copies text from CMS pages or product descriptions — useful for tracking engagement with spec sheets or size-guide content.

### Target Selectors
- `.cms-content pre`, `.product.attribute.description`, `pre`, `code`

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
    var codeBlock = parentElem ? parentElem.closest('.cms-content pre, pre, code') : null;

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
  "copied_length": 61,
  "is_code_block": false,
  "page_path": "/fusion-backpack.html"
}
```

---

## Best Practices & Security Rules

1. **Always Null the Ecommerce Object**: Before pushing any GA4 ecommerce payload, execute `window.dataLayer.push({ ecommerce: null });` to prevent parameter contamination from previous events.
2. **Never Push PII**: Exclude raw input values from text fields, email addresses, names, and phone numbers. Measure length, validation state, field name, or length buckets (`value_length_bucket`).
3. **Prefer Magento's Own Events Over Click/Submit Listeners**: Where Magento dispatches a native completion event (like `ajax:addToCart`), use it instead of guessing at click/submit timing — it only fires on confirmed server success, avoiding false positives from validation failures.
4. **Use Number Types for Monetary Values**: Ensure `price` and `value` fields in ecommerce pushes are numeric (e.g. `24.50`, not `"24.50"`).
5. **Watch for Knockout.js Re-renders**: Luma's cart/checkout widgets re-render via Knockout.js bindings, which can detach and re-attach DOM nodes. Delegated listeners on `document` handle this correctly; listeners bound directly to a specific node will silently stop firing after a re-render.
