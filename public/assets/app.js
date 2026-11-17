/* ==========================================================================
   Campus Market — frontend app (vanilla JS, no framework, no build step)
   --------------------------------------------------------------------------
   Structure:
     1. api()      unified fetch wrapper that unwraps the {data,error} envelope
     2. state      current user / route / filters
     3. router     hash routing + view registry
     4. views      per-page render functions (return HTML string, bind events)
     5. boot       startup

   Design conventions:
     - all user input passes through esc() before reaching innerHTML (XSS defense)
     - events are delegated on #app, so switching views needs no rebinding
     - routes live in the hash, so refresh never 404s and no server rewrite is needed
   ========================================================================== */

'use strict';

/* ============================ 1. Basic utilities ============================ */

const API = '/api/v1';

/** HTML escape. Every dynamic value injected into innerHTML must pass through here. */
function esc(value) {
  if (value === null || value === undefined) return '';
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

/** Format money: HK$1,299.00 */
function money(n) {
  const num = Number(n) || 0;
  return 'HK$' + num.toLocaleString('en-HK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/** Relative time: 3 minutes ago / 2 days ago / 2025-03-30 */
function shortTimeAgo(iso) {
  if (!iso) return '';
  const then = new Date(String(iso).replace(' ', 'T'));
  if (Number.isNaN(then.getTime())) return String(iso);
  const diff = (Date.now() - then.getTime()) / 1000;
  if (diff < 60) return 'just now';
  if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
  if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
  if (diff < 86400 * 7) return Math.floor(diff / 86400) + 'd ago';
  return then.toISOString().slice(0, 10);
}

function timeAgo(dateString) {
  if (!dateString) return 'just now';
  const then = new Date(String(dateString).replace(' ', 'T'));
  if (Number.isNaN(then.getTime())) return 'just now';

  const minutes = Math.floor((Date.now() - then.getTime()) / 60000);
  if (minutes < 15) return 'just now';

  const rtf = new Intl.RelativeTimeFormat('en', { numeric: 'always' });
  if (minutes < 60) return rtf.format(-minutes, 'minute');

  const hours = Math.floor(minutes / 60);
  if (hours < 24) return rtf.format(-hours, 'hour');

  const days = Math.floor(hours / 24);
  if (days < 30) return rtf.format(-days, 'day');

  return rtf.format(-Math.floor(days / 30), 'month');
}

/** Product status → display label */
const STATUS_LABEL = { new: 'New', used: 'Used', damaged: 'Damaged' };

function statusTag(status) {
  const key = ['new', 'used', 'damaged'].includes(status) ? status : 'used';
  return `<span class="tag tag-${key}">${esc(STATUS_LABEL[key] || status)}</span>`;
}

/** Rating → ★★★★☆ */
function stars(rating) {
  const r = Math.round(Number(rating) || 0);
  return '★'.repeat(r) + '☆'.repeat(Math.max(0, 5 - r));
}

/** Image path → URL. Absolute URLs pass through; site-relative paths get a leading slash. */
function imgUrl(src) {
  return /^https?:\/\//i.test(src) ? src : '/' + String(src).replace(/^\/+/, '');
}

/** Image path → <img> markup. */
function imgTag(src, alt, cls) {
  if (!src) {
    return `<div class="placeholder">No image</div>`;
  }
  const url = imgUrl(src);
  return `<img src="${esc(url)}" alt="${esc(alt)}" loading="lazy"${cls ? ` class="${esc(cls)}"` : ''}>`;
}

/** All product images go through /products/{id}/image: new images read a BLOB, legacy ones fall back to server files. */
function productImageUrl(id) {
  return '/api/v1/products/' + encodeURIComponent(id) + '/image';
}

function productImgTag(id, src, alt) {
  // The image endpoint checks BLOB first and falls back to image_path, so
  // image_path being empty must not suppress the request.
  return `<img src="${esc(productImageUrl(id))}" alt="${esc(alt)}" loading="lazy" onerror="this.hidden=true">`;
}

/** User avatar: use the image when avatar_path is set, otherwise the first character of the username. */
function avatarTag(user, cls = 'avatar', size = 40) {
  const name = (user && user.username) || '?';
  const style = `width:${size}px;height:${size}px;font-size:${Math.max(12, Math.round(size / 2.6))}px`;
  if (user && user.avatar_path) {
    return `<span class="${esc(cls)}" style="${style}"><img src="${esc(imgUrl(user.avatar_path))}" alt="${esc(name)}"></span>`;
  }
  return `<span class="${esc(cls)}" style="${style}">${esc(name.slice(0, 1))}</span>`;
}

let toastTimer = null;
function toast(message, isError = false) {
  const el = document.getElementById('toast');
  el.textContent = message;
  el.className = 'toast' + (isError ? ' err' : '');
  el.hidden = false;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { el.hidden = true; }, 3200);
}

/* ============================ 2. API wrapper ============================ */

/**
 * Call the backend. Resolves with {data, meta} on success; throws an Error carrying status / code / details on failure.
 * credentials: 'same-origin' makes the browser attach the session cookie automatically.
 */
async function api(path, { method = 'GET', body = null, form = null } = {}) {
  const opts = {
    method,
    credentials: 'same-origin',
    headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' },
  };

  if (form instanceof FormData) {
    opts.body = form;                       // let the browser set the multipart boundary
  } else if (body !== null) {
    opts.headers['Content-Type'] = 'application/json';
    opts.body = JSON.stringify(body);
  }

  let res;
  try {
    res = await fetch(API + path, opts);
  } catch (networkError) {
    const err = new Error('Cannot connect to the server');
    err.status = 0;
    throw err;
  }

  if (res.status === 204) return { data: null, meta: {} };

  let payload = null;
  const text = await res.text();
  if (text) {
    try { payload = JSON.parse(text); }
    catch { throw Object.assign(new Error('Server returned a non-JSON response'), { status: res.status, raw: text.slice(0, 200) }); }
  }

  if (!res.ok) {
    const info = payload?.error || {};
    const err = new Error(info.message || `Request failed (HTTP ${res.status})`);
    err.status = res.status;
    err.code = info.code;
    err.details = info.details || {};
    throw err;
  }

  return { data: payload?.data ?? null, meta: payload?.meta || {} };
}

/* ============================ 3. Global state ============================ */

const state = {
  user: null,          // currently signed-in user; null = signed out
  route: null,         // {name, params}
  filters: {           // product list filters (kept in sync with the URL)
    page: 1,
    category_id: 0,
    search: '',
    min_price: '',
    max_price: '',
    status: '',
  },
};

const listeners = { auth: [] };
function onAuthChange(fn) { listeners.auth.push(fn); }
function setUser(user) {
  state.user = user;
  listeners.auth.forEach(fn => fn(user));
}

/* ============================ 4. Routing ============================ */

function parseHash() {
  const raw = location.hash.replace(/^#/, '') || '/';
  const [pathPart, queryPart] = raw.split('?');
  const query = Object.fromEntries(new URLSearchParams(queryPart || ''));
  const segments = pathPart.split('/').filter(Boolean);

  if (segments.length === 0) return { name: 'home', params: {}, query };

  switch (segments[0]) {
    case 'login':    return { name: 'login',    params: {}, query };
    case 'register': return { name: 'register', params: {}, query };
    case 'publish':  return { name: 'publish',  params: {}, query };
    case 'cart':     return { name: 'cart',     params: {}, query };
    case 'orders':      return { name: 'orders',      params: {}, query };
    case 'account':     return { name: 'account',     params: {}, query };
    case 'my-products': return { name: 'my-products', params: {}, query };
    case 'admin':       return { name: 'admin',       params: {}, query };
    case 'product':     return { name: 'product',     params: { id: segments[1] }, query };
    case 'seller':      return { name: 'seller',      params: { id: segments[1] }, query };
    case 'user':        return { name: 'seller',      params: { id: segments[1] }, query };
    default:         return { name: 'notfound', params: {}, query };
  }
}

function navigate(path) {
  if (location.hash === '#' + path) {
    render();      // re-render even when the same address is clicked again
  } else {
    location.hash = path;
  }
}

/* ============================ 5. View: product list ============================ */

async function viewHome(root, route) {
  // restore filters from the URL
  const q = route.query;
  state.filters = {
    page: Math.max(1, parseInt(q.page || '1', 10) || 1),
    category_id: parseInt(q.category_id || '0', 10) || 0,
    search: q.search || '',
    min_price: q.min_price || '',
    max_price: q.max_price || '',
    status: ['new', 'used', 'damaged'].includes(q.status) ? q.status : '',
  };
  document.getElementById('search-input').value = state.filters.search;

  root.innerHTML = `
    <!-- Banner: brand intro + live stats on the left, three newest listings on the right -->
    <section class="hero" id="hero">
      <div class="hero-left">
        <h1 class="hero-title">What you don’t need, someone does.</h1>
        <p class="hero-sub">Campus secondhand marketplace — give what you don’t use to someone who needs it.</p>
        <div class="hero-stats" id="hero-stats"></div>
        <div class="hero-actions">
          <a class="btn" href="#/publish" data-link data-hero-publish>List an item</a>
          <button class="btn btn-outline" type="button" id="hero-browse">Browse</button>
        </div>
      </div>
      <div class="hero-right" id="hero-right"></div>
    </section>

    <div class="page-head">
      <h1>Items for sale</h1>
      <span class="count" id="result-count"></span>
    </div>
    <div class="chips" id="cat-parents"></div>
    <div class="chips" id="cat-children" hidden></div>
    <div class="filters filters-plain" id="filters">
      <div class="field">
        <label>Price</label>
        <div class="field-price">
          <input type="number" id="f-min" name="min_price" placeholder="Min" min="0" step="1"
                 value="${esc(state.filters.min_price)}">
          <span>—</span>
          <input type="number" id="f-max" name="max_price" placeholder="Max" min="0" step="1"
                 value="${esc(state.filters.max_price)}">
        </div>
      </div>
      <div class="field">
        <label for="f-status">Condition</label>
        <select id="f-status" name="status">
          <option value="">All</option>
          <option value="new">New</option>
          <option value="used">Used</option>
          <option value="damaged">Damaged</option>
        </select>
      </div>
      <button class="btn" id="apply-filters" type="button">Filter</button>
      <button class="btn btn-outline" id="reset-filters" type="button">Reset</button>
    </div>
    <div id="grid-wrap"><div class="loading">Loading…</div></div>
  `;

  // Banner content (stats + newest listings). If it fails, hide the whole block; the list is unaffected.
  loadHero(root);

  const statusSel = root.querySelector('#f-status');
  statusSel.value = state.filters.status;

  const parentChips = root.querySelector('#cat-parents');
  const childChips = root.querySelector('#cat-children');
  let categories = [];

  // Category pills (fetched in parallel; a failure does not block the list)
  api('/categories').then(({ data }) => {
    categories = data || [];
    renderCategoryChips();
  }).catch(() => { /* a failed category fetch does not block the product list */ });

  function categoryById(id) {
    return categories.find(c => Number(c.id) === Number(id)) || null;
  }

  function currentParentId() {
    const selected = categoryById(state.filters.category_id);
    if (!selected) return 0;
    return selected.parent_id === null ? Number(selected.id) : Number(selected.parent_id);
  }

  function categoryChip(label, active, onClick) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = active ? 'chip active' : 'chip';
    btn.textContent = label;
    btn.addEventListener('click', onClick);
    return btn;
  }

  function goCategory(categoryId) {
    state.filters.page = 1;
    state.filters.category_id = Number(categoryId) || 0;
    syncUrl();
    renderCategoryChips();
    load(true);
  }

  function syncUrl() {
    const qs = currentQuery().toString();
    history.pushState(null, '', '#' + (qs ? '/?' + qs : '/'));
  }

  function renderCategoryChips() {
    const allParents = categories.filter(c => c.parent_id === null);
    const parents = allParents.filter(c => c.name !== 'Other')
      .concat(allParents.filter(c => c.name === 'Other'));
    const selectedParent = currentParentId();

    parentChips.innerHTML = '';
    parentChips.appendChild(categoryChip('All', Number(state.filters.category_id) === 0, () => goCategory(0)));
    for (const c of parents) {
      parentChips.appendChild(categoryChip(c.name, selectedParent === Number(c.id), () => goCategory(c.id)));
    }

    const parent = categoryById(selectedParent);
    const children = parent ? categories.filter(c => Number(c.parent_id) === Number(parent.id)) : [];
    childChips.innerHTML = '';
    if (children.length === 0) {
      childChips.hidden = true;
      return;
    }
    childChips.hidden = false;
    childChips.appendChild(categoryChip('All subcategories', Number(state.filters.category_id) === Number(parent.id), () => goCategory(parent.id)));
    for (const c of children) {
      childChips.appendChild(categoryChip(c.name, Number(state.filters.category_id) === Number(c.id), () => goCategory(c.id)));
    }
  }

  function currentQuery(overrides = {}) {
    const merged = { ...state.filters, ...overrides };
    const params = new URLSearchParams();
    if (merged.page > 1) params.set('page', merged.page);
    if (merged.category_id) params.set('category_id', merged.category_id);
    if (merged.search) params.set('search', merged.search);
    if (merged.min_price !== '' && merged.min_price !== null) params.set('min_price', merged.min_price);
    if (merged.max_price !== '' && merged.max_price !== null) params.set('max_price', merged.max_price);
    if (merged.status) params.set('status', merged.status);
    return params;
  }

  let loadSeq = 0;

  async function load(keepGrid = false) {
    const wrap = root.querySelector('#grid-wrap');
    const seq = ++loadSeq;
    if (keepGrid) wrap.classList.add('is-loading');
    else wrap.innerHTML = '<div class="loading">Loading…</div>';

    const params = currentQuery();
    params.set('per_page', '12');

    try {
      const { data, meta } = await api('/products?' + params.toString());
      if (seq !== loadSeq) return;
      const pg = meta.pagination || {};

      const countEl = root.querySelector('#result-count');
      if (countEl) countEl.textContent = pg.total ? `Total ${pg.total} items` : '';

      if (!data || data.length === 0) {
        wrap.innerHTML = `
          <div class="empty">
            <h3>No items match your filters</h3>
            <p>Try widening the filters or using a different keyword.</p>
          </div>`;
        return;
      }

      wrap.innerHTML = `<div class="grid">${data.map(productCard).join('')}</div>` +
                       renderPagination(pg, currentQuery());
    } catch (e) {
      if (seq !== loadSeq) return;
      wrap.innerHTML = `<div class="empty"><h3>Failed to load</h3><p>${esc(e.message)}</p></div>`;
    } finally {
      if (seq === loadSeq) wrap.classList.remove('is-loading');
    }
  }

  // filter events
  root.querySelector('#apply-filters').addEventListener('click', () => {
    const min = root.querySelector('#f-min').value.trim();
    const max = root.querySelector('#f-max').value.trim();
    if (min !== '' && max !== '' && Number(min) > Number(max)) {
      toast('Minimum price cannot be higher than maximum price', true);
      return;
    }
    state.filters.page = 1;
    state.filters.status = statusSel.value;
    state.filters.min_price = min;
    state.filters.max_price = max;
    syncUrl();
    load(true);
  });

  root.querySelector('#reset-filters').addEventListener('click', () => {
    document.getElementById('search-input').value = '';
    state.filters = {
      page: 1,
      category_id: 0,
      search: '',
      min_price: '',
      max_price: '',
      status: '',
    };
    root.querySelector('#f-min').value = '';
    root.querySelector('#f-max').value = '';
    statusSel.value = '';
    syncUrl();
    renderCategoryChips();
    load(true);
  });

  await load();
}

function discountInfo(price) {
  const level = Number(state.user && state.user.level) || 1;
  if (level <= 1) return null;
  return { level, price: Number(price) * (1 - (level - 1) * 0.01) };
}

function productCard(p) {
  const soldOverlay = p.is_sold ? '<div class="sold-flag">Sold</div>' : '';
  const discount = discountInfo(p.price);
  const priceHtml = discount
    ? `<div class="card-price price-row">
         <span class="price-now">${money(discount.price)}</span>
         <del class="price-was">${money(p.price)}</del>
         <span class="price-badge">Lv${discount.level} -${discount.level - 1}%</span>
       </div>`
    : `<div class="card-price" style="color:var(--text)">${money(p.price)}</div>`;
  return `
    <a class="card" href="#/product/${p.id}" data-link>
      <div class="card-img">${productImgTag(p.id, p.image_path, p.title)}${soldOverlay}</div>
      <div class="card-body">
        <h3 class="card-title">${esc(p.title)}</h3>
        ${priceHtml}
        <div class="card-meta">
          ${statusTag(p.status)}
          ${p.category_name ? `<span class="tag">${esc(p.category_name)}</span>` : ''}
        </div>
      </div>
    </a>`;
}

function renderPagination(pg, params) {
  const total = pg.total_pages || 0;
  if (total <= 1) return '';

  const page = pg.page || 1;
  const link = (n, label) => {
    const p = new URLSearchParams(params);
    p.set('page', String(n));
    return `<a href="#/?${p.toString()}" data-link>${label ?? n}</a>`;
  };

  const parts = [];
  if (page > 1) {
    parts.push(link(1, '« First'));
    parts.push(link(page - 1, '‹ Prev'));
  }

  const start = Math.max(1, page - 2);
  const end = Math.min(total, page + 2);
  if (start > 1) parts.push('<span class="gap">…</span>');
  for (let i = start; i <= end; i++) {
    parts.push(i === page ? `<span class="current">${i}</span>` : link(i));
  }
  if (end < total) parts.push('<span class="gap">…</span>');

  if (page < total) {
    parts.push(link(page + 1, 'Next ›'));
    parts.push(link(total, 'Last »'));
  }

  return `<nav class="pagination" aria-label="Pagination">${parts.join('')}</nav>`;
}

/* ============================ 5b. Hero banner ============================ */

/**
 * Home banner: brand intro + live stats on the left, the three newest listings on the right.
 *
 * Why fetch from the API instead of hardcoding: the banner numbers must be real. Hardcoding
 * "137 items for sale" breaks as soon as a few sell before the demo.
 *
 * If any step fails, collapse the whole block (hide .hero); never let a broken banner affect
 * the product list — the banner is a nice-to-have, the list is the main content.
 */
async function loadHero(root) {
  const hero = root.querySelector('#hero');
  if (!hero) return;

  const statsBox = root.querySelector('#hero-stats');
  const rightBox = root.querySelector('#hero-right');
  if (!statsBox || !rightBox) return;

  const publishBtn = hero.querySelector('[data-hero-publish]');
  const browseBtn = root.querySelector('#hero-browse');

  // "Just browsing" = clear filters and return to the unfiltered list
  if (browseBtn) {
    browseBtn.addEventListener('click', () => navigate('/'));
  }

  try {
    const [statsRes, latestRes] = await Promise.all([
      api('/stats'),
      api('/products?per_page=3'),
    ]);

    const s = statsRes.data || {};
    const latest = latestRes.data || [];

    statsBox.innerHTML = `
      <div class="stat"><span class="stat-n">${Number(s.products_available ?? 0).toLocaleString()}</span><span class="stat-l">Active</span></div>
      <div class="stat"><span class="stat-n">${Number(s.products_sold ?? 0).toLocaleString()}</span><span class="stat-l">Completed</span></div>
      <div class="stat"><span class="stat-n">${Number(s.users_total ?? 0).toLocaleString()}</span><span class="stat-l">Users</span></div>
    `;

    // Guests must not see the Publish button (it would bounce them to the login page mid-flow)
    if (publishBtn && !state.user) {
      publishBtn.href = '#/login?next=' + encodeURIComponent('#/publish');
      publishBtn.textContent = 'Log in to publish';
    }

    if (latest.length === 0) {
      rightBox.innerHTML = '';
      hero.classList.add('hero-no-latest');
      return;
    }

    rightBox.innerHTML = `
      <div class="hero-latest-label">Newest listings</div>
      <div class="hero-latest">
        ${latest.map(p => {
          const discount = discountInfo(p.price);
          return `
          <a class="hero-card" href="#/product/${p.id}" data-link>
            <div class="hero-card-img">${productImgTag(p.id, p.image_path, p.title)}</div>
            <div class="hero-card-body">
              <span class="hero-card-title">${esc(p.title)}</span>
              ${discount
                ? `<span class="hero-card-price price-row">
                     <span class="price-now">${money(discount.price)}</span>
                     <del class="price-was">${money(p.price)}</del>
                     <span class="price-badge">Lv${discount.level} -${discount.level - 1}%</span>
                   </span>`
                : `<span class="hero-card-price" style="color:var(--text)">${money(p.price)}</span>`}
            </div>
          </a>`;
        }).join('')}
      </div>`;
  } catch (e) {
    // If the banner cannot get its data, hide it entirely — never leave a half-empty shell
    hero.hidden = true;
  }
}

/* ============================ 6. View: product detail ============================ */

async function viewProduct(root, route) {
  const id = route.params.id;
  root.innerHTML = '<div class="loading">Loading…</div>';

  let p;
  try {
    ({ data: p } = await api('/products/' + encodeURIComponent(id)));
  } catch (e) {
    root.innerHTML = `<div class="empty"><h3>Cannot open item</h3><p>${esc(e.message)}</p>
      <p><a href="#/" data-link>Back to list</a></p></div>`;
    return;
  }

  const isOwner = state.user && Number(state.user.id) === Number(p.seller_id);
  const rating = p.seller_rating || { count: 0, average: null };
  const discount = discountInfo(p.price);

  root.innerHTML = `
    <p style="margin-bottom:1rem"><a href="#/" data-link>← Back to list</a></p>
    <div class="detail">
      <div class="detail-img">${productImgTag(p.id, p.image_path, p.title)}</div>
      <div>
        <h1>${esc(p.title)}</h1>
        ${discount
          ? `<div class="detail-price price-row">
               <span class="price-now">${money(discount.price)}</span>
               <del class="price-was">${money(p.price)}</del>
               <span class="price-badge">Lv${discount.level} -${discount.level - 1}%</span>
             </div>`
          : `<div class="detail-price" style="color:var(--text)">${money(p.price)}</div>`}
        <div id="ebay-ref" hidden></div>
        <div class="card-meta" style="margin-bottom:.5rem">
          ${statusTag(p.status)}
          ${p.category_name ? `<span class="tag">${esc(p.category_name)}</span>` : ''}
          ${p.is_sold ? '<span class="tag tag-damaged">Sold</span>' : ''}
          <span class="tag">${esc(shortTimeAgo(p.created_at))}</span>
        </div>

        <div class="detail-desc">${esc(p.description || 'The seller hasn’t added a description.')}</div>

        <div id="detail-actions" class="detail-actions"></div>

        <div style="margin-top:1rem;display:flex;gap:.5rem;flex-wrap:wrap">
          <button class="btn btn-outline btn-sm" id="find-midway" type="button">Find Midway Point</button>
          ${isOwner ? '' : '<button class="btn btn-outline btn-sm" id="report-product" type="button">Report</button>'}
        </div>

        <div class="seller-box">
          ${avatarTag({ username: p.seller_name, avatar_path: p.seller_avatar_path }, 'avatar', 40)}
          <div style="flex:1;min-width:0">
            <a href="#/seller/${p.seller_id}" data-link style="font-weight:600">${esc(p.seller_name || 'Unknown seller')}</a>
            <div style="color:var(--text-faint);font-size:.7rem">Active ${timeAgo(p.seller_last_active_at)}</div>
            <div class="s" style="color:var(--text-faint);font-size:.82rem">
              ${rating.count > 0
                ? `<span class="stars">${stars(rating.average)}</span> ${rating.average} · ${rating.count} reviews`
                : 'No reviews yet'}
            </div>
            <div class="s" style="color:var(--text-faint);font-size:.82rem">
              <span class="tag tag-level">Seller Lv${Number(p.seller_level) || 1}</span>
            </div>
          </div>
        </div>
      </div>
    </div>
    <div id="comments" style="margin-top:2.5rem"></div>
    <div id="reviews" style="margin-top:2.5rem"></div>
  `;

  renderDetailActions(root.querySelector('#detail-actions'), p, isOwner);
  root.querySelector('#find-midway').addEventListener('click', () => openMidwayDialog(p));
  const reportBtn = root.querySelector('#report-product');
  if (reportBtn) {
    reportBtn.addEventListener('click', () => {
      if (!state.user) {
        navigate('/login?next=' + encodeURIComponent('#/product/' + p.id));
        return;
      }
      openReportDialog(p);
    });
  }
  renderProductComments(root, p);
  renderProductReviews(root, p);
  loadEbayPrice(p.id, root);
}

function openReportDialog(product) {
  const reasons = [
    'Prohibited item',
    'Counterfeit',
    'Scam or fraud',
    'Offensive content',
    'Wrong category',
    'Other',
  ];
  const backdrop = document.createElement('div');
  backdrop.className = 'panel';
  backdrop.style.cssText = 'position:fixed;inset:0;max-width:none;margin:0;border-radius:0;' +
                           'background:rgba(0,0,0,.45);display:grid;place-items:center;z-index:400;padding:1rem;box-shadow:none;border:none;';
  backdrop.innerHTML = `
    <form class="panel" style="margin:0;width:min(92vw,420px)">
      <h2>Report this item</h2>
      <div id="report-alert"></div>
      <div class="form-row">
        <label for="report-reason">Reason</label>
        <select id="report-reason">${reasons.map(reason => `<option>${esc(reason)}</option>`).join('')}</select>
      </div>
      <div class="form-row">
        <label for="report-details">Details (optional)</label>
        <textarea id="report-details" maxlength="1000" placeholder="Tell us what is wrong with this listing."></textarea>
      </div>
      <div class="form-actions">
        <button class="btn" type="submit">Submit report</button>
        <button class="btn btn-outline" type="button" id="report-cancel">Cancel</button>
      </div>
    </form>`;
  document.body.appendChild(backdrop);
  backdrop.querySelector('#report-cancel').addEventListener('click', () => backdrop.remove());
  backdrop.querySelector('form').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const btn = ev.currentTarget.querySelector('button[type=submit]');
    btn.disabled = true;
    try {
      await api('/products/' + product.id + '/report', {
        method: 'POST',
        body: {
          reason: backdrop.querySelector('#report-reason').value,
          details: backdrop.querySelector('#report-details').value.trim(),
        },
      });
      toast('Report submitted');
      backdrop.remove();
    } catch (e) {
      backdrop.querySelector('#report-alert').innerHTML = `<div class="alert">${esc(e.message)}</div>`;
      btn.disabled = false;
    }
  });
}

async function geocodeAddress(address) {
  const query = encodeURIComponent(address);
  const url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + query +
              '&email=campus-market-demo%40example.com';
  const response = await fetch(url, {
    headers: {
      'Accept': 'application/json',
      'Accept-Language': 'en',
      'User-Agent': 'CampusMarket/1.0 (campus-market-demo@example.com)',
    },
  });
  if (!response.ok) throw new Error('Address lookup failed');
  const results = await response.json();
  if (!Array.isArray(results) || results.length === 0) return null;
  return { lat: Number(results[0].lat), lng: Number(results[0].lon) };
}

async function findNearbyStation(lat, lng) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), 3000);

  try {
    const viewbox = `${lng - 0.05},${lat + 0.05},${lng + 0.05},${lat - 0.05}`;
    const url = 'https://nominatim.openstreetmap.org/search?format=json&q=MTR+station&lat=' +
      encodeURIComponent(lat) + '&lon=' + encodeURIComponent(lng) + '&limit=1' +
      '&viewbox=' + encodeURIComponent(viewbox) + '&bounded=1' +
      '&email=campus-market-demo%40example.com';
    const response = await fetch(url, {
      headers: {
        'Accept': 'application/json',
        'Accept-Language': 'en',
        'User-Agent': 'CampusMarket/1.0 (campus-market-demo@example.com)',
      },
      signal: controller.signal,
    });
    if (!response.ok) return null;

    const results = await response.json();
    if (!Array.isArray(results) || results.length === 0) return null;

    const first = results[0];
    const display = String(first.display_name || '');
    const rawName = String(first.name || display.split(',')[0] || 'MTR station').trim();
    const name = /station/i.test(rawName) ? rawName : `${rawName} Station`;

    return {
      lat: Number(first.lat),
      lng: Number(first.lon),
      name,
    };
  } catch (e) {
    return null;
  } finally {
    clearTimeout(timer);
  }
}

function openMidwayDialog(product) {
  const sellerAddress = product.seller_meetup_address || '';
  const backdrop = document.createElement('div');
  backdrop.className = 'panel';
  backdrop.style.cssText = 'position:fixed;inset:0;max-width:none;margin:0;border-radius:0;' +
                           'background:rgba(0,0,0,.45);display:grid;place-items:center;z-index:400;padding:1rem;box-shadow:none;border:none;';
  backdrop.innerHTML = `
    <div class="panel" style="margin:0;width:min(94vw,620px);max-height:92vh;overflow:auto">
      <h2>Find Midway Point</h2>
      <div class="form-row">
        <label>Your location</label>
        <button class="btn btn-outline btn-sm" id="mid-gps" type="button">Use My GPS Location</button>
        <div class="form-hint" id="mid-status"></div>
        <div id="mid-manual" hidden>
          <input type="text" id="mid-address" placeholder="Enter your address manually" maxlength="255">
        </div>
      </div>
      <div class="form-row">
        <label>Seller meetup address</label>
        <div style="padding:.42rem .6rem;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg);font-size:.9rem">${esc(sellerAddress || 'Seller has not set a meetup address.')}</div>
      </div>
      <div id="route-map" style="height:320px;border-radius:10px;background:#eee;margin-top:.8rem"></div>
      <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;margin-top:.6rem">
        <div id="mid-result" class="form-hint"></div>
        <button class="btn btn-outline btn-sm" id="mid-route" type="button" hidden>Open Route in Google Maps</button>
      </div>
      <div class="form-actions">
        <button class="btn" id="mid-calc" type="button">Calculate</button>
        <button class="btn btn-outline" id="mid-cancel" type="button">Cancel</button>
      </div>
    </div>`;

  document.body.appendChild(backdrop);
  const status = backdrop.querySelector('#mid-status');
  const manualBox = backdrop.querySelector('#mid-manual');
  const manualInput = backdrop.querySelector('#mid-address');
  const resultBox = backdrop.querySelector('#mid-result');
  const routeBtn = backdrop.querySelector('#mid-route');
  const calcBtn = backdrop.querySelector('#mid-calc');
  let buyerCoords = null;
  let map = null;

  const closeDialog = () => {
    if (map !== null) {
      map.remove();
      map = null;
    }
    backdrop.remove();
  };

  backdrop.querySelector('#mid-cancel').addEventListener('click', closeDialog);

  backdrop.querySelector('#mid-gps').addEventListener('click', () => {
    if (!navigator.geolocation) {
      manualBox.hidden = false;
      status.textContent = 'GPS unavailable. Enter your address manually.';
      return;
    }

    status.textContent = 'Locating…';
    navigator.geolocation.getCurrentPosition(
      position => {
        buyerCoords = { lat: position.coords.latitude, lng: position.coords.longitude };
        status.textContent = 'GPS location captured.';
        manualBox.hidden = true;
      },
      () => {
        buyerCoords = null;
        manualBox.hidden = false;
        status.textContent = 'Location unavailable. Enter your address manually.';
      },
      { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
    );
  });

  calcBtn.addEventListener('click', async () => {
    resultBox.textContent = '';
    routeBtn.hidden = true;
    routeBtn.onclick = null;
    if (!window.L) {
      resultBox.textContent = 'Map library failed to load.';
      return;
    }
    if (!sellerAddress) {
      resultBox.textContent = 'Seller address not found';
      return;
    }

    calcBtn.disabled = true;
    calcBtn.textContent = 'Calculating…';
    try {
      const sellerCoords = await geocodeAddress(sellerAddress);
      if (sellerCoords === null) {
        resultBox.textContent = 'Seller address not found';
        return;
      }

      let buyer = buyerCoords;
      if (buyer === null) {
        const address = manualInput.value.trim();
        if (address === '') {
          manualBox.hidden = false;
          resultBox.textContent = 'Enter your address or use GPS.';
          return;
        }
        buyer = await geocodeAddress(address);
        if (buyer === null) {
          resultBox.textContent = 'Buyer address not found';
          return;
        }
      }

      const midpoint = {
        lat: (buyer.lat + sellerCoords.lat) / 2,
        lng: (buyer.lng + sellerCoords.lng) / 2,
      };
      const station = await findNearbyStation(midpoint.lat, midpoint.lng);
      const markerPoint = station !== null && Number.isFinite(station.lat) && Number.isFinite(station.lng)
        ? station
        : midpoint;

      if (map !== null) map.remove();
      map = L.map('route-map').setView([markerPoint.lat, markerPoint.lng], 13);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors',
      }).addTo(map);

      const dot = color => L.divIcon({
        className: '',
        html: `<div style="width:16px;height:16px;border-radius:50%;background:${color};border:2px solid #fff;box-shadow:0 0 4px rgba(0,0,0,.55)"></div>`,
        iconSize: [16, 16],
        iconAnchor: [8, 8],
      });

      L.marker([buyer.lat, buyer.lng], { icon: dot('#2e7d32') }).addTo(map).bindPopup('Buyer');
      L.marker([sellerCoords.lat, sellerCoords.lng], { icon: dot('#1565c0') }).addTo(map).bindPopup('Seller');
      L.marker([markerPoint.lat, markerPoint.lng], { icon: dot('#d32f2f') }).addTo(map).bindPopup(station ? 'Recommended station' : 'Midpoint');
      L.polyline([[buyer.lat, buyer.lng], [markerPoint.lat, markerPoint.lng]], { color: '#6b7280', dashArray: '6 8', weight: 3 }).addTo(map);
      L.polyline([[markerPoint.lat, markerPoint.lng], [sellerCoords.lat, sellerCoords.lng]], { color: '#6b7280', dashArray: '6 8', weight: 3 }).addTo(map);
      map.fitBounds([[buyer.lat, buyer.lng], [sellerCoords.lat, sellerCoords.lng], [markerPoint.lat, markerPoint.lng]], { padding: [30, 30] });
      setTimeout(() => map.invalidateSize(), 100);

      resultBox.textContent = station
        ? `Recommended Station: ${station.name}`
        : 'Midpoint (No station nearby)';
      const routeUrl = 'https://www.google.com/maps/dir/?api=1&origin=' +
        `${buyer.lat},${buyer.lng}&destination=${markerPoint.lat},${markerPoint.lng}&travelmode=walking`;
      routeBtn.hidden = false;
      routeBtn.onclick = () => window.open(routeUrl, '_blank', 'noopener');
    } catch (e) {
      resultBox.textContent = 'Could not calculate midpoint. Please try again.';
    } finally {
      calcBtn.disabled = false;
      calcBtn.textContent = 'Calculate';
    }
  });
}

async function loadEbayPrice(id, root) {
  const box = root.querySelector('#ebay-ref');
  if (!box) return;

  try {
    const { data } = await api('/products/' + encodeURIComponent(id) + '/ebay-price');
    if (!data) return;
    const min = Number(data.min).toFixed(2);
    const max = Number(data.max).toFixed(2);
    const minHkd = Number(data.min_hkd).toFixed(2);
    const maxHkd = Number(data.max_hkd).toFixed(2);
    box.innerHTML = `<a href="${esc(data.url)}" target="_blank" rel="noopener" style="color:var(--text-faint);font-size:.8rem;text-decoration:none">eBay Ref: HK$ ${minHkd} - HK$ ${maxHkd} (US$ ${min} - US$ ${max})</a>`;
    box.hidden = false;
  } catch (e) {
    console.warn('eBay reference price unavailable', e);
  }
}

function commentPager(pg, attr) {
  const total = pg.total_pages || 0;
  if (total <= 1) return '';
  const page = pg.page || 1;
  return `<nav class="pagination">${page > 1 ? `<button class="btn btn-ghost btn-sm" ${attr}="${page - 1}">‹ Prev</button>` : ''}
    <span class="current">${page} / ${total}</span>
    ${page < total ? `<button class="btn btn-ghost btn-sm" ${attr}="${page + 1}">Next ›</button>` : ''}</nav>`;
}

function renderProductComments(root, p) {
  const box = root.querySelector('#comments');
  if (!box) return;
  let page = 1;

  async function load() {
    box.innerHTML = '<h2>Product comments</h2><div class="loading">Loading…</div>';
    let data, pg;
    try {
      const res = await api('/products/' + p.id + '/comments?page=' + page + '&per_page=5');
      data = res.data || [];
      pg = res.meta.pagination || {};
    } catch (e) {
      box.innerHTML = `<h2>Product comments</h2><div class="empty">${esc(e.message)}</div>`;
      return;
    }

    const form = state.user
      ? `<form id="cm-form" class="comment-form">
          <textarea id="cm-body" maxlength="1000" placeholder="Ask about condition, accessories, or pickup…"></textarea>
          <button class="btn btn-sm" type="submit">Post comment</button>
        </form>`
      : `<p class="form-hint">Log in to message the seller.<a href="#/login?next=${encodeURIComponent('#/product/' + p.id)}" data-link>Log in</a></p>`;

    box.innerHTML = `<h2>Product comments <span class="count">${pg.total || 0}</span></h2>
      ${form}
      <div id="cm-list">${data.length ? data.map(commentHtml).join('') : '<div class="empty" style="padding:1.2rem">No comments yet. Ask the first question.</div>'}</div>
      ${commentPager(pg, 'data-cm-page')}`;

    bind();
  }

  function commentHtml(c) {
    const isSeller = Number(c.user_id) === Number(p.seller_id);
    const canDelete = state.user && (Number(state.user.id) === Number(c.user_id)
      || Number(state.user.id) === Number(p.seller_id) || state.user.is_admin);
    const canReply = state.user && (Number(state.user.id) === Number(p.seller_id) || state.user.is_admin);
    const replies = (c.replies || []).map(r => `
      <div class="comment-reply">
        ${avatarTag({ username: r.user_name, avatar_path: r.user_avatar_path }, 'avatar', 28)}
        <div style="flex:1;min-width:0">
          <div class="comment-meta"><strong>${esc(r.user_name)}</strong>
            ${Number(r.user_id) === Number(p.seller_id) ? '<span class="tag tag-new">Seller</span>' : ''}
            · ${esc(shortTimeAgo(r.created_at))}</div>
          <div class="comment-body">${esc(r.body)}</div>
        </div>
      </div>`).join('');

    return `
      <div class="comment" data-cid="${c.id}">
        <div class="comment-main">
          ${avatarTag({ username: c.user_name, avatar_path: c.user_avatar_path }, 'avatar', 36)}
          <div style="flex:1;min-width:0">
            <div class="comment-meta"><strong>${esc(c.user_name)}</strong>
              ${isSeller ? '<span class="tag tag-new">Seller</span>' : ''}
              · ${esc(shortTimeAgo(c.created_at))}</div>
            <div class="comment-body">${esc(c.body)}</div>
            ${replies ? `<div class="comment-replies">${replies}</div>` : ''}
            <div class="comment-actions">
              ${canReply ? `<button class="btn btn-ghost btn-sm" data-reply="${c.id}" type="button">Reply</button>` : ''}
              ${canDelete ? `<button class="btn btn-ghost btn-sm" data-cdel="${c.id}" type="button">Delete</button>` : ''}
            </div>
            ${canReply ? `<form class="reply-form" data-reply-form="${c.id}" hidden>
              <input maxlength="1000" placeholder="Reply to this comment…">
              <button class="btn btn-sm" type="submit">Send</button>
            </form>` : ''}
          </div>
        </div>
      </div>`;
  }

  function bind() {
    const form = box.querySelector('#cm-form');
    if (form) {
      form.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const body = form.querySelector('#cm-body').value.trim();
        if (!body) { toast('Comment cannot be empty', true); return; }
        const btn = form.querySelector('button');
        btn.disabled = true;
        try {
          await api('/products/' + p.id + '/comments', { method: 'POST', body: { body } });
          page = 1;
          await load();
        } catch (e) {
          toast(e.message, true);
          btn.disabled = false;
        }
      });
    }

    box.querySelectorAll('[data-cm-page]').forEach(btn => {
      btn.addEventListener('click', async () => { page = Number(btn.dataset.cmPage); await load(); });
    });

    box.querySelectorAll('[data-reply]').forEach(btn => {
      btn.addEventListener('click', () => {
        const f = box.querySelector(`[data-reply-form="${btn.dataset.reply}"]`);
        if (f) { f.hidden = !f.hidden; if (!f.hidden) f.querySelector('input').focus(); }
      });
    });

    box.querySelectorAll('[data-reply-form]').forEach(f => {
      f.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const body = f.querySelector('input').value.trim();
        if (!body) { toast('Reply cannot be empty', true); return; }
        try {
          await api('/products/' + p.id + '/comments', {
            method: 'POST',
            body: { body, parent_id: Number(f.dataset.replyForm) },
          });
          await load();
        } catch (e) { toast(e.message, true); }
      });
    });

    box.querySelectorAll('[data-cdel]').forEach(btn => {
      btn.addEventListener('click', async () => {
        if (!confirm('Delete this comment?')) return;
        try {
          await api('/products/' + p.id + '/comments/' + btn.dataset.cdel, { method: 'DELETE' });
          await load();
        } catch (e) { toast(e.message, true); }
      });
    });
  }

  load();
}

function renderProductReviews(root, p) {
  const box = root.querySelector('#reviews');
  if (!box) return;
  let page = 1;

  async function load() {
    box.innerHTML = '<h2>Reviews</h2><div class="loading">Loading…</div>';
    let data, pg;
    try {
      const res = await api('/products/' + p.id + '/reviews?page=' + page + '&per_page=5');
      data = res.data || [];
      pg = res.meta.pagination || {};
    } catch (e) {
      box.innerHTML = `<h2>Reviews</h2><div class="empty">${esc(e.message)}</div>`;
      return;
    }

    const rows = data.map(r => {
      const canReply = state.user && Number(state.user.id) === Number(p.seller_id);
      const canHide = state.user && state.user.is_admin;
      return `
        <div class="row">
          ${avatarTag({ username: r.buyer_name, avatar_path: r.buyer_avatar_path }, 'avatar', 36)}
          <div class="row-main">
            <span class="t"><span class="stars">${stars(r.rating)}</span> ${esc(r.buyer_name || 'Anonymous')}</span>
            <span class="s">${esc(r.comment || '(No comments)')}</span>
            ${r.reply_text ? `<div class="review-reply"><strong>Seller reply:</strong>${esc(r.reply_text)}</div>` : ''}
            ${canReply ? `<form class="reply-form" data-rreply-form="${r.id}" hidden>
              <input maxlength="1000" value="${esc(r.reply_text || '')}" placeholder="Reply to this review…">
              <button class="btn btn-sm" type="submit">Send</button>
            </form>` : ''}
            ${canReply || canHide ? `<div class="comment-actions">
              ${canReply ? `<button class="btn btn-ghost btn-sm" data-rreply="${r.id}" type="button">Reply / Edit</button>` : ''}
              ${canHide ? `<button class="btn btn-ghost btn-sm" data-rhide="${r.id}" type="button">Hide</button>` : ''}
            </div>` : ''}
          </div>
          <div class="row-side"><span class="s" style="color:var(--text-faint);font-size:.8rem">${esc(shortTimeAgo(r.created_at))}</span></div>
        </div>`;
    }).join('');

    box.innerHTML = `<h2>Reviews <span class="count">${pg.total || 0}</span></h2>
      ${data.length ? `<div class="list">${rows}</div>` : '<div class="empty" style="padding:1.5rem">No reviews yet. You can review the seller after a completed order.</div>'}
      ${commentPager(pg, 'data-rpage')}`;

    box.querySelectorAll('[data-rpage]').forEach(btn => {
      btn.addEventListener('click', async () => { page = Number(btn.dataset.rpage); await load(); });
    });

    box.querySelectorAll('[data-rreply]').forEach(btn => {
      btn.addEventListener('click', () => {
        const f = box.querySelector(`[data-rreply-form="${btn.dataset.rreply}"]`);
        if (f) { f.hidden = !f.hidden; if (!f.hidden) f.querySelector('input').focus(); }
      });
    });

    box.querySelectorAll('[data-rreply-form]').forEach(f => {
      f.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const reply = f.querySelector('input').value.trim();
        if (!reply) { toast('Reply cannot be empty', true); return; }
        try {
          await api('/reviews/' + f.dataset.rreplyForm + '/reply', { method: 'POST', body: { reply } });
          await load();
        } catch (e) { toast(e.message, true); }
      });
    });

    box.querySelectorAll('[data-rhide]').forEach(btn => {
      btn.addEventListener('click', async () => {
        if (!confirm('Hide this review?')) return;
        try {
          await api('/admin/reviews/' + btn.dataset.rhide, { method: 'PATCH', body: { is_hidden: true } });
          await load();
        } catch (e) { toast(e.message, true); }
      });
    });
  }

  load();
}

function renderDetailActions(container, p, isOwner) {
  if (p.is_sold) {
    container.innerHTML = '<p class="form-hint">This item has already been sold.</p>';
    return;
  }

  if (!state.user) {
    container.innerHTML = `<a class="btn" href="#/login?next=${encodeURIComponent('#/product/' + p.id)}" data-link>Log in to buy</a>`;
    return;
  }

  if (isOwner) {
    container.innerHTML = `
      <p class="form-hint" style="width:100%">This is your listing.</p>
      <a class="btn btn-outline" href="#/seller/${p.seller_id}" data-link>My profile</a>`;
    return;
  }

  container.innerHTML = `
    <button class="btn" id="add-cart" type="button">Add to cart</button>
    <button class="btn btn-outline" id="buy-now" type="button">Buy now</button>`;

  container.querySelector('#add-cart').addEventListener('click', async (ev) => {
    const btn = ev.currentTarget;
    btn.disabled = true;
    try {
      const { data } = await api('/cart', { method: 'POST', body: { product_id: Number(p.id) } });
      toast(data.created ? 'Added to cart' : 'This item is already in your cart');
      refreshCartBadge();
    } catch (e) {
      toast(e.message, true);
    } finally {
      btn.disabled = false;
    }
  });

  container.querySelector('#buy-now').addEventListener('click', async (ev) => {
    const btn = ev.currentTarget;
    btn.disabled = true;
    try {
      // add to cart first, then check that item out immediately — reusing one backend path avoids a "checkout that skips the cart" branch
      const added = await api('/cart', { method: 'POST', body: { product_id: Number(p.id) } });
      const { data } = await api('/orders', { method: 'POST', body: { cart_ids: [added.data.id] } });
      refreshCartBadge();
      openPaymentDialog(data.orders);
    } catch (e) {
      toast(e.message, true);
      btn.disabled = false;
    }
  });
}

function openPaymentDialog(orders) {
  const total = orders.reduce((sum, order) => sum + Number(order.total_price || 0), 0);
  const level = Number(state.user && state.user.level) || 1;
  const discountLine = level > 1
    ? `<p style="color:#1e6f43;font-size:.8rem;margin:.2rem 0 0">Lv${level} Discount (-${level - 1}%)</p>`
    : '';
  const backdrop = document.createElement('div');
  backdrop.className = 'panel';
  backdrop.style.cssText = 'position:fixed;inset:0;max-width:none;margin:0;border-radius:0;' +
                           'background:rgba(0,0,0,.45);display:grid;place-items:center;z-index:300;padding:1rem;box-shadow:none;border:none;';
  backdrop.innerHTML = `
    <div class="panel" style="margin:0;width:min(94vw,460px)">
      <h2>Payment</h2>
      <p class="form-hint">Total: ${money(total)}</p>
      ${discountLine}
      <div class="tabs" id="pay-tabs" style="margin-bottom:1rem">
        <button class="tab active" data-pay-tab="card" type="button">Bank Card</button>
        <button class="tab" data-pay-tab="qr" type="button">QR Code</button>
      </div>
      <div id="pay-card">
        <div class="form-row">
          <label for="pay-card-no">Card number</label>
          <input id="pay-card-no" inputmode="numeric" maxlength="19" placeholder="1234 5678 9012 3456">
        </div>
        <div class="form-row">
          <label for="pay-exp">Expiry</label>
          <input id="pay-exp" inputmode="numeric" maxlength="5" placeholder="MM/YY">
        </div>
        <div class="form-row">
          <label for="pay-cvv">CVV</label>
          <input id="pay-cvv" type="password" inputmode="numeric" maxlength="4" placeholder="123">
        </div>
        <div id="pay-error"></div>
        <div class="form-actions">
          <button class="btn" id="pay-submit" type="button">Pay</button>
          <button class="btn btn-outline" id="pay-cancel" type="button">Cancel</button>
        </div>
      </div>
      <div id="pay-qr" hidden style="text-align:center">
        <div class="qr-frame">
          <img alt="QR code" src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAUAAAAFAAQMAAAD3XjfpAAAABlBMVEX///8AAABVwtN+AAAACXBIWXMAAA7EAAAOxAGVKw4bAAACyklEQVRoge2aMZKkMAxFTREQcgSOwtGao3GUPgIhAYVWX19y9+wy0FXDRiNHgF93IKu+v2SX8itHKxz6+Jge+vAcnv3Sy6ozW7M3Pj0neAFuiGYjRRQso5Gy9CtjrKBF+5HgJaghr+8yj+BKv3aiE7v9g8iU4Mcg30d54hcacJAJ/kewyAREyQFC0a2uFAl+CIZS6KsKqw4ohZIKWMD/kZQEj8DYuaC4mrjzgIAv3dpt7fEWl+CPwNdAJhcFRRdmwQdI89FI8AC0gDOuk3oFG+A6BnrXheBEgufgBna3gNO6FigurOtWGv1OyU3wLhAD68Kn0UDxWiH+wLa4BE9BbHER1yk8LjisRUMyMjzBExAO1YSCO5fQcplV2IpvXBbwBG8BYwiaBchxNAvArW7bMBFKkeC3YIu4ulbA447RLLAqV/w7vVmC34NsragjYMQ1cVG9AtxoI6wYeyR4F9iiJAg9YMaHVaBne6uGEzwHKRW0XHM4hc4Sf38pRYKnoLAnIOZPmbcW725rWUO8JCXBG0BIxR5bHL3Zm1I4SUlJ8BT0V75b19UdF/e+YiqS4CXotWstUNEs6F0pWESEFCd4A4hmAZWCgjC6UvB8pnFpljnBc9DPC2yLk6lK7lqiW1iVIsFT0KUC3WvuXU9KRfS1vyhFgj8GUZw13iy0VB7Q5gaI7oz1uWsfN8ET0B0BtXbEEijKnkuE3KrfBK9AG0bCcrlSdGuJ2rW8HSwm+HOwXsSYXt5s6WRta7PQTESCp2Ad8Y78xjnXWo8LyiPBS/Dt/NoEoQxPU4pCxW3qRII3gXFDo7x1C/ulWJu78fL3y1WOBI/Btt68ckUYeM7V1i2OGZ7gh6BZLlNcizcXov4+wdtBq83moXYLtfpteEPj7wxP8AB0pTBB8G5hv9ipq1W51JAEr8C6c9npCm9e2Z1hNAuRudGdSfAW8FeOP3B7DNoe4HSQAAAAAElFTkSuQmCC">
        </div>
        <p style="margin:.7rem 0 .3rem">Scan to pay</p>
        <p class="form-hint" id="pay-qr-countdown"></p>
        <div id="pay-qr-error"></div>
        <button class="btn btn-outline btn-sm" id="pay-qr-cancel" type="button">Cancel</button>
      </div>
    </div>`;

  document.body.appendChild(backdrop);

  const cardPanel = backdrop.querySelector('#pay-card');
  const qrPanel = backdrop.querySelector('#pay-qr');
  const qrCountdown = backdrop.querySelector('#pay-qr-countdown');
  const qrError = backdrop.querySelector('#pay-qr-error');
  const errorBox = backdrop.querySelector('#pay-error');
  const cardNo = backdrop.querySelector('#pay-card-no');
  const expiry = backdrop.querySelector('#pay-exp');
  const cvv = backdrop.querySelector('#pay-cvv');
  const tabs = backdrop.querySelectorAll('#pay-tabs .tab');
  let qrTimer = null;

  function closePayment() {
    if (qrTimer !== null) {
      clearInterval(qrTimer);
      qrTimer = null;
    }
    backdrop.remove();
  }

  function cancelPayment() {
    closePayment();
    navigate('/orders');
  }

  async function payAll() {
    for (const order of orders) {
      await api('/orders/' + order.id + '/pay', { method: 'POST' });
    }
    toast('Payment successful');
    closePayment();
    navigate('/orders');
  }

  function showCard() {
    if (qrTimer !== null) {
      clearInterval(qrTimer);
      qrTimer = null;
    }
    cardPanel.hidden = false;
    qrPanel.hidden = true;
    tabs.forEach(tab => tab.classList.toggle('active', tab.dataset.payTab === 'card'));
  }

  function showQr() {
    if (qrTimer !== null) {
      clearInterval(qrTimer);
      qrTimer = null;
    }
    cardPanel.hidden = true;
    qrPanel.hidden = false;
    tabs.forEach(tab => tab.classList.toggle('active', tab.dataset.payTab === 'qr'));

    let remaining = 5;
    qrCountdown.textContent = `Auto-confirming in ${remaining}s`;
    qrTimer = setInterval(async () => {
      remaining -= 1;
      if (remaining > 0) {
        qrCountdown.textContent = `Auto-confirming in ${remaining}s`;
        return;
      }
      clearInterval(qrTimer);
      qrTimer = null;
      qrCountdown.textContent = 'Confirming…';
      try {
        await payAll();
      } catch (e) {
        qrCountdown.textContent = '';
        qrError.innerHTML = `<div class="alert">${esc(e.message)}</div>`;
      }
    }, 1000);
  }

  cardNo.addEventListener('input', () => {
    const digits = cardNo.value.replace(/\D/g, '').slice(0, 16);
    cardNo.value = digits.replace(/(\d{4})(?=\d)/g, '$1 ');
  });
  expiry.addEventListener('input', () => {
    const digits = expiry.value.replace(/\D/g, '').slice(0, 4);
    expiry.value = digits.length > 2 ? digits.slice(0, 2) + '/' + digits.slice(2) : digits;
  });
  cvv.addEventListener('input', () => {
    cvv.value = cvv.value.replace(/\D/g, '').slice(0, 4);
  });

  tabs.forEach(tab => {
    tab.addEventListener('click', () => {
      if (tab.dataset.payTab === 'qr') showQr();
      else showCard();
    });
  });

  backdrop.querySelector('#pay-cancel').addEventListener('click', cancelPayment);
  backdrop.querySelector('#pay-qr-cancel').addEventListener('click', cancelPayment);

  backdrop.querySelector('#pay-submit').addEventListener('click', async () => {
    const digits = cardNo.value.replace(/\D/g, '');
    if (digits.length !== 16) {
      errorBox.innerHTML = '<div class="alert">Enter a 16-digit card number.</div>';
      return;
    }
    if (expiry.value.length !== 5 || cvv.value.length < 3) {
      errorBox.innerHTML = '<div class="alert">Enter a valid expiry and CVV.</div>';
      return;
    }

    errorBox.innerHTML = '';
    const btn = backdrop.querySelector('#pay-submit');
    btn.disabled = true;
    btn.textContent = 'Loading…';

    try {
      await new Promise(resolve => setTimeout(resolve, 1000));
      await payAll();
    } catch (e) {
      errorBox.innerHTML = `<div class="alert">${esc(e.message)}</div>`;
      btn.disabled = false;
      btn.textContent = 'Pay';
    }
  });
}

/* ============================ 7. View: login / register ============================ */

function viewLogin(root, route) {
  const next = route.query.next || '#/';
  root.innerHTML = `
    <div class="panel">
      <h1>Log in</h1>
      <div id="login-alert"></div>
      <form id="login-form" novalidate>
        <div class="form-row">
          <label for="l-email">Email</label>
          <input type="email" id="l-email" name="email" autocomplete="email" required>
        </div>
        <div class="form-row">
          <label for="l-pw">Password</label>
          <input type="password" id="l-pw" name="password" autocomplete="current-password" required>
        </div>
        <div class="form-actions">
          <button class="btn btn-block" type="submit">Log in</button>
        </div>
      </form>
      <p class="form-foot">Don’t have an account? <a href="#/register" data-link>Create one</a></p>
      <div class="form-foot" style="margin-top:.35rem;text-align:center"><a href="#" id="forgot-link">Forgot Password?</a></div>
    </div>`;

  root.querySelector('#login-form').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const form = ev.currentTarget;
    const btn = form.querySelector('button[type=submit]');
    const alertBox = root.querySelector('#login-alert');
    alertBox.innerHTML = '';
    btn.disabled = true;

    try {
      const { data } = await api('/auth/login', {
        method: 'POST',
        body: {
          email: form.elements.email.value.trim(),
          password: form.elements.password.value,
        },
      });
      setUser(data);
      toast(`Welcome back, ${data.username}`);
      navigate(next);
    } catch (e) {
      alertBox.innerHTML = `<div class="alert">${esc(e.message)}</div>`;
      btn.disabled = false;
    }
  });

  root.querySelector('#forgot-link').addEventListener('click', (ev) => {
    ev.preventDefault();
    openForgotPasswordDialog();
  });
}

function openForgotPasswordDialog() {
  const backdrop = document.createElement('div');
  backdrop.className = 'panel';
  backdrop.style.cssText = 'position:fixed;inset:0;max-width:none;margin:0;border-radius:0;' +
                           'background:rgba(0,0,0,.45);display:grid;place-items:center;z-index:300;padding:1rem;box-shadow:none;border:none;';
  backdrop.innerHTML = `
    <div class="panel" style="margin:0;width:min(92vw,420px)">
      <h2>Forgot Password?</h2>
      <div id="fp-status"></div>
      <form id="fp-email-form">
        <div class="form-row">
          <label for="fp-email">Email</label>
          <input type="email" id="fp-email" required>
        </div>
        <div class="form-actions">
          <button class="btn" type="submit" style="min-width:132px;font-variant-numeric:tabular-nums;white-space:nowrap">Send Code</button>
          <button class="btn btn-outline" type="button" id="fp-cancel">Cancel</button>
        </div>
      </form>
      <form id="fp-reset-form" hidden>
        <div class="form-row">
          <label for="fp-code">Code</label>
          <input type="text" id="fp-code" maxlength="6" required>
        </div>
        <div class="form-row">
          <label for="fp-new-password">New password</label>
          <input type="password" id="fp-new-password" minlength="6" required>
        </div>
        <div class="form-actions">
          <button class="btn" type="submit">Reset Password</button>
        </div>
      </form>
    </div>`;

  document.body.appendChild(backdrop);
  const status = backdrop.querySelector('#fp-status');
  const emailInput = backdrop.querySelector('#fp-email');
  const resetForm = backdrop.querySelector('#fp-reset-form');
  const emailForm = backdrop.querySelector('#fp-email-form');
  let resendTimer = null;

  function closeDialog() {
    if (resendTimer !== null) {
      clearInterval(resendTimer);
      resendTimer = null;
    }
    backdrop.remove();
  }

  backdrop.querySelector('#fp-cancel').addEventListener('click', closeDialog);

  emailForm.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const btn = emailForm.querySelector('button[type=submit]');
    status.innerHTML = '';
    btn.disabled = true;

    try {
      const { data } = await api('/auth/forgot-password', {
        method: 'POST',
        body: { email: emailInput.value.trim() },
      });
      if (!document.body.contains(backdrop)) return;
      if (!data || !data.code) {
        status.innerHTML = '<div class="alert">If the email exists, a code has been sent.</div>';
        btn.disabled = false;
        return;
      }
      status.innerHTML = `<div style="color:#1e6f43;font-size:.85rem;margin-bottom:.6rem">Demo Mode: Your code is ${esc(data.code)}</div>`;
      resetForm.hidden = false;

      let remaining = 30;
      btn.textContent = `Resend in ${remaining}s`;
      resendTimer = setInterval(() => {
        remaining -= 1;
        if (remaining <= 0) {
          clearInterval(resendTimer);
          resendTimer = null;
          btn.textContent = 'Send Code';
          btn.disabled = false;
          return;
        }
        btn.textContent = `Resend in ${remaining}s`;
      }, 1000);
    } catch (e) {
      status.innerHTML = `<div class="alert">${esc(e.message)}</div>`;
      btn.disabled = false;
    }
  });

  resetForm.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const btn = resetForm.querySelector('button[type=submit]');
    btn.disabled = true;

    try {
      await api('/auth/reset-password', {
        method: 'POST',
        body: {
          email: emailInput.value.trim(),
          code: resetForm.querySelector('#fp-code').value.trim(),
          new_password: resetForm.querySelector('#fp-new-password').value,
        },
      });
      toast('Password reset. Log in with your new password.');
      closeDialog();
    } catch (e) {
      status.innerHTML = `<div class="alert">${esc(e.message)}</div>`;
      btn.disabled = false;
    }
  });
}

function viewRegister(root) {
  root.innerHTML = `
    <div class="panel">
      <h1>Sign up</h1>
      <div id="reg-alert"></div>
      <form id="reg-form" novalidate>
        <div class="form-row" data-field="username">
          <label for="r-user">Username</label>
          <input type="text" id="r-user" name="username" autocomplete="username" required>
          <div class="err"></div>
        </div>
        <div class="form-row" data-field="email">
          <label for="r-email">Email</label>
          <input type="email" id="r-email" name="email" autocomplete="email" required>
          <div class="err"></div>
        </div>
        <div class="form-row" data-field="password">
          <label for="r-pw">Password</label>
          <input type="password" id="r-pw" name="password" autocomplete="new-password" required>
          <div class="form-hint">At least 6 characters</div>
          <div class="err"></div>
        </div>
        <div class="form-row" data-field="password_confirmation">
          <label for="r-pw2">Confirm password</label>
          <input type="password" id="r-pw2" name="password_confirmation" autocomplete="new-password" required>
          <div class="err"></div>
        </div>
        <div class="form-actions">
          <button class="btn btn-block" type="submit">Create account</button>
        </div>
      </form>
      <p class="form-foot">Already have an account? <a href="#/login" data-link>Log in</a></p>
    </div>`;

  root.querySelector('#reg-form').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const form = ev.currentTarget;
    const btn = form.querySelector('button[type=submit]');
    const alertBox = root.querySelector('#reg-alert');

    // clear field errors from the previous attempt
    root.querySelectorAll('.form-row').forEach(r => {
      r.classList.remove('invalid');
      const err = r.querySelector('.err');
      if (err) err.textContent = '';
    });
    alertBox.innerHTML = '';

    // catch obvious mistakes on the client first (the backend validates again; this layer is only for UX)
    const values = {
      username: form.elements.username.value.trim(),
      email: form.elements.email.value.trim(),
      password: form.elements.password.value,
      password_confirmation: form.elements.password_confirmation.value,
    };
    const localErrors = {};
    if (!values.username) localErrors.username = 'Enter your username';
    if (!values.email) localErrors.email = 'Enter your email';
    if (!values.password) localErrors.password = 'Enter your password';
    else if (values.password.length < 6) localErrors.password = 'Password must be at least 6 characters';
    else if (values.password !== values.password_confirmation) {
      localErrors.password_confirmation = 'The passwords do not match';
    }

    if (Object.keys(localErrors).length > 0) {
      showFieldErrors(root, localErrors);
      return;
    }

    btn.disabled = true;
    try {
      const { data } = await api('/auth/register', { method: 'POST', body: values });
      setUser(data);
      toast('Account created. You are now logged in.');
      navigate('/');
    } catch (e) {
      if (e.status === 422 && e.details && Object.keys(e.details).length > 0) {
        showFieldErrors(root, e.details);
      } else {
        alertBox.innerHTML = `<div class="alert">${esc(e.message)}</div>`;
      }
      btn.disabled = false;
    }
  });
}

function showFieldErrors(root, details) {
  for (const [field, message] of Object.entries(details)) {
    const row = root.querySelector(`.form-row[data-field="${field}"]`);
    if (!row) continue;
    row.classList.add('invalid');
    const err = row.querySelector('.err');
    if (err) err.textContent = message;
  }
  const first = root.querySelector('.form-row.invalid input');
  if (first) first.focus();
}

/* ============================ 8. View: publish product ============================ */

async function viewPublish(root, route) {
  const editId = route && route.query && route.query.edit ? Number(route.query.edit) : 0;
  let product = null;

  if (editId) {
    try {
      const res = await api('/products/' + editId);
      product = res.data;
    } catch (e) {
      root.innerHTML = `<div class="empty"><h3>Cannot open item</h3><p>${esc(e.message)}</p>
        <p><a href="#/my-products" data-link>Back to my listings</a></p></div>`;
      return;
    }

    if (!state.user || Number(state.user.id) !== Number(product.seller_id)) {
      root.innerHTML = `<div class="empty"><h3>No permission</h3><p>You can only edit your own items.</p>
        <p><a href="#/my-products" data-link>Back to my listings</a></p></div>`;
      return;
    }

    if (product.is_sold) {
      root.innerHTML = `<div class="empty"><h3>Sold</h3><p>Sold items cannot be edited.</p>
        <p><a href="#/product/${product.id}" data-link>View item</a></p></div>`;
      return;
    }
  }

  const isEdit = !!product;

  root.innerHTML = `
    <div class="panel panel-wide">
      <h1>${isEdit ? 'Edit item' : 'Publish item'}</h1>
      <div id="pub-alert"></div>
      <form id="pub-form" novalidate>
        <div class="form-row" data-field="title">
          <label for="p-title">Title</label>
          <input type="text" id="p-title" name="title" maxlength="100" required
                 value="${isEdit ? esc(product.title) : ''}" placeholder="e.g. iPad Air 10.9-inch">
          <div class="err"></div>
        </div>
        <div class="form-row" data-field="description">
          <label for="p-desc">Description</label>
          <textarea id="p-desc" name="description" maxlength="5000"
                    placeholder="Condition, purchase date, accessories, pickup…">${isEdit ? esc(product.description || '') : ''}</textarea>
          <div class="err"></div>
        </div>
        <div class="form-row" data-field="price">
          <label for="p-price">Price (HK$)</label>
          <input type="number" id="p-price" name="price" min="0.01" step="0.01" required
                 value="${isEdit ? product.price : ''}">
          <div class="form-hint" id="p-price-fee" hidden></div>
          <div class="err"></div>
        </div>
        <div class="form-row" data-field="category_id">
          <label for="p-cat-parent">Category</label>
          <div class="cat-selects">
            <select id="p-cat-parent"><option value="">Choose a category</option></select>
            <select id="p-cat-child" hidden><option value="">Choose a subcategory</option></select>
          </div>
          <div class="err"></div>
        </div>
        <div class="form-row" data-field="status">
          <label for="p-status">Condition</label>
          <select id="p-status" name="status" required>
            <option value="new">New</option>
            <option value="used" selected>Used</option>
            <option value="damaged">Damaged</option>
          </select>
          <div class="err"></div>
        </div>
        <div class="form-row" data-field="image">
          <label for="p-img">Image</label>
          ${isEdit && product.image_path ? `<div style="max-width:160px;margin-bottom:.5rem">${productImgTag(product.id, product.image_path, product.title)}</div>` : ''}
          <input type="file" id="p-img" name="image" accept="image/jpeg,image/png,image/gif,image/webp">
          <div class="form-hint">${isEdit ? 'Leave empty to keep the current image.' : 'JPG / PNG / GIF / WebP, up to 5 MB.'}</div>
          <div class="err"></div>
        </div>
        <div class="form-actions">
          <button class="btn" type="submit">${isEdit ? 'Save changes' : 'Publish'}</button>
          <a class="btn btn-outline" href="${isEdit ? '#/product/' + product.id : '#/'}" data-link>Cancel</a>
        </div>
      </form>
    </div>`;

  const parentSel = root.querySelector('#p-cat-parent');
  const childSel = root.querySelector('#p-cat-child');
  if (isEdit) root.querySelector('#p-status').value = product.status;

  let categories = [];

  function fillChildOptions(parentId) {
    const children = categories.filter(c => Number(c.parent_id) === Number(parentId));
    childSel.innerHTML = '<option value="">Choose a subcategory</option>';
    for (const c of children) {
      const opt = document.createElement('option');
      opt.value = c.id;
      opt.textContent = c.name;
      childSel.appendChild(opt);
    }
    childSel.hidden = children.length === 0;
  }

  parentSel.addEventListener('change', () => {
    fillChildOptions(parentSel.value);
  });

  try {
    const { data } = await api('/categories');
    categories = data || [];
    for (const c of categories.filter(x => x.parent_id === null)) {
      const opt = document.createElement('option');
      opt.value = c.id;
      opt.textContent = c.name;
      parentSel.appendChild(opt);
    }
    if (isEdit && product.category_id !== null) {
      const selected = categories.find(c => Number(c.id) === Number(product.category_id));
      const parentId = selected && selected.parent_id !== null ? Number(selected.parent_id) : Number(product.category_id);
      parentSel.value = String(parentId);
      fillChildOptions(parentId);
      if (selected && selected.parent_id !== null) {
        childSel.value = String(product.category_id);
      }
    }
  } catch { /* a failed category load does not block the form; the backend still validates */ }

  const priceInput = root.querySelector('#p-price');
  const feeHint = root.querySelector('#p-price-fee');
  const updateFeeHint = () => {
    const price = Number(priceInput.value);
    if (!Number.isFinite(price) || price <= 0) {
      feeHint.hidden = true;
      return;
    }
    feeHint.hidden = false;
    feeHint.textContent = `Platform fee (10%): ${money(price * 0.10)} | You will receive: ${money(price * 0.90)}`;
  };
  priceInput.addEventListener('input', updateFeeHint);
  updateFeeHint();

  root.querySelector('#pub-form').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const form = ev.currentTarget;
    const btn = form.querySelector('button[type=submit]');
    const alertBox = root.querySelector('#pub-alert');

    clearFieldErrors(root);
    alertBox.innerHTML = '';

    if (!childSel.hidden && !childSel.value) {
      showFieldErrors(root, { category_id: 'Choose a subcategory' });
      return;
    }

    const fd = new FormData();
    fd.append('title', form.elements.title.value.trim());
    fd.append('description', form.elements.description.value.trim());
    fd.append('price', form.elements.price.value);
    fd.append('status', form.elements.status.value);
    const cat = childSel.value || parentSel.value;
    if (cat) fd.append('category_id', cat);
    else if (isEdit) fd.append('category_id', '');
    const file = form.elements.image.files[0];
    if (file) fd.append('image', file);

    btn.disabled = true;
    try {
      const { data } = isEdit
        ? await api('/products/' + product.id, { method: 'POST', form: fd })
        : await api('/products', { method: 'POST', form: fd });
      toast(isEdit ? 'Changes saved' : 'Published');
      navigate('/product/' + data.id);
    } catch (e) {
      if (e.status === 422 && e.details && Object.keys(e.details).length > 0) {
        showFieldErrors(root, e.details);
      } else {
        alertBox.innerHTML = `<div class="alert">${esc(e.message)}</div>`;
      }
      btn.disabled = false;
    }
  });
}

/* ============================ 9. View: cart ============================ */

async function viewCart(root) {
  root.innerHTML = '<div class="loading">Loading…</div>';

  async function load() {
    let summary;
    try {
      ({ data: summary } = await api('/cart'));
    } catch (e) {
      root.innerHTML = `<div class="empty"><h3>Failed to load cart</h3><p>${esc(e.message)}</p></div>`;
      return;
    }

    if (!summary.items || summary.items.length === 0) {
      root.innerHTML = `<div class="page-head"><h1>Cart</h1></div>
        <div class="empty"><h3>Your cart is empty</h3>
        <p><a href="#/" data-link>Browse</a></p></div>`;
      updateCartBadge(0);
      return;
    }

    const level = Number(state.user && state.user.level) || 1;
    const discountRate = 1 - (level - 1) * 0.01;
    const discountedTotal = summary.total * discountRate;
    const discountLine = level > 1
      ? `<div class="cart-discount">Lv${level} Discount (-${level - 1}%)</div>`
      : '';

    root.innerHTML = `
      <div class="page-head"><h1>Cart</h1>
        <span class="count">${summary.quantity} items · Total ${money(summary.total)}</span>
        <span class="spacer"></span>
        <button class="btn btn-danger btn-sm" id="cart-clear" type="button">Clear</button>
      </div>
      <div class="list">
        ${summary.items.map(item => `
          <div class="row ${item.available ? '' : 'invalid'}" data-cart-id="${item.id}">
            <div class="row-thumb">${productImgTag(item.product_id, item.image_path, item.title)}</div>
            <div class="row-main">
              <a class="t" href="#/product/${item.product_id}" data-link>${esc(item.title)}</a>
              <span class="s cart-item-price">
                ${level > 1
                  ? `<del class="price-was">${money(item.price)}</del> <span class="price-now">${money(item.price * discountRate)}</span>`
                  : `<strong>${money(item.price)}</strong>`}
                ${item.available ? '' : ' · Sold items are skipped at checkout'}
              </span>
            </div>
            <div class="row-side">
              <button class="btn btn-danger btn-sm" data-remove="${item.id}" type="button">Remove</button>
            </div>
          </div>`).join('')}
      </div>
      <div class="summary">
        <div>
          <div style="font-size:.85rem;color:var(--text-muted)">Total ${summary.quantity} items</div>
          ${discountLine}
          <div class="cart-total">
            ${level > 1 ? `<del class="cart-total-was">${money(summary.total)}</del>` : ''}
            <span class="cart-total-now">${money(discountedTotal)}</span>
          </div>
        </div>
        <button class="btn" id="checkout" type="button" ${summary.quantity === 0 ? 'disabled' : ''}>Checkout</button>
      </div>`;

    updateCartBadge(summary.item_count);

    root.querySelectorAll('[data-remove]').forEach(btn => {
      btn.addEventListener('click', async () => {
        btn.disabled = true;
        try {
          await api('/cart/' + btn.dataset.remove, { method: 'DELETE' });
          toast('Removed');
          await load();
        } catch (e) {
          toast(e.message, true);
          btn.disabled = false;
        }
      });
    });

    root.querySelector('#cart-clear').addEventListener('click', async (ev) => {
      if (!confirm('Clear your cart?')) return;
      ev.currentTarget.disabled = true;
      try {
        await api('/cart', { method: 'DELETE' });
        toast('Cart cleared');
        await load();
      } catch (e) {
        toast(e.message, true);
        ev.currentTarget.disabled = false;
      }
    });

    root.querySelector('#checkout').addEventListener('click', async (ev) => {
      ev.currentTarget.disabled = true;
      try {
        const { data } = await api('/orders', { method: 'POST', body: {} });
        openPaymentDialog(data.orders);
      } catch (e) {
        toast(e.message, true);
        ev.currentTarget.disabled = false;
      }
    });
  }

  await load();
}

/* ============================ 10. View: orders ============================ */

async function viewOrders(root) {
  root.innerHTML = `
    <div class="page-head"><h1>Orders</h1></div>
    <div class="filters" style="gap:.5rem">
      <button class="btn btn-sm" id="tab-buyer" type="button">Purchases</button>
      <button class="btn btn-outline btn-sm" id="tab-seller" type="button">Sales</button>
    </div>
    <div id="orders-wrap"><div class="loading">Loading…</div></div>`;

  const wrap = root.querySelector('#orders-wrap');
  const tabBuyer = root.querySelector('#tab-buyer');
  const tabSeller = root.querySelector('#tab-seller');

  async function load(role) {
    tabBuyer.className = 'btn btn-sm' + (role === 'buyer' ? '' : ' btn-outline');
    tabSeller.className = 'btn btn-sm' + (role === 'seller' ? '' : ' btn-outline');
    wrap.innerHTML = '<div class="loading">Loading…</div>';

    let rows;
    try {
      ({ data: rows } = await api('/orders?role=' + role));
    } catch (e) {
      wrap.innerHTML = `<div class="empty"><h3>Failed to load</h3><p>${esc(e.message)}</p></div>`;
      return;
    }

    if (!rows || rows.length === 0) {
      wrap.innerHTML = `<div class="empty"><h3>${role === 'buyer' ? 'No purchases yet' : 'No sales yet'}</h3>
        <p><a href="#/" data-link>Browse</a></p></div>`;
      return;
    }

    const STATUS_TEXT = { pending: 'Pending', completed: 'Completed', cancelled: 'Cancelled' };
    wrap.innerHTML = `<div class="list">${rows.map(o => `
      <div class="row">
        <div class="row-thumb">${productImgTag(o.product_id, o.image_path, o.title)}</div>
        <div class="row-main">
          <a class="t" href="#/product/${o.product_id}" data-link>${esc(o.title)}</a>
          <span class="s">
            ${money(o.total_price)} ·
            ${role === 'buyer' ? 'Seller ' + esc(o.seller_name || '') : 'Buyer ' + esc(o.buyer_name || '')} ·
            ${esc(shortTimeAgo(o.order_date))}
          </span>
        </div>
        <div class="row-side">
          <span class="tag ${o.status === 'completed' ? 'tag-new' : o.status === 'cancelled' ? 'tag-damaged' : 'tag-used'}">
            ${esc(STATUS_TEXT[o.status] || o.status)}
          </span>
          ${role === 'buyer' && !o.reviewed && o.status === 'completed'
            ? `<button class="btn btn-outline btn-sm" data-review="${o.id}" type="button">Reviews</button>` : ''}
          ${role === 'buyer' && o.reviewed
            ? `<span class="stars" title="Reviewed">${stars(o.review_rating)}</span>` : ''}
          ${role === 'buyer' && o.status === 'pending' && o.escrow_status === 'unpaid'
            ? `<button class="btn btn-danger btn-sm" data-cancel="${o.id}" type="button">Cancel</button>` : ''}
          ${role === 'buyer' && o.status === 'pending' && o.escrow_status === 'held'
            ? `<button class="btn btn-sm" data-confirm="${o.id}" type="button">Confirm Receipt</button>` : ''}
          ${role === 'seller' && o.status === 'pending' && o.escrow_status === 'held'
            ? '<span class="s">Payment held in escrow</span>' : ''}
          ${role === 'seller' && !o.reviewed && o.status === 'completed'
            ? `<button class="btn btn-outline btn-sm" data-review="${o.id}" type="button">Review buyer</button>` : ''}
          ${role === 'seller' && o.reviewed
            ? `<span class="stars" title="Reviewed">${stars(o.review_rating)}</span>` : ''}
        </div>
      </div>`).join('')}</div>`;

    wrap.querySelectorAll('[data-cancel]').forEach(btn => {
      btn.addEventListener('click', async () => {
        if (!confirm('Cancel this order? The item will be relisted.')) return;
        try {
          await api('/orders/' + btn.dataset.cancel, { method: 'PATCH', body: { status: 'cancelled' } });
          toast('Order cancelled');
          await load(role);
        } catch (e) { toast(e.message, true); }
      });
    });

    wrap.querySelectorAll('[data-confirm]').forEach(btn => {
      btn.addEventListener('click', async () => {
        if (!confirm('Confirm you have received this item? Funds will be released to the seller.')) return;
        btn.disabled = true;
        try {
          await api('/orders/' + btn.dataset.confirm + '/confirm', { method: 'POST' });
          toast('Receipt confirmed');
          await load(role);
        } catch (e) {
          toast(e.message, true);
          btn.disabled = false;
        }
      });
    });

    wrap.querySelectorAll('[data-review]').forEach(btn => {
      btn.addEventListener('click', () => openReviewDialog(btn.dataset.review, () => load(role), role));
    });
  }

  tabBuyer.addEventListener('click', () => load('buyer'));
  tabSeller.addEventListener('click', () => load('seller'));
  await load('buyer');
}

function openReviewDialog(orderId, onDone, role = 'buyer') {
  const backdrop = document.createElement('div');
  backdrop.className = 'panel';
  backdrop.style.cssText = 'position:fixed;inset:0;max-width:none;margin:0;border-radius:0;' +
                           'background:rgba(0,0,0,.45);display:grid;place-items:center;z-index:300;padding:1rem;box-shadow:none;border:none;';
  backdrop.innerHTML = `
    <form class="panel" style="margin:0;width:min(92vw,420px)">
      <h2>${role === 'seller' ? 'Review this buyer' : 'Review this seller'}</h2>
      <div id="rv-alert"></div>
      <div class="form-row">
        <label for="rv-rating">Rating</label>
        <select id="rv-rating">
          <option value="5">★★★★★ Very satisfied</option>
          <option value="4">★★★★☆ Satisfied</option>
          <option value="3">★★★☆☆ Neutral</option>
          <option value="2">★★☆☆☆ Unsatisfied</option>
          <option value="1">★☆☆☆☆ Very poor</option>
        </select>
      </div>
      <div class="form-row">
        <label for="rv-comment">Comments</label>
        <textarea id="rv-comment" maxlength="1000" placeholder="Tell us about the transaction…"></textarea>
      </div>
      <div class="form-actions">
        <button class="btn" type="submit">Submit review</button>
        <button class="btn btn-outline" type="button" id="rv-cancel">Cancel</button>
      </div>
    </form>`;

  document.body.appendChild(backdrop);
  backdrop.querySelector('#rv-cancel').addEventListener('click', () => backdrop.remove());

  backdrop.querySelector('form').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const btn = ev.currentTarget.querySelector('button[type=submit]');
    btn.disabled = true;
    try {
      await api('/orders/' + orderId + '/review', {
        method: 'POST',
        body: {
          rating: Number(backdrop.querySelector('#rv-rating').value),
          comment: backdrop.querySelector('#rv-comment').value.trim(),
        },
      });
      toast('Review submitted');
      backdrop.remove();
      onDone();
    } catch (e) {
      backdrop.querySelector('#rv-alert').innerHTML = `<div class="alert">${esc(e.message)}</div>`;
      btn.disabled = false;
    }
  });
}

/* ============================ 11. View: seller profile ============================ */

async function viewSeller(root, route) {
  const id = Number(route.params.id);
  root.innerHTML = '<div class="loading">Loading…</div>';

  let profile;
  try {
    ({ data: profile } = await api('/users/' + id));
  } catch (e) {
    root.innerHTML = `<div class="empty"><h3>Cannot open user</h3><p>${esc(e.message)}</p></div>`;
    return;
  }

  const isSelf = state.user && Number(state.user.id) === id;
  const rating = profile.rating || { count: 0, average: null };
  const buyerRating = profile.buyer_rating || { count: 0, average: null };

  root.innerHTML = `
    <div class="profile-head">
      ${avatarTag(profile, 'avatar', 72)}
      <div class="profile-main">
        <h1>${esc(profile.username)} ${isSelf ? '<span class="tag tag-new">Me</span>' : ''}</h1>
        <div class="profile-level"><span class="tag tag-level">Buyer Lv${Number(profile.level) || 1}</span> <span class="tag tag-level">Seller Lv${Number(profile.seller_level) || 1}</span></div>
        <p class="profile-bio">${esc(profile.bio || 'This user hasn’t added a bio.')}</p>
        <div class="profile-meta">Joined ${esc(shortTimeAgo(profile.created_at))}</div>
      </div>
      <div class="profile-stats">
        <div><strong>${profile.product_count || 0}</strong><span>Active</span></div>
        <div><strong>${profile.sold_count || 0}</strong><span>Sold</span></div>
        <div><strong>${rating.count || 0}</strong><span>Seller reviews</span></div>
        <div><strong>${rating.average === null ? '—' : rating.average}</strong><span>Seller rating</span></div>
        <div><strong>${buyerRating.average === null ? '—' : buyerRating.average}</strong><span>Buyer rating</span></div>
      </div>
      ${isSelf ? `<div class="profile-actions">
        <a class="btn btn-outline btn-sm" href="#/account" data-link>Account settings</a>
        <a class="btn btn-outline btn-sm" href="#/my-products" data-link>My listings</a>
      </div>` : ''}
    </div>

    <div class="tabs" id="profile-tabs">
      <button class="tab active" data-tab="products" type="button">Items for sale</button>
      <button class="tab" data-tab="reviews" type="button">Seller reviews (${rating.count || 0})</button>
      <button class="tab" data-tab="buyer-reviews" type="button">Buyer reviews (${buyerRating.count || 0})</button>
      <button class="tab" data-tab="guestbook" type="button">Guestbook</button>
    </div>
    <div id="profile-panel"><div class="loading">Loading…</div></div>`;

  const panel = root.querySelector('#profile-panel');
  const tabs = root.querySelectorAll('#profile-tabs .tab');
  const setActive = (name) => {
    tabs.forEach(t => t.classList.toggle('active', t.dataset.tab === name));
  };

  async function loadProducts() {
    panel.innerHTML = '<div class="loading">Loading…</div>';
    try {
      const { data } = await api('/products?seller_id=' + id + '&per_page=100');
      if (!data || data.length === 0) {
        panel.innerHTML = `<div class="empty">${isSelf ? 'You have no items for sale' : 'This user has no items for sale'}</div>`;
      } else {
        panel.innerHTML = `<div class="grid">${data.map(productCard).join('')}</div>`;
      }
    } catch (e) {
      panel.innerHTML = `<div class="empty">${esc(e.message)}</div>`;
    }
  }

  async function loadReviews(page = 1, role = 'seller') {
    panel.innerHTML = '<div class="loading">Loading…</div>';
    let data, pg;
    try {
      const res = await api('/users/' + id + '/reviews?role=' + role + '&page=' + page + '&per_page=5');
      data = res.data.items || [];
      pg = res.meta.pagination || {};
    } catch (e) {
      panel.innerHTML = `<div class="empty">${esc(e.message)}</div>`;
      return;
    }

    const rows = data.map(r => {
      const canReply = isSelf && role === 'seller';
      const canHide = state.user && state.user.is_admin;
      const author = role === 'buyer' ? r.seller_name : r.buyer_name;
      return `
        <div class="row">
          ${avatarTag({ username: author, avatar_path: role === 'buyer' ? null : r.buyer_avatar_path }, 'avatar', 40)}
          <div class="row-main">
            <span class="t"><span class="stars">${stars(r.rating)}</span> ${esc(author || 'Anonymous')}</span>
            <span class="s">${role === 'buyer' ? 'Seller review of buyer' : 'Buyer review of seller'} · ${esc(r.product_title || '')} · ${esc(shortTimeAgo(r.created_at))}</span>
            <span class="s">${esc(r.comment || '(No comments)')}</span>
            ${r.reply_text ? `<div class="review-reply"><strong>Seller reply:</strong>${esc(r.reply_text)}</div>` : ''}
            ${canReply ? `<form class="reply-form" data-rreply-form="${r.id}" hidden>
              <input maxlength="1000" value="${esc(r.reply_text || '')}" placeholder="Reply to this review…">
              <button class="btn btn-sm" type="submit">Send</button>
            </form>` : ''}
            ${canReply || canHide ? `<div class="comment-actions">
              ${canReply ? `<button class="btn btn-ghost btn-sm" data-rreply="${r.id}" type="button">Reply / Edit</button>` : ''}
              ${canHide ? `<button class="btn btn-ghost btn-sm" data-rhide="${r.id}" type="button">Hide</button>` : ''}
            </div>` : ''}
          </div>
        </div>`;
    }).join('');

    panel.innerHTML = `
      ${data.length ? `<div class="list">${rows}</div>` : '<div class="empty">No reviews yet</div>'}
      ${commentPager(pg, 'data-rpage')}`;

    panel.querySelectorAll('[data-rpage]').forEach(btn => {
      btn.addEventListener('click', () => loadReviews(Number(btn.dataset.rpage), role));
    });

    panel.querySelectorAll('[data-rreply]').forEach(btn => {
      btn.addEventListener('click', () => {
        const f = panel.querySelector(`[data-rreply-form="${btn.dataset.rreply}"]`);
        if (f) { f.hidden = !f.hidden; if (!f.hidden) f.querySelector('input').focus(); }
      });
    });

    panel.querySelectorAll('[data-rreply-form]').forEach(f => {
      f.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const reply = f.querySelector('input').value.trim();
        if (!reply) { toast('Reply cannot be empty', true); return; }
        try {
          await api('/reviews/' + f.dataset.rreplyForm + '/reply', { method: 'POST', body: { reply } });
          await loadReviews(page, role);
        } catch (e) { toast(e.message, true); }
      });
    });

    panel.querySelectorAll('[data-rhide]').forEach(btn => {
      btn.addEventListener('click', async () => {
        if (!confirm('Hide this review?')) return;
        try {
          await api('/admin/reviews/' + btn.dataset.rhide, { method: 'PATCH', body: { is_hidden: true } });
          await loadReviews(page, role);
        } catch (e) { toast(e.message, true); }
      });
    });
  }

  async function loadGuestbook(page = 1) {
    panel.innerHTML = '<div class="loading">Loading…</div>';
    let data, pg;
    try {
      const res = await api('/users/' + id + '/comments?page=' + page + '&per_page=5');
      data = res.data || [];
      pg = res.meta.pagination || {};
    } catch (e) {
      panel.innerHTML = `<div class="empty">${esc(e.message)}</div>`;
      return;
    }

    const form = state.user
      ? `<form id="guestbook-form" class="comment-form">
          <textarea id="guestbook-body" maxlength="1000" placeholder="Leave a message for ${esc(profile.username)}…"></textarea>
          <button class="btn btn-sm" type="submit">Comments</button>
        </form>`
      : `<p class="form-hint">Log in to leave a comment.<a href="#/login?next=${encodeURIComponent('#/seller/' + id)}" data-link>Log in</a></p>`;

    const rows = data.map(c => {
      const canDelete = state.user && (Number(state.user.id) === Number(c.author_id)
        || isSelf || state.user.is_admin);
      return `
        <div class="comment">
          <div class="comment-main">
            ${avatarTag({ username: c.author_name, avatar_path: c.author_avatar_path }, 'avatar', 36)}
            <div style="flex:1;min-width:0">
              <div class="comment-meta"><strong>${esc(c.author_name)}</strong> · ${esc(shortTimeAgo(c.created_at))}</div>
              <div class="comment-body">${esc(c.body)}</div>
              ${canDelete ? `<div class="comment-actions">
                <button class="btn btn-ghost btn-sm" data-gdel="${c.id}" type="button">Delete</button>
              </div>` : ''}
            </div>
          </div>
        </div>`;
    }).join('');

    panel.innerHTML = `
      ${form}
      ${data.length ? `<div>${rows}</div>` : '<div class="empty">No comments yet</div>'}
      ${commentPager(pg, 'data-gpage')}`;

    const f = panel.querySelector('#guestbook-form');
    if (f) {
      f.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const body = f.querySelector('#guestbook-body').value.trim();
        if (!body) { toast('Comment cannot be empty', true); return; }
        try {
          await api('/users/' + id + '/comments', { method: 'POST', body: { body } });
          await loadGuestbook(1);
        } catch (e) { toast(e.message, true); }
      });
    }

    panel.querySelectorAll('[data-gpage]').forEach(btn => {
      btn.addEventListener('click', () => loadGuestbook(Number(btn.dataset.gpage)));
    });

    panel.querySelectorAll('[data-gdel]').forEach(btn => {
      btn.addEventListener('click', async () => {
        if (!confirm('Delete this comment?')) return;
        try {
          await api('/users/' + id + '/comments/' + btn.dataset.gdel, { method: 'DELETE' });
          await loadGuestbook(page);
        } catch (e) { toast(e.message, true); }
      });
    });
  }

  tabs.forEach(tab => {
    tab.addEventListener('click', () => {
      const name = tab.dataset.tab;
      setActive(name);
      if (name === 'products') loadProducts();
      else if (name === 'reviews') loadReviews(1, 'seller');
      else if (name === 'buyer-reviews') loadReviews(1, 'buyer');
      else loadGuestbook(1);
    });
  });

  loadProducts();
}

/* ============================ 11b. View: account settings ============================ */

function clearFieldErrors(root) {
  root.querySelectorAll('.form-row').forEach(row => {
    row.classList.remove('invalid');
    const err = row.querySelector('.err');
    if (err) err.textContent = '';
  });
}

function memberSince(value) {
  const match = String(value || '').match(/^(\d{4})-(\d{2})/);
  if (!match) return 'Member since —';
  const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  return `Member since ${months[Number(match[2]) - 1]} ${match[1]}`;
}

async function viewAccount(root) {
  const u = state.user;
  if (!u) return;

  root.innerHTML = `
    <div class="panel panel-wide account-panel">
      <h1>Account settings</h1>

      <div class="tabs" id="acct-tabs" style="margin-bottom:1.2rem">
        <button class="tab active" data-acct-tab="profile" type="button">Profile</button>
        <button class="tab" data-acct-tab="security" type="button">Security</button>
      </div>

      <div id="acct-profile">
        <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;margin-bottom:1.4rem">
          <span id="acct-avatar">${avatarTag(u, 'avatar', 72)}</span>
          <div style="flex:1;min-width:0">
            <div style="font-weight:700;font-size:1.1rem">${esc(u.username)}</div>
            <div><span class="tag tag-level">Lv${Number(u.level) || 1}</span></div>
            <div style="color:var(--text-muted);font-size:.85rem">${memberSince(u.created_at)}</div>
            <div style="color:var(--text-muted);font-size:.85rem;margin-top:.25rem">Balance: HK$ ${Number(u.balance || 0).toFixed(2)} <button class="btn btn-outline btn-sm" id="acct-withdraw" type="button">Withdraw</button></div>
          </div>
          <a class="btn btn-outline btn-sm" href="#/user/${u.id}" data-link>My public profile</a>
        </div>

        <form id="avatar-form" style="margin-bottom:1.4rem">
          <div class="form-row">
            <label for="a-avatar">Avatar</label>
            <input type="file" id="a-avatar" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp">
            <div class="form-hint">JPG / PNG / GIF / WebP, up to 5 MB.</div>
          </div>
          <button class="btn btn-sm" type="submit">Upload avatar</button>
        </form>

        <h2 style="font-size:1.1rem;margin:1.5rem 0 .8rem">Profile</h2>
        <div id="profile-alert"></div>
        <form id="profile-form" novalidate>
          <div class="form-row" data-field="username">
            <label for="acct-username">Username</label>
            <input type="text" id="acct-username" name="username" maxlength="50" value="${esc(u.username)}" required>
            <div class="err"></div>
          </div>
          <div class="form-row" data-field="bio">
            <label for="acct-bio">Bio</label>
            <textarea id="acct-bio" name="bio" maxlength="255" placeholder="A short bio, e.g. third-year CS student selling textbooks and gadgets.">${esc(u.bio || '')}</textarea>
            <div class="err"></div>
          </div>
          <div class="form-row" data-field="meetup_address">
            <label for="acct-meetup">Meetup address</label>
            <input type="text" id="acct-meetup" name="meetup_address" maxlength="255" value="${esc(u.meetup_address || '')}" placeholder="e.g. Hung Hom, Hong Kong">
            <div class="err"></div>
          </div>
          <div class="form-actions"><button class="btn" type="submit">Save profile</button></div>
        </form>

      </div>

      <div id="acct-security" hidden>
        <h2 style="font-size:1.1rem;margin:1.5rem 0 .8rem">Email</h2>
        <div class="form-row">
          <label>Current email</label>
          <div id="acct-email-value" style="padding:.42rem .6rem;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg);font-size:.9rem">${esc(u.email || '')}</div>
        </div>
        <button class="btn btn-outline btn-sm" id="show-email-form" type="button">Change Email</button>
        <div id="email-alert"></div>
        <form id="email-form" hidden novalidate>
          <div class="form-row" data-field="email">
            <label for="acct-new-email">New email</label>
            <input type="email" id="acct-new-email" name="email" maxlength="100" required>
            <div class="err"></div>
          </div>
          <div class="form-row" data-field="current_password">
            <label for="acct-email-pw">Current password</label>
            <input type="password" id="acct-email-pw" name="current_password" autocomplete="current-password" required>
            <div class="err"></div>
          </div>
          <div class="form-actions">
            <button class="btn" type="submit">Save Email</button>
            <button class="btn btn-outline" type="button" id="cancel-email">Cancel</button>
          </div>
        </form>

        <h2 style="font-size:1.1rem;margin:1.8rem 0 .8rem">Password</h2>
        <button class="btn btn-outline btn-sm" id="show-pw-form" type="button">Change Password</button>
        <div id="pw-alert"></div>
        <form id="pw-form" hidden novalidate>
          <div class="form-row" data-field="current_password">
            <label for="pw-current">Current password</label>
            <input type="password" id="pw-current" name="current_password" autocomplete="current-password" required>
            <div class="err"></div>
          </div>
          <div class="form-row" data-field="password">
            <label for="pw-new">New password</label>
            <input type="password" id="pw-new" name="password" autocomplete="new-password" required>
            <div class="form-hint">At least 6 characters</div>
            <div class="err"></div>
          </div>
          <div class="form-row" data-field="password_confirmation">
            <label for="pw-confirm">Confirm new password</label>
            <input type="password" id="pw-confirm" name="password_confirmation" autocomplete="new-password" required>
            <div class="err"></div>
          </div>
          <div class="form-actions">
            <button class="btn" type="submit">Change password</button>
            <button class="btn btn-outline" type="button" id="cancel-pw">Cancel</button>
          </div>
        </form>
      </div>
    </div>`;

  root.querySelector('#acct-withdraw').addEventListener('click', withdrawBalance);

  const tabs = root.querySelectorAll('#acct-tabs .tab');
  tabs.forEach(tab => {
    tab.addEventListener('click', () => {
      const name = tab.dataset.acctTab;
      tabs.forEach(t => t.classList.toggle('active', t === tab));
      root.querySelector('#acct-profile').hidden = name !== 'profile';
      root.querySelector('#acct-security').hidden = name !== 'security';
    });
  });

  const emailForm = root.querySelector('#email-form');
  const pwForm = root.querySelector('#pw-form');
  root.querySelector('#show-email-form').addEventListener('click', () => { emailForm.hidden = false; });
  root.querySelector('#show-pw-form').addEventListener('click', () => { pwForm.hidden = false; });
  root.querySelector('#cancel-email').addEventListener('click', () => {
    emailForm.hidden = true;
    emailForm.reset();
    clearFieldErrors(root);
  });
  root.querySelector('#cancel-pw').addEventListener('click', () => {
    pwForm.hidden = true;
    pwForm.reset();
    clearFieldErrors(root);
  });

  root.querySelector('#avatar-form').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const file = root.querySelector('#a-avatar').files[0];
    if (!file) { toast('Please choose an avatar image', true); return; }
    const btn = ev.currentTarget.querySelector('button');
    const fd = new FormData();
    fd.append('avatar', file);
    btn.disabled = true;
    try {
      const { data } = await api('/account/avatar', { method: 'POST', form: fd });
      setUser(data);
      toast('Avatar updated');
      render();
    } catch (e) {
      toast(e.message, true);
      btn.disabled = false;
    }
  });

  root.querySelector('#profile-form').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const form = ev.currentTarget;
    const btn = form.querySelector('button[type=submit]');
    const alertBox = root.querySelector('#profile-alert');
    clearFieldErrors(root);
    alertBox.innerHTML = '';

    const body = {
      username: form.elements.username.value.trim(),
      bio: form.elements.bio.value.trim(),
      meetup_address: form.elements.meetup_address.value.trim(),
    };

    btn.disabled = true;
    try {
      const { data } = await api('/account', { method: 'PATCH', body });
      setUser(data);
      toast('Profile saved');
      render();
    } catch (e) {
      if (e.status === 422 && e.details && Object.keys(e.details).length > 0) {
        showFieldErrors(root, e.details);
      } else {
        alertBox.innerHTML = `<div class="alert">${esc(e.message)}</div>`;
      }
      btn.disabled = false;
    }
  });

  emailForm.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const btn = emailForm.querySelector('button[type=submit]');
    const alertBox = root.querySelector('#email-alert');
    clearFieldErrors(root);
    alertBox.innerHTML = '';

    const body = {
      email: emailForm.elements.email.value.trim(),
      current_password: emailForm.elements.current_password.value,
    };

    btn.disabled = true;
    try {
      const { data } = await api('/account', { method: 'PATCH', body });
      setUser(data);
      root.querySelector('#acct-email-value').textContent = data.email;
      emailForm.hidden = true;
      emailForm.reset();
      toast('Email updated');
    } catch (e) {
      if (e.status === 422 && e.details && Object.keys(e.details).length > 0) {
        showFieldErrors(root, e.details);
      } else {
        alertBox.innerHTML = `<div class="alert">${esc(e.message)}</div>`;
      }
    }
    btn.disabled = false;
  });

  pwForm.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const btn = pwForm.querySelector('button[type=submit]');
    const alertBox = root.querySelector('#pw-alert');
    clearFieldErrors(root);
    alertBox.innerHTML = '';

    const body = {
      current_password: pwForm.elements.current_password.value,
      password: pwForm.elements.password.value,
      password_confirmation: pwForm.elements.password_confirmation.value,
    };

    btn.disabled = true;
    try {
      await api('/account/password', { method: 'POST', body });
      toast('Password changed');
      pwForm.hidden = true;
      pwForm.reset();
    } catch (e) {
      if (e.status === 422 && e.details && Object.keys(e.details).length > 0) {
        showFieldErrors(root, e.details);
      } else {
        alertBox.innerHTML = `<div class="alert">${esc(e.message)}</div>`;
      }
    }
    btn.disabled = false;
  });
}

/* ============================ 11c. View: my listings ============================ */

function renderMyPagination(pg, page) {
  const total = pg.total_pages || 0;
  if (total <= 1) return '';
  const parts = [];
  if (page > 1) parts.push(`<a href="#/my-products?page=${page - 1}" data-link>‹ Prev</a>`);
  parts.push(`<span class="current">${page} / ${total}</span>`);
  if (page < total) parts.push(`<a href="#/my-products?page=${page + 1}" data-link>Next ›</a>`);
  return `<nav class="pagination" aria-label="Pagination">${parts.join('')}</nav>`;
}

async function viewMyProducts(root, route) {
  const page = Math.max(1, parseInt(route.query.page || '1', 10) || 1);

  root.innerHTML = `
    <div class="page-head">
      <h1>My listings</h1>
      <span class="spacer"></span>
      <a class="btn" href="#/publish" data-link>New item</a>
    </div>
    <div id="my-wrap"><div class="loading">Loading…</div></div>`;

  const wrap = root.querySelector('#my-wrap');
  let rows, pg;
  try {
    const res = await api('/products/mine?page=' + page + '&per_page=12');
    rows = res.data;
    pg = res.meta.pagination || {};
  } catch (e) {
    wrap.innerHTML = `<div class="empty"><h3>Failed to load</h3><p>${esc(e.message)}</p></div>`;
    return;
  }

  if (!rows || rows.length === 0) {
    wrap.innerHTML = `<div class="empty"><h3>No listings yet</h3>
      <p><a href="#/publish" data-link>List your first item</a></p></div>`;
    return;
  }

  wrap.innerHTML = `<div class="list">${rows.map(p => `
    <div class="row">
      <div class="row-thumb">${productImgTag(p.id, p.image_path, p.title)}</div>
      <div class="row-main">
        <a class="t" href="#/product/${p.id}" data-link>${esc(p.title)}</a>
        <span class="s">${money(p.price)} · ${STATUS_LABEL[p.status] || p.status} · ${esc(shortTimeAgo(p.created_at))}</span>
      </div>
      <div class="row-side">
        ${p.is_sold ? `<span class="tag tag-damaged">Sold</span>` : `
          <a class="btn btn-outline btn-sm" href="#/publish?edit=${p.id}" data-link>Edit</a>
          <button class="btn btn-danger btn-sm" data-del="${p.id}" type="button">Delete</button>`}
      </div>
    </div>`).join('')}</div>${renderMyPagination(pg, page)}`;

  wrap.querySelectorAll('[data-del]').forEach(btn => {
    btn.addEventListener('click', async () => {
      if (!confirm('Delete this item? This cannot be undone.')) return;
      btn.disabled = true;
      try {
        await api('/products/' + btn.dataset.del, { method: 'DELETE' });
        toast('Deleted');
        render();
      } catch (e) {
        toast(e.message, true);
        btn.disabled = false;
      }
    });
  });
}

/* ============================ 11d. View: admin console ============================ */

function renderAdminPagination(pg, search) {
  const total = pg.total_pages || 0;
  const page = pg.page || 1;
  if (total <= 1) return '';
  const q = (n) => {
    const p = new URLSearchParams();
    if (search) p.set('search', search);
    p.set('page', String(n));
    return '#/admin?' + p.toString();
  };
  const parts = [];
  if (page > 1) parts.push(`<a href="${q(page - 1)}" data-link>‹ Prev</a>`);
  parts.push(`<span class="current">${page} / ${total}</span>`);
  if (page < total) parts.push(`<a href="${q(page + 1)}" data-link>Next ›</a>`);
  return `<nav class="pagination" aria-label="Pagination">${parts.join('')}</nav>`;
}

async function viewAdmin(root, route) {
  if (!state.user || !state.user.is_admin) {
    root.innerHTML = `<div class="empty"><h3>Admin access required</h3>
      <p><a href="#/" data-link>Back to home</a></p></div>`;
    return;
  }

  const tabs = [
    { key: 'users', label: 'Users' },
    { key: 'products', label: 'Manage Products' },
    { key: 'reports', label: 'Reports' },
    { key: 'escrow', label: 'Escrow' },
    { key: 'reviews', label: 'Reviews' },
    { key: 'product-comments', label: 'Product comments' },
    { key: 'profile-comments', label: 'Profile comments' },
  ];
  const tab = tabs.some(t => t.key === route.query.tab) ? route.query.tab : 'users';
  const search = route.query.search || '';
  const page = Math.max(1, parseInt(route.query.page || '1', 10) || 1);
  const productStatus = ['available', 'sold', 'hidden'].includes(route.query.status) ? route.query.status : '';

  root.innerHTML = `
    <div class="page-head"><h1>Admin</h1><span class="count">Users & content</span></div>
    <div class="tabs" id="admin-tabs">
      ${tabs.map(t => `<button class="tab ${t.key === tab ? 'active' : ''}" data-admin-tab="${t.key}" type="button">${t.label}</button>`).join('')}
    </div>
    <div id="admin-toolbar"></div>
    <div id="admin-wrap"><div class="loading">Loading…</div></div>`;

  root.querySelectorAll('[data-admin-tab]').forEach(btn => {
    btn.addEventListener('click', () => {
      const q = new URLSearchParams();
      q.set('tab', btn.dataset.adminTab);
      navigate('/admin?' + q.toString());
    });
  });

  const toolbar = root.querySelector('#admin-toolbar');
  const wrap = root.querySelector('#admin-wrap');

  const pager = (pg) => {
    const total = pg.total_pages || 0;
    if (total <= 1) return '';
    const q = (n) => {
      const p = new URLSearchParams();
      p.set('tab', tab);
      if (search) p.set('search', search);
      if (tab === 'products' && productStatus) p.set('status', productStatus);
      if (n > 1) p.set('page', String(n));
      return '#/admin?' + p.toString();
    };
    const cur = pg.page || 1;
    const parts = [];
    if (cur > 1) parts.push(`<a href="${q(cur - 1)}" data-link>‹ Prev</a>`);
    parts.push(`<span class="current">${cur} / ${total}</span>`);
    if (cur < total) parts.push(`<a href="${q(cur + 1)}" data-link>Next ›</a>`);
    return `<nav class="pagination" aria-label="Pagination">${parts.join('')}</nav>`;
  };

  const loadUsers = async () => {
    toolbar.innerHTML = `<div class="filters">
      <div class="field">
        <label for="admin-search">Search users</label>
        <input type="search" id="admin-search" value="${esc(search)}" placeholder="Username or email">
      </div>
      <button class="btn" id="admin-search-btn" type="button">Search</button>
      <button class="btn btn-outline" id="admin-reset" type="button">Reset</button>
    </div>`;

    const go = (kw) => {
      const q = new URLSearchParams();
      q.set('tab', 'users');
      if (kw) q.set('search', kw);
      navigate('/admin?' + q.toString());
    };
    toolbar.querySelector('#admin-search-btn').addEventListener('click', () => {
      go(toolbar.querySelector('#admin-search').value.trim());
    });
    toolbar.querySelector('#admin-search').addEventListener('keydown', (ev) => {
      if (ev.key === 'Enter') { ev.preventDefault(); go(toolbar.querySelector('#admin-search').value.trim()); }
    });
    toolbar.querySelector('#admin-reset').addEventListener('click', () => navigate('/admin'));

    const q = new URLSearchParams();
    if (search) q.set('search', search);
    q.set('page', String(page));
    q.set('per_page', '20');
    const res = await api('/admin/users?' + q.toString());
    const rows = res.data || [];
    const pg = res.meta.pagination || {};

    if (rows.length === 0) {
      wrap.innerHTML = '<div class="empty"><h3>No users found</h3></div>';
      return;
    }

    wrap.innerHTML = `<div class="list">${rows.map(u => `
      <div class="row">
        <div class="row-thumb" style="width:44px;height:44px">${avatarTag({ username: u.username, avatar_path: u.avatar_path }, 'avatar', 40)}</div>
        <div class="row-main">
          <span class="t">
            ${esc(u.username)}
            <span class="tag tag-level">Lv${Number(u.level) || 1}</span>
            ${u.is_admin ? `<span class="tag tag-new">Admin</span>` : ''}
            ${u.is_active ? '' : `<span class="tag tag-damaged">Disabled</span>`}
          </span>
          <span class="s">${esc(u.email)} · ${u.product_count} items · Joined ${esc(shortTimeAgo(u.created_at))}</span>
          <span class="s">Spent ¥${Number(u.total_spent || 0).toFixed(2)}</span>
        </div>
        <div class="row-side">
          <div style="display:flex;flex-direction:column;gap:.35rem;font-size:.78rem">
            <label>Buyer Level
              <select data-buyer-level="${u.id}">${[1, 2, 3, 4, 5, 6].map(n => `<option value="${n}" ${Number(u.level) === n ? 'selected' : ''}>${n}</option>`).join('')}</select>
            </label>
            <label>Seller Level
              <select data-seller-level="${u.id}">${[1, 2, 3, 4, 5, 6].map(n => `<option value="${n}" ${Number(u.seller_level) === n ? 'selected' : ''}>${n}</option>`).join('')}</select>
            </label>
          </div>
          ${u.id === state.user.id ? `<span class="s">Current account</span>` : `
            <button class="btn btn-outline btn-sm" data-toggle-active="${u.id}" data-active="${u.is_active}" type="button">${u.is_active ? 'Disable' : 'Enable'}</button>
            <button class="btn btn-outline btn-sm" data-toggle-admin="${u.id}" data-admin="${u.is_admin}" type="button">${u.is_admin ? 'Remove admin' : 'Make admin'}</button>`}
        </div>
      </div>`).join('')}</div>${pager(pg)}`;

    const patch = async (id, body, message) => {
      try {
        await api('/admin/users/' + id, { method: 'PATCH', body });
        toast(message);
        render();
      } catch (e) { toast(e.message, true); }
    };

    const updateLevel = async (id, body) => {
      try {
        await api('/admin/users/' + id, { method: 'PATCH', body });
        toast('User levels updated');
      } catch (e) {
        toast(e.message, true);
      }
    };

    wrap.querySelectorAll('[data-buyer-level]').forEach(sel => {
      sel.addEventListener('change', () => updateLevel(sel.dataset.buyerLevel, { level: Number(sel.value) }));
    });
    wrap.querySelectorAll('[data-seller-level]').forEach(sel => {
      sel.addEventListener('change', () => updateLevel(sel.dataset.sellerLevel, { seller_level: Number(sel.value) }));
    });

    wrap.querySelectorAll('[data-toggle-active]').forEach(btn => {
      btn.addEventListener('click', () => {
        const active = btn.dataset.active === 'true';
        if (active && !confirm('Disable this user? They will be logged out immediately.')) return;
        patch(btn.dataset.toggleActive, { is_active: !active }, active ? 'User disabled' : 'User enabled');
      });
    });
    wrap.querySelectorAll('[data-toggle-admin]').forEach(btn => {
      btn.addEventListener('click', () => {
        const isAdmin = btn.dataset.admin === 'true';
        if (isAdmin && !confirm('Remove this user’s admin rights?')) return;
        patch(btn.dataset.toggleAdmin, { is_admin: !isAdmin }, isAdmin ? 'Admin rights removed' : 'Admin rights granted');
      });
    });
  };

  const loadProducts = async () => {
    const productFilters = [
      { key: '', label: 'All' },
      { key: 'available', label: 'Available' },
      { key: 'sold', label: 'Sold' },
      { key: 'hidden', label: 'Hidden' },
    ];
    toolbar.innerHTML = `<div class="chips">
      ${productFilters.map(item => `<button class="chip ${productStatus === item.key ? 'active' : ''}" data-product-filter="${item.key}" type="button">${item.label}</button>`).join('')}
    </div>`;

    toolbar.querySelectorAll('[data-product-filter]').forEach(btn => {
      btn.addEventListener('click', () => {
        const q = new URLSearchParams();
        q.set('tab', 'products');
        if (btn.dataset.productFilter) q.set('status', btn.dataset.productFilter);
        navigate('/admin?' + q.toString());
      });
    });

    const res = await api('/admin/products?page=' + page + '&per_page=20' + (productStatus ? '&status=' + productStatus : ''));
    const rows = res.data || [];
    const pg = res.meta.pagination || {};

    if (rows.length === 0) {
      wrap.innerHTML = '<div class="empty">No products found</div>';
      return;
    }

    wrap.innerHTML = `<div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Image</th>
            <th>Title</th>
            <th>Price</th>
            <th>Seller</th>
            <th>Status</th>
            <th>Visibility</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          ${rows.map(p => `
            <tr>
              <td>#${p.id}</td>
              <td><div class="admin-thumb">${productImgTag(p.id, p.image_path, p.title)}</div></td>
              <td>${esc(p.title)}</td>
              <td>${money(p.price)}</td>
              <td>${esc(p.seller_name || '')}<br><span class="s">${esc(p.seller_email || '')}</span></td>
              <td><span class="tag ${p.is_sold ? 'tag-damaged' : 'tag-new'}">${p.is_sold ? 'Sold' : 'Available'}</span></td>
              <td><span class="tag ${p.is_hidden ? 'tag-damaged' : 'tag-new'}">${p.is_hidden ? 'Hidden' : 'Normal'}</span></td>
              <td>
                <div class="admin-actions">
                  ${p.is_sold
                    ? ''
                    : `<button class="btn btn-outline btn-sm" data-product-hidden="${p.id}" data-hidden="${p.is_hidden}" type="button">${p.is_hidden ? 'RESTORE' : 'TAKE DOWN'}</button>`}
                  <button class="btn btn-danger btn-sm" data-delete-product="${p.id}" type="button">Delete</button>
                </div>
              </td>
            </tr>`).join('')}
        </tbody>
      </table>
    </div>${pager(pg)}`;

    wrap.querySelectorAll('[data-product-hidden]').forEach(btn => {
      btn.addEventListener('click', async () => {
        btn.disabled = true;
        try {
          await api('/admin/products/' + btn.dataset.productHidden, {
            method: 'PATCH',
            body: { is_hidden: btn.dataset.hidden !== 'true' },
          });
          toast(btn.dataset.hidden === 'true' ? 'Product restored' : 'Product taken down');
          await loadProducts();
        } catch (e) {
          toast(e.message, true);
          btn.disabled = false;
        }
      });
    });

    wrap.querySelectorAll('[data-delete-product]').forEach(btn => {
      btn.addEventListener('click', async () => {
        if (!confirm('Permanently delete this product and any linked orders?')) return;
        btn.disabled = true;
        try {
          await api('/admin/products/' + btn.dataset.deleteProduct, { method: 'DELETE' });
          toast('Product deleted');
          await loadProducts();
        } catch (e) {
          toast(e.message, true);
          btn.disabled = false;
        }
      });
    });
  };

  const loadEscrow = async () => {
    toolbar.innerHTML = '';
    const res = await api('/admin/orders?escrow_status=held&page=' + page + '&per_page=20');
    const rows = res.data || [];
    const pg = res.meta.pagination || {};

    if (rows.length === 0) {
      wrap.innerHTML = '<div class="empty">No funds held in escrow</div>';
      return;
    }

    wrap.innerHTML = `<div class="list">${rows.map(o => `
      <div class="row">
        <div class="row-thumb">${productImgTag(o.product_id, o.image_path, o.title)}</div>
        <div class="row-main">
          <span class="t">#${o.id} · ${esc(o.title)}</span>
          <span class="s">Buyer ${esc(o.buyer_name || '')} · Seller ${esc(o.seller_name || '')}</span>
          <span class="s">${money(o.total_price)} held · Paid ${esc(shortTimeAgo(o.paid_at))}</span>
        </div>
        <div class="row-side">
          <button class="btn btn-sm" data-release="${o.id}" type="button">Release</button>
          <button class="btn btn-danger btn-sm" data-refund="${o.id}" type="button">Refund</button>
        </div>
      </div>`).join('')}</div>${pager(pg)}`;

    const act = async (id, action) => {
      try {
        await api('/admin/orders/' + id + '/' + action, { method: 'POST' });
        toast(action === 'release' ? 'Funds released' : 'Order refunded');
        await loadEscrow();
      } catch (e) { toast(e.message, true); }
    };

    wrap.querySelectorAll('[data-release]').forEach(btn => {
      btn.addEventListener('click', () => act(btn.dataset.release, 'release'));
    });
    wrap.querySelectorAll('[data-refund]').forEach(btn => {
      btn.addEventListener('click', () => {
        if (!confirm('Refund this order in full? The product will remain sold.')) return;
        act(btn.dataset.refund, 'refund');
      });
    });
  };

  const loadReports = async () => {
    toolbar.innerHTML = '';
    const res = await api('/admin/reports?page=' + page + '&per_page=10');
    const rows = res.data || [];
    const pg = res.meta.pagination || {};

    if (rows.length === 0) {
      wrap.innerHTML = '<div class="empty">No reports yet</div>';
      return;
    }

    wrap.innerHTML = `<div class="list">${rows.map(r => `
      <div class="row">
        <div class="row-main">
          <span class="t">${esc(r.product_title)} · <span class="tag ${r.status === 'pending' ? 'tag-used' : 'tag-new'}">${esc(r.status)}</span></span>
          <span class="s">Reported by ${esc(r.reporter_name)} · ${esc(r.reason)} · ${esc(shortTimeAgo(r.created_at))}</span>
          ${r.details ? `<span class="s">${esc(r.details)}</span>` : ''}
          ${r.handler_name ? `<span class="s">Handled by ${esc(r.handler_name)} · ${esc(shortTimeAgo(r.handled_at))}</span>` : ''}
        </div>
        <div class="row-side">
          ${r.status === 'pending'
            ? `<button class="btn btn-outline btn-sm" data-report-resolve="${r.id}" type="button">Resolve</button>
               <button class="btn btn-outline btn-sm" data-report-dismiss="${r.id}" type="button">Dismiss</button>`
            : ''}
        </div>
      </div>`).join('')}</div>${pager(pg)}`;

    wrap.querySelectorAll('[data-report-resolve]').forEach(btn => {
      btn.addEventListener('click', async () => {
        try {
          await api('/admin/reports/' + btn.dataset.reportResolve, { method: 'PATCH', body: { status: 'resolved' } });
          toast('Report resolved');
          await loadReports();
        } catch (e) { toast(e.message, true); }
      });
    });
    wrap.querySelectorAll('[data-report-dismiss]').forEach(btn => {
      btn.addEventListener('click', async () => {
        try {
          await api('/admin/reports/' + btn.dataset.reportDismiss, { method: 'PATCH', body: { status: 'dismissed' } });
          toast('Report dismissed');
          await loadReports();
        } catch (e) { toast(e.message, true); }
      });
    });
  };

  const loadReviews = async () => {
    toolbar.innerHTML = '';
    const res = await api('/admin/reviews?page=' + page + '&per_page=10');
    const rows = res.data || [];
    const pg = res.meta.pagination || {};
    if (rows.length === 0) { wrap.innerHTML = '<div class="empty">No reviews yet</div>'; return; }

    wrap.innerHTML = `<div class="list">${rows.map(r => `
      <div class="row">
        <div class="row-main">
          <span class="t">${r.direction === 'seller_to_buyer'
            ? `${esc(r.seller_name)} → ${esc(r.buyer_name)}`
            : `${esc(r.buyer_name)} → ${esc(r.seller_name)}`} · <span class="stars">${stars(r.rating)}</span>
            ${r.is_hidden ? `<span class="tag tag-damaged">Hidden</span>` : ''}</span>
          <span class="s">${esc(r.product_title)} · ${esc(shortTimeAgo(r.created_at))}</span>
          <span class="s">${esc(r.comment || '(No comments)')}</span>
          ${r.reply_text ? `<div class="review-reply"><strong>Seller reply:</strong>${esc(r.reply_text)}</div>` : ''}
        </div>
        <div class="row-side">
          <button class="btn btn-outline btn-sm" data-hide-review="${r.id}" data-hidden="${r.is_hidden}" type="button">${r.is_hidden ? 'Restore' : 'Hide'}</button>
        </div>
      </div>`).join('')}</div>${pager(pg)}`;

    wrap.querySelectorAll('[data-hide-review]').forEach(btn => {
      btn.addEventListener('click', async () => {
        const hidden = btn.dataset.hidden === 'true';
        try {
          await api('/admin/reviews/' + btn.dataset.hideReview, { method: 'PATCH', body: { is_hidden: !hidden } });
          toast(hidden ? 'Review restored' : 'Review hidden');
          render();
        } catch (e) { toast(e.message, true); }
      });
    });
  };

  const loadProductComments = async () => {
    toolbar.innerHTML = '';
    const res = await api('/admin/product-comments?page=' + page + '&per_page=10');
    const rows = res.data || [];
    const pg = res.meta.pagination || {};
    if (rows.length === 0) { wrap.innerHTML = '<div class="empty">No comments yet</div>'; return; }

    wrap.innerHTML = `<div class="list">${rows.map(c => `
      <div class="row">
        <div class="row-main">
          <span class="t">${esc(c.user_name)} · ${esc(c.product_title)}
            ${c.is_hidden ? `<span class="tag tag-damaged">Hidden</span>` : ''}</span>
          <span class="s">${esc(c.body)}</span>
          <span class="s">${esc(shortTimeAgo(c.created_at))}</span>
        </div>
        <div class="row-side">
          <button class="btn btn-outline btn-sm" data-hide-pc="${c.id}" data-hidden="${c.is_hidden}" type="button">${c.is_hidden ? 'Restore' : 'Hide'}</button>
        </div>
      </div>`).join('')}</div>${pager(pg)}`;

    wrap.querySelectorAll('[data-hide-pc]').forEach(btn => {
      btn.addEventListener('click', async () => {
        const hidden = btn.dataset.hidden === 'true';
        try {
          await api('/admin/product-comments/' + btn.dataset.hidePc, { method: 'PATCH', body: { is_hidden: !hidden } });
          toast(hidden ? 'Comment restored' : 'Comment hidden');
          render();
        } catch (e) { toast(e.message, true); }
      });
    });
  };

  const loadProfileComments = async () => {
    toolbar.innerHTML = '';
    const res = await api('/admin/profile-comments?page=' + page + '&per_page=10');
    const rows = res.data || [];
    const pg = res.meta.pagination || {};
    if (rows.length === 0) { wrap.innerHTML = '<div class="empty">No guestbook comments yet</div>'; return; }

    wrap.innerHTML = `<div class="list">${rows.map(c => `
      <div class="row">
        <div class="row-main">
          <span class="t">${esc(c.author_name)} → ${esc(c.profile_name)}
            ${c.is_hidden ? `<span class="tag tag-damaged">Hidden</span>` : ''}</span>
          <span class="s">${esc(c.body)}</span>
          <span class="s">${esc(shortTimeAgo(c.created_at))}</span>
        </div>
        <div class="row-side">
          <button class="btn btn-outline btn-sm" data-hide-pfc="${c.id}" data-hidden="${c.is_hidden}" type="button">${c.is_hidden ? 'Restore' : 'Hide'}</button>
        </div>
      </div>`).join('')}</div>${pager(pg)}`;

    wrap.querySelectorAll('[data-hide-pfc]').forEach(btn => {
      btn.addEventListener('click', async () => {
        const hidden = btn.dataset.hidden === 'true';
        try {
          await api('/admin/profile-comments/' + btn.dataset.hidePfc, { method: 'PATCH', body: { is_hidden: !hidden } });
          toast(hidden ? 'Comment restored' : 'Comment hidden');
          render();
        } catch (e) { toast(e.message, true); }
      });
    });
  };

  try {
    if (tab === 'users') await loadUsers();
    else if (tab === 'products') await loadProducts();
    else if (tab === 'reports') await loadReports();
    else if (tab === 'escrow') await loadEscrow();
    else if (tab === 'reviews') await loadReviews();
    else if (tab === 'product-comments') await loadProductComments();
    else await loadProfileComments();
  } catch (e) {
    wrap.innerHTML = `<div class="empty"><h3>Failed to load</h3><p>${esc(e.message)}</p></div>`;
  }
}

/* ============================ 12. View: 404 ============================ */

function viewNotFound(root) {
  root.innerHTML = `<div class="empty">
    <h3>Page not found</h3>
    <p>The address may be wrong.<a href="#/" data-link>Back to home</a></p>
  </div>`;
}

/* ============================ 13. Session and navigation state ============================ */

async function refreshCartBadge() {
  if (!state.user) { updateCartBadge(0); return; }
  try {
    const { data } = await api('/cart');
    updateCartBadge(data.item_count || 0);
  } catch { /* ignore */ }
}

function updateCartBadge(n) {
  const badge = document.getElementById('cart-badge');
  if (!badge) return;
  if (n > 0) {
    badge.textContent = n > 99 ? '99+' : String(n);
    badge.hidden = false;
  } else {
    badge.hidden = true;
  }
}

async function withdrawBalance() {
  const value = prompt('Withdraw amount (HK$):');
  if (value === null) return;
  const amount = Number(value);
  if (!Number.isFinite(amount) || amount <= 0) {
    toast('Enter a valid amount', true);
    return;
  }

  try {
    await api('/users/withdraw', { method: 'POST', body: { amount } });
    toast('Withdrawal successful');
    location.reload();
  } catch (e) {
    toast(e.message, true);
  }
}

function syncNav() {
  const loggedIn = !!state.user;
  document.querySelectorAll('[data-auth-only]').forEach(el => { el.hidden = !loggedIn; });
  document.querySelectorAll('[data-guest-only]').forEach(el => { el.hidden = loggedIn; });
  document.querySelectorAll('[data-admin-only]').forEach(el => {
    el.hidden = !(loggedIn && state.user && state.user.is_admin);
  });

  const chip = document.getElementById('userchip');
  const name = document.getElementById('userchip-name');
  const walletMenu = document.getElementById('wallet-menu');
  chip.hidden = !loggedIn;
  if (walletMenu) walletMenu.hidden = true;
  if (loggedIn) {
    name.textContent = state.user.username;
    document.getElementById('wallet-balance').textContent = Number(state.user.balance || 0).toFixed(2);
  }

  const sideUser = document.getElementById('sidebar-user');
  if (sideUser) {
    sideUser.hidden = !loggedIn;
    if (loggedIn) {
      document.getElementById('sidebar-avatar').innerHTML = avatarTag(state.user, 'avatar', 36);
      document.getElementById('sidebar-name').textContent = state.user.username;
      document.getElementById('sidebar-name').href = '#/user/' + state.user.id;
      document.getElementById('sidebar-email').textContent = state.user.email || '';
    }
  }
}

function syncSidebar(route) {
  const map = {
    home: 'home', product: 'home', seller: 'home',
    publish: 'publish', 'my-products': 'my-products',
    cart: 'cart', orders: 'orders', account: 'account',
    admin: 'admin', login: 'login', register: 'register',
  };
  const active = route.name === 'admin' && route.query.tab === 'products'
    ? 'admin-products'
    : (map[route.name] || '');
  document.querySelectorAll('.sidebar-nav a[data-nav]').forEach(a => {
    a.classList.toggle('active', a.dataset.nav === active);
  });
}

/* ============================ 14. Render scheduling ============================ */

const VIEWS = {
  home: viewHome,
  product: viewProduct,
  login: viewLogin,
  register: viewRegister,
  publish: viewPublish,
  cart: viewCart,
  orders: viewOrders,
  account: viewAccount,
  'my-products': viewMyProducts,
  admin: viewAdmin,
  seller: viewSeller,
  notfound: viewNotFound,
};

const AUTH_REQUIRED = new Set(['publish', 'cart', 'orders', 'account', 'my-products', 'admin']);

let renderToken = 0;

async function render() {
  const route = parseHash();
  state.route = route;
  syncSidebar(route);

  // Auth-only pages: guests are sent straight to login, remembering the original address
  if (AUTH_REQUIRED.has(route.name) && !state.user) {
    navigate('/login?next=' + encodeURIComponent('#/' + route.name));
    return;
  }

  // signed-in users hitting the login/register pages → back to home
  if ((route.name === 'login' || route.name === 'register') && state.user) {
    navigate('/');
    return;
  }

  const root = document.getElementById('app');
  const token = ++renderToken;

  // scroll to top on page change (not when paging, or the user loses their place)
  if (route.name !== 'home') window.scrollTo({ top: 0 });

  try {
    await VIEWS[route.name](root, route);
  } catch (e) {
    if (token !== renderToken) return;   // superseded by a later render; discard
    root.innerHTML = `<div class="empty"><h3>Something went wrong</h3><p>${esc(e.message)}</p></div>`;
    console.error(e);
  }
}

/* ============================ 15. Startup ============================ */

function boot() {
  // sidebar (mobile drawer)
  const sidebar = document.getElementById('sidebar');
  const sidebarToggle = document.getElementById('sidebar-toggle');
  if (sidebar && sidebarToggle) {
    sidebarToggle.addEventListener('click', () => {
      sidebar.classList.toggle('open');
      sidebarToggle.setAttribute('aria-expanded', String(sidebar.classList.contains('open')));
    });
    sidebar.querySelectorAll('a').forEach(a => {
      a.addEventListener('click', () => {
        sidebar.classList.remove('open');
        sidebarToggle.setAttribute('aria-expanded', 'false');
      });
    });
  }

  // top bar search
  document.getElementById('search-form').addEventListener('submit', (ev) => {
    ev.preventDefault();
    const kw = document.getElementById('search-input').value.trim();
    navigate('/' + (kw ? '?search=' + encodeURIComponent(kw) : ''));
  });

  // logout
  document.getElementById('sidebar-avatar').addEventListener('click', () => {
    if (state.user) navigate('/user/' + state.user.id);
  });

  document.getElementById('userchip-toggle').addEventListener('click', (ev) => {
    ev.stopPropagation();
    const menu = document.getElementById('wallet-menu');
    menu.hidden = !menu.hidden;
  });
  document.addEventListener('click', (ev) => {
    if (!ev.target.closest('#userchip')) {
      document.getElementById('wallet-menu').hidden = true;
    }
  });
  document.getElementById('wallet-withdraw').addEventListener('click', () => {
    document.getElementById('wallet-menu').hidden = true;
    withdrawBalance();
  });

  document.getElementById('logout-btn').addEventListener('click', async () => {
    try {
      await api('/auth/logout', { method: 'POST' });
    } catch { /* clear local state even if the request fails */ }
    setUser(null);
    updateCartBadge(0);
    toast('Logged out');
    navigate('/');
  });

  // session restore: ask the backend "who am I" once after a page refresh
  api('/auth/me')
    .then(({ data }) => {
      setUser(data);
      refreshCartBadge();
    })
    .catch(() => setUser(null))
    .finally(() => {
      // render only after first-paint data arrives, so the nav does not flash "Log in" then swap back to the username
      window.addEventListener('hashchange', render);
      render();
    });

  onAuthChange(() => { syncNav(); refreshCartBadge(); });
  syncNav();
}

document.addEventListener('DOMContentLoaded', boot);
