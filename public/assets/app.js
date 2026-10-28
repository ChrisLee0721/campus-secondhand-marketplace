/* ==========================================================================
   闲置集市 —— 前端应用（原生 JS，无框架、无构建步骤）
   --------------------------------------------------------------------------
   结构：
     1. api()      统一 fetch 封装，解析 {data,error} 信封
     2. state      当前用户 / 路由 / 筛选条件
     3. router     hash 路由 + 视图注册表
     4. views      各页面渲染函数（返回 HTML 字符串 + 绑定事件）
     5. boot       启动

   设计约定：
     - 所有用户输入经过 esc() 转义后再进 innerHTML（防 XSS）
     - 事件用委托绑定在 #app 上，视图切换不用反复解绑
     - 路由在 hash 里，刷新不会 404，也不需要服务端 rewrite 支持
   ========================================================================== */

'use strict';

/* ============================ 1. 基础工具 ============================ */

const API = '/api/v1';

/** HTML 转义。任何要插进 innerHTML 的动态内容都必须过这一层。 */
function esc(value) {
  if (value === null || value === undefined) return '';
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

/** 金额格式化：HK$1,299.00 */
function money(n) {
  const num = Number(n) || 0;
  return 'HK$' + num.toLocaleString('en-HK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/** 相对时间：3 分钟前 / 2 天前 / 2025-03-30 */
function timeAgo(iso) {
  if (!iso) return '';
  const then = new Date(String(iso).replace(' ', 'T'));
  if (Number.isNaN(then.getTime())) return String(iso);
  const diff = (Date.now() - then.getTime()) / 1000;
  if (diff < 60) return '刚刚';
  if (diff < 3600) return Math.floor(diff / 60) + ' 分钟前';
  if (diff < 86400) return Math.floor(diff / 3600) + ' 小时前';
  if (diff < 86400 * 7) return Math.floor(diff / 86400) + ' 天前';
  return then.toISOString().slice(0, 10);
}

/** 商品状态 → 中文标签 */
const STATUS_LABEL = { new: '全新', used: '二手', damaged: '有瑕疵' };

function statusTag(status) {
  const key = ['new', 'used', 'damaged'].includes(status) ? status : 'used';
  return `<span class="tag tag-${key}">${esc(STATUS_LABEL[key] || status)}</span>`;
}

/** 评分 → ★★★★☆ */
function stars(rating) {
  const r = Math.round(Number(rating) || 0);
  return '★'.repeat(r) + '☆'.repeat(Math.max(0, 5 - r));
}

/** 图片地址 → URL。外链直接用，站内相对路径加前导斜杠。 */
function imgUrl(src) {
  return /^https?:\/\//i.test(src) ? src : '/' + String(src).replace(/^\/+/, '');
}

/** 图片地址 → <img>。 */
function imgTag(src, alt, cls) {
  if (!src) {
    return `<div class="placeholder">暂无图片</div>`;
  }
  const url = imgUrl(src);
  return `<img src="${esc(url)}" alt="${esc(alt)}" loading="lazy"${cls ? ` class="${esc(cls)}"` : ''}>`;
}

/** 用户头像：有 avatar_path 用图片，否则用用户名首字。 */
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

/* ============================ 2. API 封装 ============================ */

/**
 * 调用后端。成功返回 {data, meta}，失败抛 Error（带 status / code / details）。
 * credentials: 'same-origin' 让 session cookie 自动带上。
 */
async function api(path, { method = 'GET', body = null, form = null } = {}) {
  const opts = {
    method,
    credentials: 'same-origin',
    headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' },
  };

  if (form instanceof FormData) {
    opts.body = form;                       // 让浏览器自己设 multipart 边界
  } else if (body !== null) {
    opts.headers['Content-Type'] = 'application/json';
    opts.body = JSON.stringify(body);
  }

  let res;
  try {
    res = await fetch(API + path, opts);
  } catch (networkError) {
    const err = new Error('无法连接到服务器');
    err.status = 0;
    throw err;
  }

  if (res.status === 204) return { data: null, meta: {} };

  let payload = null;
  const text = await res.text();
  if (text) {
    try { payload = JSON.parse(text); }
    catch { throw Object.assign(new Error('服务器返回了非 JSON 内容'), { status: res.status, raw: text.slice(0, 200) }); }
  }

  if (!res.ok) {
    const info = payload?.error || {};
    const err = new Error(info.message || `请求失败（HTTP ${res.status}）`);
    err.status = res.status;
    err.code = info.code;
    err.details = info.details || {};
    throw err;
  }

  return { data: payload?.data ?? null, meta: payload?.meta || {} };
}

/* ============================ 3. 全局状态 ============================ */

const state = {
  user: null,          // 当前登录用户，null = 未登录
  route: null,         // {name, params}
  filters: {           // 商品列表的筛选条件（跟着 URL 走）
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

/* ============================ 4. 路由 ============================ */

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
    default:         return { name: 'notfound', params: {}, query };
  }
}

function navigate(path) {
  if (location.hash === '#' + path) {
    render();      // 同一个地址再点一次也重新渲染
  } else {
    location.hash = path;
  }
}

/* ============================ 5. 视图：商品列表 ============================ */

async function viewHome(root, route) {
  // 从 URL 恢复筛选条件
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
    <!-- 横幅：左侧品牌介绍 + 实时统计，右侧最新上架三件 -->
    <section class="hero" id="hero">
      <div class="hero-left">
        <h1 class="hero-title">你的闲置，我的需要</h1>
        <p class="hero-sub">校园二手交易平台 —— 把用不上的东西，交给刚好需要的人。</p>
        <div class="hero-stats" id="hero-stats"></div>
        <div class="hero-actions">
          <a class="btn" href="#/publish" data-link data-hero-publish>发布一件闲置</a>
          <button class="btn btn-outline" type="button" id="hero-browse">随便逛逛</button>
        </div>
      </div>
      <div class="hero-right" id="hero-right"></div>
    </section>

    <div class="page-head">
      <h1>在售商品</h1>
      <span class="count" id="result-count"></span>
    </div>
    <div class="filters" id="filters">
      <div class="field">
        <label for="f-cat">分类</label>
        <select id="f-cat" name="category_id"><option value="0">全部分类</option></select>
      </div>
      <div class="field">
        <label>价格</label>
        <div class="field-price">
          <input type="number" id="f-min" name="min_price" placeholder="最低" min="0" step="1"
                 value="${esc(state.filters.min_price)}">
          <span>—</span>
          <input type="number" id="f-max" name="max_price" placeholder="最高" min="0" step="1"
                 value="${esc(state.filters.max_price)}">
        </div>
      </div>
      <div class="field">
        <label for="f-status">成色</label>
        <select id="f-status" name="status">
          <option value="">全部</option>
          <option value="new">全新</option>
          <option value="used">二手</option>
          <option value="damaged">有瑕疵</option>
        </select>
      </div>
      <button class="btn" id="apply-filters" type="button">筛选</button>
      <button class="btn btn-outline" id="reset-filters" type="button">重置</button>
    </div>
    <div id="grid-wrap"><div class="loading">加载中…</div></div>
  `;

  // 横幅内容（统计 + 最新上架）。失败就整块藏起来，不影响列表。
  loadHero(root);

  const catSel = root.querySelector('#f-cat');
  const statusSel = root.querySelector('#f-status');
  statusSel.value = state.filters.status;

  // 分类下拉（并行请求，失败不阻断列表）
  api('/categories').then(({ data }) => {
    for (const c of data || []) {
      const opt = document.createElement('option');
      opt.value = c.id;
      opt.textContent = `${c.name}（${c.product_count}）`;
      if (Number(c.id) === state.filters.category_id) opt.selected = true;
      catSel.appendChild(opt);
    }
  }).catch(() => { /* 分类拉不到不影响商品列表 */ });

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

  async function load() {
    const wrap = root.querySelector('#grid-wrap');
    wrap.innerHTML = '<div class="loading">加载中…</div>';

    const params = currentQuery();
    params.set('per_page', '12');

    try {
      const { data, meta } = await api('/products?' + params.toString());
      const pg = meta.pagination || {};

      const countEl = root.querySelector('#result-count');
      if (countEl) countEl.textContent = pg.total ? `共 ${pg.total} 件` : '';

      if (!data || data.length === 0) {
        wrap.innerHTML = `
          <div class="empty">
            <h3>没有找到符合条件的商品</h3>
            <p>试试放宽筛选条件，或者换个关键词。</p>
          </div>`;
        return;
      }

      wrap.innerHTML = `<div class="grid">${data.map(productCard).join('')}</div>` +
                       renderPagination(pg, currentQuery());
    } catch (e) {
      wrap.innerHTML = `<div class="empty"><h3>加载失败</h3><p>${esc(e.message)}</p></div>`;
    }
  }

  // 筛选事件
  root.querySelector('#apply-filters').addEventListener('click', () => {
    const min = root.querySelector('#f-min').value.trim();
    const max = root.querySelector('#f-max').value.trim();
    if (min !== '' && max !== '' && Number(min) > Number(max)) {
      toast('最低价不能高于最高价', true);
      return;
    }
    navigate('/?' + currentQuery({
      page: 1,
      category_id: catSel.value,
      status: statusSel.value,
      min_price: min,
      max_price: max,
    }).toString());
  });

  root.querySelector('#reset-filters').addEventListener('click', () => {
    document.getElementById('search-input').value = '';
    navigate('/');
  });

  await load();
}

function productCard(p) {
  const soldOverlay = p.is_sold ? '<div class="sold-flag">已售出</div>' : '';
  return `
    <a class="card" href="#/product/${p.id}" data-link>
      <div class="card-img">${imgTag(p.image_path, p.title)}${soldOverlay}</div>
      <div class="card-body">
        <h3 class="card-title">${esc(p.title)}</h3>
        <div class="card-price">${money(p.price)}</div>
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
    parts.push(link(1, '« 首页'));
    parts.push(link(page - 1, '‹ 上一页'));
  }

  const start = Math.max(1, page - 2);
  const end = Math.min(total, page + 2);
  if (start > 1) parts.push('<span class="gap">…</span>');
  for (let i = start; i <= end; i++) {
    parts.push(i === page ? `<span class="current">${i}</span>` : link(i));
  }
  if (end < total) parts.push('<span class="gap">…</span>');

  if (page < total) {
    parts.push(link(page + 1, '下一页 ›'));
    parts.push(link(total, '末页 »'));
  }

  return `<nav class="pagination" aria-label="分页">${parts.join('')}</nav>`;
}

/* ============================ 5b. 首页横幅 ============================ */

/**
 * 首页横幅：左边品牌介绍 + 实时统计，右边最新上架三件。
 *
 * 为什么要走接口而不是写死：横幅上的数字必须是真实的。写死「在售 137 件」
 * 演示前刚好卖出去几件就不对了。
 *
 * 任一步失败就把整块收起来（.hero 隐藏），绝不因为横幅挂掉而影响商品列表 ——
 * 横幅是锦上添花，列表才是主体。
 */
async function loadHero(root) {
  const hero = root.querySelector('#hero');
  if (!hero) return;

  const statsBox = root.querySelector('#hero-stats');
  const rightBox = root.querySelector('#hero-right');
  if (!statsBox || !rightBox) return;

  const publishBtn = hero.querySelector('[data-hero-publish]');
  const browseBtn = root.querySelector('#hero-browse');

  // 「随便逛逛」= 清空筛选、回到纯列表
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
      <div class="stat"><span class="stat-n">${Number(s.products_available ?? 0).toLocaleString()}</span><span class="stat-l">在售</span></div>
      <div class="stat"><span class="stat-n">${Number(s.products_sold ?? 0).toLocaleString()}</span><span class="stat-l">已成交</span></div>
      <div class="stat"><span class="stat-n">${Number(s.users_total ?? 0).toLocaleString()}</span><span class="stat-l">用户</span></div>
    `;

    // 未登录时不该出现「发布」按钮（点进去会被弹回登录页，体验割裂）
    if (publishBtn && !state.user) {
      publishBtn.href = '#/login?next=' + encodeURIComponent('#/publish');
      publishBtn.textContent = '登录后发布';
    }

    if (latest.length === 0) {
      rightBox.innerHTML = '';
      hero.classList.add('hero-no-latest');
      return;
    }

    rightBox.innerHTML = `
      <div class="hero-latest-label">最新上架</div>
      <div class="hero-latest">
        ${latest.map(p => `
          <a class="hero-card" href="#/product/${p.id}" data-link>
            <div class="hero-card-img">${imgTag(p.image_path, p.title)}</div>
            <div class="hero-card-body">
              <span class="hero-card-title">${esc(p.title)}</span>
              <span class="hero-card-price">${money(p.price)}</span>
            </div>
          </a>`).join('')}
      </div>`;
  } catch (e) {
    // 横幅拿不到数据就整个藏起来 —— 不留半截空壳
    hero.hidden = true;
  }
}

/* ============================ 6. 视图：商品详情 ============================ */

async function viewProduct(root, route) {
  const id = route.params.id;
  root.innerHTML = '<div class="loading">加载中…</div>';

  let p;
  try {
    ({ data: p } = await api('/products/' + encodeURIComponent(id)));
  } catch (e) {
    root.innerHTML = `<div class="empty"><h3>商品打不开</h3><p>${esc(e.message)}</p>
      <p><a href="#/" data-link>返回列表</a></p></div>`;
    return;
  }

  const isOwner = state.user && Number(state.user.id) === Number(p.seller_id);
  const rating = p.seller_rating || { count: 0, average: null };

  root.innerHTML = `
    <p style="margin-bottom:1rem"><a href="#/" data-link>← 返回列表</a></p>
    <div class="detail">
      <div class="detail-img">${imgTag(p.image_path, p.title)}</div>
      <div>
        <h1>${esc(p.title)}</h1>
        <div class="detail-price">${money(p.price)}</div>
        <div class="card-meta" style="margin-bottom:.5rem">
          ${statusTag(p.status)}
          ${p.category_name ? `<span class="tag">${esc(p.category_name)}</span>` : ''}
          ${p.is_sold ? '<span class="tag tag-damaged">已售出</span>' : ''}
          <span class="tag">${esc(timeAgo(p.created_at))}</span>
        </div>

        <div class="detail-desc">${esc(p.description || '卖家没有填写描述。')}</div>

        <div id="detail-actions" class="detail-actions"></div>

        <div class="seller-box">
          ${avatarTag({ username: p.seller_name, avatar_path: p.seller_avatar_path }, 'avatar', 40)}
          <div style="flex:1;min-width:0">
            <a href="#/seller/${p.seller_id}" data-link style="font-weight:600">${esc(p.seller_name || '未知卖家')}</a>
            <div class="s" style="color:var(--text-faint);font-size:.82rem">
              ${rating.count > 0
                ? `<span class="stars">${stars(rating.average)}</span> ${rating.average} · ${rating.count} 条评价`
                : '还没有评价'}
            </div>
          </div>
        </div>
      </div>
    </div>
    <div id="comments" style="margin-top:2.5rem"></div>
    <div id="reviews" style="margin-top:2.5rem"></div>
  `;

  renderDetailActions(root.querySelector('#detail-actions'), p, isOwner);
  renderProductComments(root, p);
  renderProductReviews(root, p);
}

function commentPager(pg, attr) {
  const total = pg.total_pages || 0;
  if (total <= 1) return '';
  const page = pg.page || 1;
  return `<nav class="pagination">${page > 1 ? `<button class="btn btn-ghost btn-sm" ${attr}="${page - 1}">‹ 上一页</button>` : ''}
    <span class="current">${page} / ${total}</span>
    ${page < total ? `<button class="btn btn-ghost btn-sm" ${attr}="${page + 1}">下一页 ›</button>` : ''}</nav>`;
}

function renderProductComments(root, p) {
  const box = root.querySelector('#comments');
  if (!box) return;
  let page = 1;

  async function load() {
    box.innerHTML = '<h2>商品留言</h2><div class="loading">加载中…</div>';
    let data, pg;
    try {
      const res = await api('/products/' + p.id + '/comments?page=' + page + '&per_page=5');
      data = res.data || [];
      pg = res.meta.pagination || {};
    } catch (e) {
      box.innerHTML = `<h2>商品留言</h2><div class="empty">${esc(e.message)}</div>`;
      return;
    }

    const form = state.user
      ? `<form id="cm-form" class="comment-form">
          <textarea id="cm-body" maxlength="1000" placeholder="问一下成色、配件、交易方式…"></textarea>
          <button class="btn btn-sm" type="submit">发送留言</button>
        </form>`
      : `<p class="form-hint">登录后可以给卖家留言。<a href="#/login?next=${encodeURIComponent('#/product/' + p.id)}" data-link>去登录</a></p>`;

    box.innerHTML = `<h2>商品留言 <span class="count">${pg.total || 0}</span></h2>
      ${form}
      <div id="cm-list">${data.length ? data.map(commentHtml).join('') : '<div class="empty" style="padding:1.2rem">还没有留言，来问第一个问题吧。</div>'}</div>
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
            ${Number(r.user_id) === Number(p.seller_id) ? '<span class="tag tag-new">卖家</span>' : ''}
            · ${esc(timeAgo(r.created_at))}</div>
          <div class="comment-body">${esc(r.body)}</div>
        </div>
      </div>`).join('');

    return `
      <div class="comment" data-cid="${c.id}">
        <div class="comment-main">
          ${avatarTag({ username: c.user_name, avatar_path: c.user_avatar_path }, 'avatar', 36)}
          <div style="flex:1;min-width:0">
            <div class="comment-meta"><strong>${esc(c.user_name)}</strong>
              ${isSeller ? '<span class="tag tag-new">卖家</span>' : ''}
              · ${esc(timeAgo(c.created_at))}</div>
            <div class="comment-body">${esc(c.body)}</div>
            ${replies ? `<div class="comment-replies">${replies}</div>` : ''}
            <div class="comment-actions">
              ${canReply ? `<button class="btn btn-ghost btn-sm" data-reply="${c.id}" type="button">回复</button>` : ''}
              ${canDelete ? `<button class="btn btn-ghost btn-sm" data-cdel="${c.id}" type="button">删除</button>` : ''}
            </div>
            ${canReply ? `<form class="reply-form" data-reply-form="${c.id}" hidden>
              <input maxlength="1000" placeholder="回复这条留言…">
              <button class="btn btn-sm" type="submit">发送</button>
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
        if (!body) { toast('留言不能为空', true); return; }
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
        if (!body) { toast('回复不能为空', true); return; }
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
        if (!confirm('确定删除这条留言？')) return;
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
    box.innerHTML = '<h2>评价</h2><div class="loading">加载中…</div>';
    let data, pg;
    try {
      const res = await api('/products/' + p.id + '/reviews?page=' + page + '&per_page=5');
      data = res.data || [];
      pg = res.meta.pagination || {};
    } catch (e) {
      box.innerHTML = `<h2>评价</h2><div class="empty">${esc(e.message)}</div>`;
      return;
    }

    const rows = data.map(r => {
      const canReply = state.user && Number(state.user.id) === Number(p.seller_id);
      const canHide = state.user && state.user.is_admin;
      return `
        <div class="row">
          ${avatarTag({ username: r.buyer_name, avatar_path: r.buyer_avatar_path }, 'avatar', 36)}
          <div class="row-main">
            <span class="t"><span class="stars">${stars(r.rating)}</span> ${esc(r.buyer_name || '匿名')}</span>
            <span class="s">${esc(r.comment || '（没有留言）')}</span>
            ${r.reply_text ? `<div class="review-reply"><strong>卖家回复：</strong>${esc(r.reply_text)}</div>` : ''}
            ${canReply ? `<form class="reply-form" data-rreply-form="${r.id}" hidden>
              <input maxlength="1000" value="${esc(r.reply_text || '')}" placeholder="回复这条评价…">
              <button class="btn btn-sm" type="submit">发送</button>
            </form>` : ''}
            ${canReply || canHide ? `<div class="comment-actions">
              ${canReply ? `<button class="btn btn-ghost btn-sm" data-rreply="${r.id}" type="button">回复 / 修改</button>` : ''}
              ${canHide ? `<button class="btn btn-ghost btn-sm" data-rhide="${r.id}" type="button">隐藏</button>` : ''}
            </div>` : ''}
          </div>
          <div class="row-side"><span class="s" style="color:var(--text-faint);font-size:.8rem">${esc(timeAgo(r.created_at))}</span></div>
        </div>`;
    }).join('');

    box.innerHTML = `<h2>评价 <span class="count">${pg.total || 0}</span></h2>
      ${data.length ? `<div class="list">${rows}</div>` : '<div class="empty" style="padding:1.5rem">还没有评价，完成交易后可以评价卖家。</div>'}
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
        if (!reply) { toast('回复不能为空', true); return; }
        try {
          await api('/reviews/' + f.dataset.rreplyForm + '/reply', { method: 'POST', body: { reply } });
          await load();
        } catch (e) { toast(e.message, true); }
      });
    });

    box.querySelectorAll('[data-rhide]').forEach(btn => {
      btn.addEventListener('click', async () => {
        if (!confirm('隐藏这条评价？')) return;
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
    container.innerHTML = '<p class="form-hint">这件商品已经卖出去了。</p>';
    return;
  }

  if (!state.user) {
    container.innerHTML = `<a class="btn" href="#/login?next=${encodeURIComponent('#/product/' + p.id)}" data-link>登录后购买</a>`;
    return;
  }

  if (isOwner) {
    container.innerHTML = `
      <p class="form-hint" style="width:100%">这是你发布的商品。</p>
      <a class="btn btn-outline" href="#/seller/${p.seller_id}" data-link>我的主页</a>`;
    return;
  }

  container.innerHTML = `
    <button class="btn" id="add-cart" type="button">加入购物车</button>
    <button class="btn btn-outline" id="buy-now" type="button">立即购买</button>`;

  container.querySelector('#add-cart').addEventListener('click', async (ev) => {
    const btn = ev.currentTarget;
    btn.disabled = true;
    try {
      const { data } = await api('/cart', { method: 'POST', body: { product_id: Number(p.id) } });
      toast(data.created ? '已加入购物车' : '购物车里已经有这件商品了');
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
      // 先加进购物车，再立刻结算它 —— 复用同一条后端路径，避免出现"绕过购物车的下单"分支
      const added = await api('/cart', { method: 'POST', body: { product_id: Number(p.id) } });
      await api('/orders', { method: 'POST', body: { cart_ids: [added.data.id] } });
      toast('下单成功');
      refreshCartBadge();
      navigate('/orders');
    } catch (e) {
      toast(e.message, true);
      btn.disabled = false;
    }
  });
}

/* ============================ 7. 视图：登录 / 注册 ============================ */

function viewLogin(root, route) {
  const next = route.query.next || '#/';
  root.innerHTML = `
    <div class="panel">
      <h1>登录</h1>
      <div id="login-alert"></div>
      <form id="login-form" novalidate>
        <div class="form-row">
          <label for="l-email">电子邮箱</label>
          <input type="email" id="l-email" name="email" autocomplete="email" required>
        </div>
        <div class="form-row">
          <label for="l-pw">密码</label>
          <input type="password" id="l-pw" name="password" autocomplete="current-password" required>
        </div>
        <div class="form-actions">
          <button class="btn btn-block" type="submit">登录</button>
        </div>
      </form>
      <p class="form-foot">还没有账号？<a href="#/register" data-link>注册一个</a></p>
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
      toast(`欢迎回来，${data.username}`);
      navigate(next);
    } catch (e) {
      alertBox.innerHTML = `<div class="alert">${esc(e.message)}</div>`;
      btn.disabled = false;
    }
  });
}

function viewRegister(root) {
  root.innerHTML = `
    <div class="panel">
      <h1>注册</h1>
      <div id="reg-alert"></div>
      <form id="reg-form" novalidate>
        <div class="form-row" data-field="username">
          <label for="r-user">用户名</label>
          <input type="text" id="r-user" name="username" autocomplete="username" required>
          <div class="err"></div>
        </div>
        <div class="form-row" data-field="email">
          <label for="r-email">电子邮箱</label>
          <input type="email" id="r-email" name="email" autocomplete="email" required>
          <div class="err"></div>
        </div>
        <div class="form-row" data-field="password">
          <label for="r-pw">密码</label>
          <input type="password" id="r-pw" name="password" autocomplete="new-password" required>
          <div class="form-hint">至少 6 位</div>
          <div class="err"></div>
        </div>
        <div class="form-row" data-field="password_confirmation">
          <label for="r-pw2">确认密码</label>
          <input type="password" id="r-pw2" name="password_confirmation" autocomplete="new-password" required>
          <div class="err"></div>
        </div>
        <div class="form-actions">
          <button class="btn btn-block" type="submit">创建账号</button>
        </div>
      </form>
      <p class="form-foot">已经有账号了？<a href="#/login" data-link>去登录</a></p>
    </div>`;

  root.querySelector('#reg-form').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const form = ev.currentTarget;
    const btn = form.querySelector('button[type=submit]');
    const alertBox = root.querySelector('#reg-alert');

    // 清掉上一次的字段错误
    root.querySelectorAll('.form-row').forEach(r => {
      r.classList.remove('invalid');
      const err = r.querySelector('.err');
      if (err) err.textContent = '';
    });
    alertBox.innerHTML = '';

    // 前端先挡一道明显的错误（后端仍会再校验一次，前端这层只是体验）
    const values = {
      username: form.elements.username.value.trim(),
      email: form.elements.email.value.trim(),
      password: form.elements.password.value,
      password_confirmation: form.elements.password_confirmation.value,
    };
    const localErrors = {};
    if (!values.username) localErrors.username = '请输入用户名';
    if (!values.email) localErrors.email = '请输入电子邮箱';
    if (!values.password) localErrors.password = '请输入密码';
    else if (values.password.length < 6) localErrors.password = '密码至少 6 位';
    else if (values.password !== values.password_confirmation) {
      localErrors.password_confirmation = '两次输入的密码不一致';
    }

    if (Object.keys(localErrors).length > 0) {
      showFieldErrors(root, localErrors);
      return;
    }

    btn.disabled = true;
    try {
      const { data } = await api('/auth/register', { method: 'POST', body: values });
      setUser(data);
      toast('注册成功，已自动登录');
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

/* ============================ 8. 视图：发布商品 ============================ */

async function viewPublish(root, route) {
  const editId = route && route.query && route.query.edit ? Number(route.query.edit) : 0;
  let product = null;

  if (editId) {
    try {
      const res = await api('/products/' + editId);
      product = res.data;
    } catch (e) {
      root.innerHTML = `<div class="empty"><h3>商品打不开</h3><p>${esc(e.message)}</p>
        <p><a href="#/my-products" data-link>返回我的发布</a></p></div>`;
      return;
    }

    if (!state.user || Number(state.user.id) !== Number(product.seller_id)) {
      root.innerHTML = `<div class="empty"><h3>没有权限</h3><p>只能编辑自己发布的商品。</p>
        <p><a href="#/my-products" data-link>返回我的发布</a></p></div>`;
      return;
    }

    if (product.is_sold) {
      root.innerHTML = `<div class="empty"><h3>已售出</h3><p>已售出的商品不能再编辑。</p>
        <p><a href="#/product/${product.id}" data-link>查看商品</a></p></div>`;
      return;
    }
  }

  const isEdit = !!product;

  root.innerHTML = `
    <div class="panel panel-wide">
      <h1>${isEdit ? '编辑商品' : '发布商品'}</h1>
      <p class="form-hint" style="margin-bottom:1rem">${isEdit ? '修改后会立即更新商品页。' : '填写商品信息，带图更容易成交。'}</p>
      <div id="pub-alert"></div>
      <form id="pub-form" novalidate>
        <div class="form-row" data-field="title">
          <label for="p-title">标题</label>
          <input type="text" id="p-title" name="title" maxlength="100" required
                 value="${isEdit ? esc(product.title) : ''}" placeholder="例如：iPad Air 10.9 英寸">
          <div class="err"></div>
        </div>
        <div class="form-row" data-field="description">
          <label for="p-desc">描述</label>
          <textarea id="p-desc" name="description" maxlength="5000"
                    placeholder="成色、购买时间、有无配件、交易方式…">${isEdit ? esc(product.description || '') : ''}</textarea>
          <div class="err"></div>
        </div>
        <div class="form-row" data-field="price">
          <label for="p-price">价格（HK$）</label>
          <input type="number" id="p-price" name="price" min="0.01" step="0.01" required
                 value="${isEdit ? product.price : ''}">
          <div class="err"></div>
        </div>
        <div class="form-row" data-field="category_id">
          <label for="p-cat">分类</label>
          <select id="p-cat" name="category_id"><option value="">请选择</option></select>
          <div class="err"></div>
        </div>
        <div class="form-row" data-field="status">
          <label for="p-status">成色</label>
          <select id="p-status" name="status" required>
            <option value="new">全新</option>
            <option value="used" selected>二手</option>
            <option value="damaged">有瑕疵</option>
          </select>
          <div class="err"></div>
        </div>
        <div class="form-row" data-field="image">
          <label for="p-img">图片</label>
          ${isEdit && product.image_path ? `<div style="max-width:160px;margin-bottom:.5rem">${imgTag(product.image_path, product.title)}</div>` : ''}
          <input type="file" id="p-img" name="image" accept="image/jpeg,image/png,image/gif,image/webp">
          <div class="form-hint">${isEdit ? '不选择新图片则保留原图。' : '支持 JPG / PNG / GIF / WebP，不超过 5 MB。'}</div>
          <div class="err"></div>
        </div>
        <div class="form-actions">
          <button class="btn" type="submit">${isEdit ? '保存修改' : '发布'}</button>
          <a class="btn btn-outline" href="${isEdit ? '#/product/' + product.id : '#/'}" data-link>取消</a>
        </div>
      </form>
    </div>`;

  const catSel = root.querySelector('#p-cat');
  if (isEdit) root.querySelector('#p-status').value = product.status;

  try {
    const { data } = await api('/categories');
    for (const c of data || []) {
      const opt = document.createElement('option');
      opt.value = c.id;
      opt.textContent = c.name;
      catSel.appendChild(opt);
    }
    if (isEdit && product.category_id !== null) {
      catSel.value = String(product.category_id);
    }
  } catch { /* 分类加载失败不阻断表单，后端仍会校验 */ }

  root.querySelector('#pub-form').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const form = ev.currentTarget;
    const btn = form.querySelector('button[type=submit]');
    const alertBox = root.querySelector('#pub-alert');

    clearFieldErrors(root);
    alertBox.innerHTML = '';

    const fd = new FormData();
    fd.append('title', form.elements.title.value.trim());
    fd.append('description', form.elements.description.value.trim());
    fd.append('price', form.elements.price.value);
    fd.append('status', form.elements.status.value);
    const cat = form.elements.category_id.value;
    if (cat) fd.append('category_id', cat);
    else if (isEdit) fd.append('category_id', '');
    const file = form.elements.image.files[0];
    if (file) fd.append('image', file);

    btn.disabled = true;
    try {
      const { data } = isEdit
        ? await api('/products/' + product.id, { method: 'POST', form: fd })
        : await api('/products', { method: 'POST', form: fd });
      toast(isEdit ? '修改已保存' : '发布成功');
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

/* ============================ 9. 视图：购物车 ============================ */

async function viewCart(root) {
  root.innerHTML = '<div class="loading">加载中…</div>';

  async function load() {
    let summary;
    try {
      ({ data: summary } = await api('/cart'));
    } catch (e) {
      root.innerHTML = `<div class="empty"><h3>购物车加载失败</h3><p>${esc(e.message)}</p></div>`;
      return;
    }

    if (!summary.items || summary.items.length === 0) {
      root.innerHTML = `<div class="page-head"><h1>购物车</h1></div>
        <div class="empty"><h3>购物车是空的</h3>
        <p><a href="#/" data-link>去逛逛</a></p></div>`;
      updateCartBadge(0);
      return;
    }

    root.innerHTML = `
      <div class="page-head"><h1>购物车</h1>
        <span class="count">${summary.quantity} 件 · 合计 ${money(summary.total)}</span>
        <span class="spacer"></span>
        <button class="btn btn-danger btn-sm" id="cart-clear" type="button">清空</button>
      </div>
      <div class="list">
        ${summary.items.map(item => `
          <div class="row ${item.available ? '' : 'invalid'}" data-cart-id="${item.id}">
            <div class="row-thumb">${imgTag(item.image_path, item.title)}</div>
            <div class="row-main">
              <a class="t" href="#/product/${item.product_id}" data-link>${esc(item.title)}</a>
              <span class="s">
                <strong>${money(item.price)}</strong>
                ${item.available ? '' : ' · 已售出，结算时会跳过'}
              </span>
            </div>
            <div class="row-side">
              <button class="btn btn-danger btn-sm" data-remove="${item.id}" type="button">移除</button>
            </div>
          </div>`).join('')}
      </div>
      <div class="summary">
        <div>
          <div style="font-size:.85rem;color:var(--text-muted)">共 ${summary.quantity} 件商品</div>
          <div class="total">${money(summary.total)}</div>
        </div>
        <button class="btn" id="checkout" type="button" ${summary.quantity === 0 ? 'disabled' : ''}>结算</button>
      </div>`;

    updateCartBadge(summary.item_count);

    root.querySelectorAll('[data-remove]').forEach(btn => {
      btn.addEventListener('click', async () => {
        btn.disabled = true;
        try {
          await api('/cart/' + btn.dataset.remove, { method: 'DELETE' });
          toast('已移除');
          await load();
        } catch (e) {
          toast(e.message, true);
          btn.disabled = false;
        }
      });
    });

    root.querySelector('#cart-clear').addEventListener('click', async (ev) => {
      if (!confirm('确定清空购物车？')) return;
      ev.currentTarget.disabled = true;
      try {
        await api('/cart', { method: 'DELETE' });
        toast('购物车已清空');
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
        toast(`下单成功，共 ${money(data.total)}`);
        navigate('/orders');
      } catch (e) {
        toast(e.message, true);
        ev.currentTarget.disabled = false;
      }
    });
  }

  await load();
}

/* ============================ 10. 视图：订单 ============================ */

async function viewOrders(root) {
  root.innerHTML = `
    <div class="page-head"><h1>订单</h1></div>
    <div class="filters" style="gap:.5rem">
      <button class="btn btn-sm" id="tab-buyer" type="button">我买到的</button>
      <button class="btn btn-outline btn-sm" id="tab-seller" type="button">我卖出的</button>
    </div>
    <div id="orders-wrap"><div class="loading">加载中…</div></div>`;

  const wrap = root.querySelector('#orders-wrap');
  const tabBuyer = root.querySelector('#tab-buyer');
  const tabSeller = root.querySelector('#tab-seller');

  async function load(role) {
    tabBuyer.className = 'btn btn-sm' + (role === 'buyer' ? '' : ' btn-outline');
    tabSeller.className = 'btn btn-sm' + (role === 'seller' ? '' : ' btn-outline');
    wrap.innerHTML = '<div class="loading">加载中…</div>';

    let rows;
    try {
      ({ data: rows } = await api('/orders?role=' + role));
    } catch (e) {
      wrap.innerHTML = `<div class="empty"><h3>加载失败</h3><p>${esc(e.message)}</p></div>`;
      return;
    }

    if (!rows || rows.length === 0) {
      wrap.innerHTML = `<div class="empty"><h3>${role === 'buyer' ? '还没有买过东西' : '还没有卖出过东西'}</h3>
        <p><a href="#/" data-link>去逛逛</a></p></div>`;
      return;
    }

    const STATUS_TEXT = { pending: '待处理', completed: '已完成', cancelled: '已取消' };
    wrap.innerHTML = `<div class="list">${rows.map(o => `
      <div class="row">
        <div class="row-thumb">${imgTag(o.image_path, o.title)}</div>
        <div class="row-main">
          <a class="t" href="#/product/${o.product_id}" data-link>${esc(o.title)}</a>
          <span class="s">
            ${money(o.total_price)} ·
            ${role === 'buyer' ? '卖家 ' + esc(o.seller_name || '') : '买家 ' + esc(o.buyer_name || '')} ·
            ${esc(timeAgo(o.order_date))}
          </span>
        </div>
        <div class="row-side">
          <span class="tag ${o.status === 'completed' ? 'tag-new' : o.status === 'cancelled' ? 'tag-damaged' : 'tag-used'}">
            ${esc(STATUS_TEXT[o.status] || o.status)}
          </span>
          ${role === 'buyer' && !o.reviewed && o.status === 'completed'
            ? `<button class="btn btn-outline btn-sm" data-review="${o.id}" type="button">评价</button>` : ''}
          ${role === 'buyer' && o.reviewed
            ? `<span class="stars" title="已评价">${stars(o.review_rating)}</span>` : ''}
          ${role === 'buyer' && o.status === 'pending'
            ? `<button class="btn btn-danger btn-sm" data-cancel="${o.id}" type="button">取消</button>` : ''}
          ${role === 'seller' && o.status === 'pending'
            ? `<button class="btn btn-sm" data-complete="${o.id}" type="button">标记完成</button>` : ''}
        </div>
      </div>`).join('')}</div>`;

    wrap.querySelectorAll('[data-cancel]').forEach(btn => {
      btn.addEventListener('click', async () => {
        if (!confirm('取消这笔订单？商品会重新上架。')) return;
        try {
          await api('/orders/' + btn.dataset.cancel, { method: 'PATCH', body: { status: 'cancelled' } });
          toast('订单已取消');
          await load(role);
        } catch (e) { toast(e.message, true); }
      });
    });

    wrap.querySelectorAll('[data-complete]').forEach(btn => {
      btn.addEventListener('click', async () => {
        try {
          await api('/orders/' + btn.dataset.complete, { method: 'PATCH', body: { status: 'completed' } });
          toast('订单已完成');
          await load(role);
        } catch (e) { toast(e.message, true); }
      });
    });

    wrap.querySelectorAll('[data-review]').forEach(btn => {
      btn.addEventListener('click', () => openReviewDialog(btn.dataset.review, () => load(role)));
    });
  }

  tabBuyer.addEventListener('click', () => load('buyer'));
  tabSeller.addEventListener('click', () => load('seller'));
  await load('buyer');
}

function openReviewDialog(orderId, onDone) {
  const backdrop = document.createElement('div');
  backdrop.className = 'panel';
  backdrop.style.cssText = 'position:fixed;inset:0;max-width:none;margin:0;border-radius:0;' +
                           'background:rgba(0,0,0,.45);display:grid;place-items:center;z-index:300;padding:1rem;box-shadow:none;border:none;';
  backdrop.innerHTML = `
    <form class="panel" style="margin:0;width:min(92vw,420px)">
      <h2>评价这笔订单</h2>
      <div id="rv-alert"></div>
      <div class="form-row">
        <label for="rv-rating">评分</label>
        <select id="rv-rating">
          <option value="5">★★★★★ 非常满意</option>
          <option value="4">★★★★☆ 满意</option>
          <option value="3">★★★☆☆ 一般</option>
          <option value="2">★★☆☆☆ 不太满意</option>
          <option value="1">★☆☆☆☆ 很差</option>
        </select>
      </div>
      <div class="form-row">
        <label for="rv-comment">留言</label>
        <textarea id="rv-comment" maxlength="1000" placeholder="说说这次交易…"></textarea>
      </div>
      <div class="form-actions">
        <button class="btn" type="submit">提交评价</button>
        <button class="btn btn-outline" type="button" id="rv-cancel">取消</button>
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
      toast('评价已提交');
      backdrop.remove();
      onDone();
    } catch (e) {
      backdrop.querySelector('#rv-alert').innerHTML = `<div class="alert">${esc(e.message)}</div>`;
      btn.disabled = false;
    }
  });
}

/* ============================ 11. 视图：卖家主页 ============================ */

async function viewSeller(root, route) {
  const id = Number(route.params.id);
  root.innerHTML = '<div class="loading">加载中…</div>';

  let profile;
  try {
    ({ data: profile } = await api('/users/' + id));
  } catch (e) {
    root.innerHTML = `<div class="empty"><h3>用户打不开</h3><p>${esc(e.message)}</p></div>`;
    return;
  }

  const isSelf = state.user && Number(state.user.id) === id;
  const rating = profile.rating || { count: 0, average: null };

  root.innerHTML = `
    <div class="profile-head">
      ${avatarTag(profile, 'avatar', 72)}
      <div class="profile-main">
        <h1>${esc(profile.username)} ${isSelf ? '<span class="tag tag-new">我</span>' : ''}</h1>
        <p class="profile-bio">${esc(profile.bio || '这个人还没有写简介。')}</p>
        <div class="profile-meta">${esc(timeAgo(profile.created_at))}加入</div>
      </div>
      <div class="profile-stats">
        <div><strong>${profile.product_count || 0}</strong><span>在售</span></div>
        <div><strong>${profile.sold_count || 0}</strong><span>已售</span></div>
        <div><strong>${rating.count || 0}</strong><span>评价</span></div>
        <div><strong>${rating.average === null ? '—' : rating.average}</strong><span>评分</span></div>
      </div>
      ${isSelf ? `<div class="profile-actions">
        <a class="btn btn-outline btn-sm" href="#/account" data-link>账号设置</a>
        <a class="btn btn-outline btn-sm" href="#/my-products" data-link>我的发布</a>
      </div>` : ''}
    </div>

    <div class="tabs" id="profile-tabs">
      <button class="tab active" data-tab="products" type="button">在售商品</button>
      <button class="tab" data-tab="reviews" type="button">收到的评价 (${rating.count || 0})</button>
      <button class="tab" data-tab="guestbook" type="button">留言板</button>
    </div>
    <div id="profile-panel"><div class="loading">加载中…</div></div>`;

  const panel = root.querySelector('#profile-panel');
  const tabs = root.querySelectorAll('#profile-tabs .tab');
  const setActive = (name) => {
    tabs.forEach(t => t.classList.toggle('active', t.dataset.tab === name));
  };

  async function loadProducts() {
    panel.innerHTML = '<div class="loading">加载中…</div>';
    try {
      const { data } = await api('/products?seller_id=' + id + '&per_page=100');
      if (!data || data.length === 0) {
        panel.innerHTML = `<div class="empty">${isSelf ? '你还没有在售商品' : 'TA 还没有在售商品'}</div>`;
      } else {
        panel.innerHTML = `<div class="grid">${data.map(productCard).join('')}</div>`;
      }
    } catch (e) {
      panel.innerHTML = `<div class="empty">${esc(e.message)}</div>`;
    }
  }

  async function loadReviews(page = 1) {
    panel.innerHTML = '<div class="loading">加载中…</div>';
    let data, pg;
    try {
      const res = await api('/users/' + id + '/reviews?page=' + page + '&per_page=5');
      data = res.data.items || [];
      pg = res.meta.pagination || {};
    } catch (e) {
      panel.innerHTML = `<div class="empty">${esc(e.message)}</div>`;
      return;
    }

    const rows = data.map(r => {
      const canReply = isSelf;
      const canHide = state.user && state.user.is_admin;
      return `
        <div class="row">
          ${avatarTag({ username: r.buyer_name, avatar_path: r.buyer_avatar_path }, 'avatar', 40)}
          <div class="row-main">
            <span class="t"><span class="stars">${stars(r.rating)}</span> ${esc(r.buyer_name || '匿名')}</span>
            <span class="s">${esc(r.product_title || '')} · ${esc(timeAgo(r.created_at))}</span>
            <span class="s">${esc(r.comment || '（没有留言）')}</span>
            ${r.reply_text ? `<div class="review-reply"><strong>卖家回复：</strong>${esc(r.reply_text)}</div>` : ''}
            ${canReply ? `<form class="reply-form" data-rreply-form="${r.id}" hidden>
              <input maxlength="1000" value="${esc(r.reply_text || '')}" placeholder="回复这条评价…">
              <button class="btn btn-sm" type="submit">发送</button>
            </form>` : ''}
            ${canReply || canHide ? `<div class="comment-actions">
              ${canReply ? `<button class="btn btn-ghost btn-sm" data-rreply="${r.id}" type="button">回复 / 修改</button>` : ''}
              ${canHide ? `<button class="btn btn-ghost btn-sm" data-rhide="${r.id}" type="button">隐藏</button>` : ''}
            </div>` : ''}
          </div>
        </div>`;
    }).join('');

    panel.innerHTML = `
      ${data.length ? `<div class="list">${rows}</div>` : '<div class="empty">还没有收到评价</div>'}
      ${commentPager(pg, 'data-rpage')}`;

    panel.querySelectorAll('[data-rpage]').forEach(btn => {
      btn.addEventListener('click', () => loadReviews(Number(btn.dataset.rpage)));
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
        if (!reply) { toast('回复不能为空', true); return; }
        try {
          await api('/reviews/' + f.dataset.rreplyForm + '/reply', { method: 'POST', body: { reply } });
          await loadReviews(page);
        } catch (e) { toast(e.message, true); }
      });
    });

    panel.querySelectorAll('[data-rhide]').forEach(btn => {
      btn.addEventListener('click', async () => {
        if (!confirm('隐藏这条评价？')) return;
        try {
          await api('/admin/reviews/' + btn.dataset.rhide, { method: 'PATCH', body: { is_hidden: true } });
          await loadReviews(page);
        } catch (e) { toast(e.message, true); }
      });
    });
  }

  async function loadGuestbook(page = 1) {
    panel.innerHTML = '<div class="loading">加载中…</div>';
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
          <textarea id="guestbook-body" maxlength="1000" placeholder="给 ${esc(profile.username)} 留句话…"></textarea>
          <button class="btn btn-sm" type="submit">留言</button>
        </form>`
      : `<p class="form-hint">登录后可以留言。<a href="#/login?next=${encodeURIComponent('#/seller/' + id)}" data-link>去登录</a></p>`;

    const rows = data.map(c => {
      const canDelete = state.user && (Number(state.user.id) === Number(c.author_id)
        || isSelf || state.user.is_admin);
      return `
        <div class="comment">
          <div class="comment-main">
            ${avatarTag({ username: c.author_name, avatar_path: c.author_avatar_path }, 'avatar', 36)}
            <div style="flex:1;min-width:0">
              <div class="comment-meta"><strong>${esc(c.author_name)}</strong> · ${esc(timeAgo(c.created_at))}</div>
              <div class="comment-body">${esc(c.body)}</div>
              ${canDelete ? `<div class="comment-actions">
                <button class="btn btn-ghost btn-sm" data-gdel="${c.id}" type="button">删除</button>
              </div>` : ''}
            </div>
          </div>
        </div>`;
    }).join('');

    panel.innerHTML = `
      ${form}
      ${data.length ? `<div>${rows}</div>` : '<div class="empty">还没有留言</div>'}
      ${commentPager(pg, 'data-gpage')}`;

    const f = panel.querySelector('#guestbook-form');
    if (f) {
      f.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const body = f.querySelector('#guestbook-body').value.trim();
        if (!body) { toast('留言不能为空', true); return; }
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
        if (!confirm('确定删除这条留言？')) return;
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
      else if (name === 'reviews') loadReviews(1);
      else loadGuestbook(1);
    });
  });

  loadProducts();
}

/* ============================ 11b. 视图：账号设置 ============================ */

function clearFieldErrors(root) {
  root.querySelectorAll('.form-row').forEach(row => {
    row.classList.remove('invalid');
    const err = row.querySelector('.err');
    if (err) err.textContent = '';
  });
}

async function viewAccount(root) {
  const u = state.user;
  if (!u) return;

  root.innerHTML = `
    <div class="panel panel-wide">
      <h1>账号设置</h1>

      <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;margin-bottom:1.4rem">
        <span id="acct-avatar">${avatarTag(u, 'avatar', 72)}</span>
        <div style="flex:1;min-width:0">
          <div style="font-weight:700;font-size:1.1rem">${esc(u.username)}</div>
          <div style="color:var(--text-muted);font-size:.85rem">${esc(u.email || '')} · ${esc(timeAgo(u.created_at))}加入</div>
        </div>
        <a class="btn btn-outline btn-sm" href="#/seller/${u.id}" data-link>我的公开主页</a>
      </div>

      <form id="avatar-form" style="margin-bottom:1.4rem">
        <div class="form-row">
          <label for="a-avatar">头像</label>
          <input type="file" id="a-avatar" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp">
          <div class="form-hint">JPG / PNG / GIF / WebP，不超过 5 MB。</div>
        </div>
        <button class="btn btn-sm" type="submit">上传头像</button>
      </form>

      <h2 style="font-size:1.1rem;margin:1.5rem 0 .8rem">基本资料</h2>
      <div id="profile-alert"></div>
      <form id="profile-form" novalidate>
        <div class="form-row" data-field="username">
          <label for="acct-username">用户名</label>
          <input type="text" id="acct-username" name="username" maxlength="50" value="${esc(u.username)}" required>
          <div class="err"></div>
        </div>
        <div class="form-row" data-field="email">
          <label for="acct-email">电子邮箱</label>
          <input type="email" id="acct-email" name="email" maxlength="100" value="${esc(u.email || '')}" required>
          <div class="err"></div>
        </div>
        <div class="form-row" data-field="bio">
          <label for="acct-bio">个人简介</label>
          <textarea id="acct-bio" name="bio" maxlength="255" placeholder="一句话介绍自己，比如：大三计科，常出教材和数码配件。">${esc(u.bio || '')}</textarea>
          <div class="err"></div>
        </div>
        <div class="form-row" data-field="current_password">
          <label for="acct-current">当前密码（修改邮箱时需要）</label>
          <input type="password" id="acct-current" name="current_password" autocomplete="current-password" placeholder="只改用户名可以留空">
          <div class="err"></div>
        </div>
        <div class="form-actions"><button class="btn" type="submit">保存资料</button></div>
      </form>

      <h2 style="font-size:1.1rem;margin:1.8rem 0 .8rem">修改密码</h2>
      <div id="pw-alert"></div>
      <form id="pw-form" novalidate>
        <div class="form-row" data-field="current_password">
          <label for="pw-current">当前密码</label>
          <input type="password" id="pw-current" name="current_password" autocomplete="current-password" required>
          <div class="err"></div>
        </div>
        <div class="form-row" data-field="password">
          <label for="pw-new">新密码</label>
          <input type="password" id="pw-new" name="password" autocomplete="new-password" required>
          <div class="form-hint">至少 6 位</div>
          <div class="err"></div>
        </div>
        <div class="form-row" data-field="password_confirmation">
          <label for="pw-confirm">确认新密码</label>
          <input type="password" id="pw-confirm" name="password_confirmation" autocomplete="new-password" required>
          <div class="err"></div>
        </div>
        <div class="form-actions"><button class="btn" type="submit">修改密码</button></div>
      </form>

      <h2 style="font-size:1.1rem;margin:1.8rem 0 .8rem">我的内容</h2>
      <div class="form-actions">
        <a class="btn btn-outline" href="#/my-products" data-link>我的发布</a>
        ${u.is_admin ? `<a class="btn btn-outline" href="#/admin" data-link>管理后台</a>` : ''}
      </div>
      <p class="form-hint" style="margin-top:1rem">修改邮箱需要验证当前密码；本项目暂未做邮箱验证。</p>
    </div>`;

  root.querySelector('#avatar-form').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const file = root.querySelector('#a-avatar').files[0];
    if (!file) { toast('请选择头像图片', true); return; }
    const btn = ev.currentTarget.querySelector('button');
    const fd = new FormData();
    fd.append('avatar', file);
    btn.disabled = true;
    try {
      const { data } = await api('/account/avatar', { method: 'POST', form: fd });
      setUser(data);
      toast('头像已更新');
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
      email: form.elements.email.value.trim(),
      bio: form.elements.bio.value.trim(),
    };
    const current = form.elements.current_password.value;
    if (current) body.current_password = current;

    btn.disabled = true;
    try {
      const { data } = await api('/account', { method: 'PATCH', body });
      setUser(data);
      toast('资料已保存');
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

  root.querySelector('#pw-form').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const form = ev.currentTarget;
    const btn = form.querySelector('button[type=submit]');
    const alertBox = root.querySelector('#pw-alert');
    clearFieldErrors(root);
    alertBox.innerHTML = '';

    const body = {
      current_password: form.elements.current_password.value,
      password: form.elements.password.value,
      password_confirmation: form.elements.password_confirmation.value,
    };

    btn.disabled = true;
    try {
      await api('/account/password', { method: 'POST', body });
      toast('密码已修改');
      form.reset();
      btn.disabled = false;
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

/* ============================ 11c. 视图：我的发布 ============================ */

function renderMyPagination(pg, page) {
  const total = pg.total_pages || 0;
  if (total <= 1) return '';
  const parts = [];
  if (page > 1) parts.push(`<a href="#/my-products?page=${page - 1}" data-link>‹ 上一页</a>`);
  parts.push(`<span class="current">${page} / ${total}</span>`);
  if (page < total) parts.push(`<a href="#/my-products?page=${page + 1}" data-link>下一页 ›</a>`);
  return `<nav class="pagination" aria-label="分页">${parts.join('')}</nav>`;
}

async function viewMyProducts(root, route) {
  const page = Math.max(1, parseInt(route.query.page || '1', 10) || 1);

  root.innerHTML = `
    <div class="page-head">
      <h1>我的发布</h1>
      <span class="spacer"></span>
      <a class="btn" href="#/publish" data-link>发布新商品</a>
    </div>
    <div id="my-wrap"><div class="loading">加载中…</div></div>`;

  const wrap = root.querySelector('#my-wrap');
  let rows, pg;
  try {
    const res = await api('/products/mine?page=' + page + '&per_page=12');
    rows = res.data;
    pg = res.meta.pagination || {};
  } catch (e) {
    wrap.innerHTML = `<div class="empty"><h3>加载失败</h3><p>${esc(e.message)}</p></div>`;
    return;
  }

  if (!rows || rows.length === 0) {
    wrap.innerHTML = `<div class="empty"><h3>还没有发布过商品</h3>
      <p><a href="#/publish" data-link>发布第一件闲置</a></p></div>`;
    return;
  }

  wrap.innerHTML = `<div class="list">${rows.map(p => `
    <div class="row">
      <div class="row-thumb">${imgTag(p.image_path, p.title)}</div>
      <div class="row-main">
        <a class="t" href="#/product/${p.id}" data-link>${esc(p.title)}</a>
        <span class="s">${money(p.price)} · ${STATUS_LABEL[p.status] || p.status} · ${esc(timeAgo(p.created_at))}</span>
      </div>
      <div class="row-side">
        ${p.is_sold ? `<span class="tag tag-damaged">已售出</span>` : `
          <a class="btn btn-outline btn-sm" href="#/publish?edit=${p.id}" data-link>编辑</a>
          <button class="btn btn-danger btn-sm" data-del="${p.id}" type="button">删除</button>`}
      </div>
    </div>`).join('')}</div>${renderMyPagination(pg, page)}`;

  wrap.querySelectorAll('[data-del]').forEach(btn => {
    btn.addEventListener('click', async () => {
      if (!confirm('确定删除这件商品？删除后不可恢复。')) return;
      btn.disabled = true;
      try {
        await api('/products/' + btn.dataset.del, { method: 'DELETE' });
        toast('已删除');
        render();
      } catch (e) {
        toast(e.message, true);
        btn.disabled = false;
      }
    });
  });
}

/* ============================ 11d. 视图：管理后台 ============================ */

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
  if (page > 1) parts.push(`<a href="${q(page - 1)}" data-link>‹ 上一页</a>`);
  parts.push(`<span class="current">${page} / ${total}</span>`);
  if (page < total) parts.push(`<a href="${q(page + 1)}" data-link>下一页 ›</a>`);
  return `<nav class="pagination" aria-label="分页">${parts.join('')}</nav>`;
}

async function viewAdmin(root, route) {
  if (!state.user || !state.user.is_admin) {
    root.innerHTML = `<div class="empty"><h3>需要管理员权限</h3>
      <p><a href="#/" data-link>回到首页</a></p></div>`;
    return;
  }

  const tabs = [
    { key: 'users', label: '用户' },
    { key: 'reviews', label: '评价' },
    { key: 'product-comments', label: '商品留言' },
    { key: 'profile-comments', label: '主页留言' },
  ];
  const tab = tabs.some(t => t.key === route.query.tab) ? route.query.tab : 'users';
  const search = route.query.search || '';
  const page = Math.max(1, parseInt(route.query.page || '1', 10) || 1);

  root.innerHTML = `
    <div class="page-head"><h1>管理后台</h1><span class="count">用户与内容</span></div>
    <div class="tabs" id="admin-tabs">
      ${tabs.map(t => `<button class="tab ${t.key === tab ? 'active' : ''}" data-admin-tab="${t.key}" type="button">${t.label}</button>`).join('')}
    </div>
    <div id="admin-toolbar"></div>
    <div id="admin-wrap"><div class="loading">加载中…</div></div>`;

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
      if (n > 1) p.set('page', String(n));
      return '#/admin?' + p.toString();
    };
    const cur = pg.page || 1;
    const parts = [];
    if (cur > 1) parts.push(`<a href="${q(cur - 1)}" data-link>‹ 上一页</a>`);
    parts.push(`<span class="current">${cur} / ${total}</span>`);
    if (cur < total) parts.push(`<a href="${q(cur + 1)}" data-link>下一页 ›</a>`);
    return `<nav class="pagination" aria-label="分页">${parts.join('')}</nav>`;
  };

  const loadUsers = async () => {
    toolbar.innerHTML = `<div class="filters">
      <div class="field">
        <label for="admin-search">搜索用户</label>
        <input type="search" id="admin-search" value="${esc(search)}" placeholder="用户名或邮箱">
      </div>
      <button class="btn" id="admin-search-btn" type="button">搜索</button>
      <button class="btn btn-outline" id="admin-reset" type="button">重置</button>
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
      wrap.innerHTML = '<div class="empty"><h3>没有找到用户</h3></div>';
      return;
    }

    wrap.innerHTML = `<div class="list">${rows.map(u => `
      <div class="row">
        <div class="row-thumb" style="width:44px;height:44px">${avatarTag({ username: u.username, avatar_path: u.avatar_path }, 'avatar', 40)}</div>
        <div class="row-main">
          <span class="t">
            ${esc(u.username)}
            ${u.is_admin ? `<span class="tag tag-new">管理员</span>` : ''}
            ${u.is_active ? '' : `<span class="tag tag-damaged">已禁用</span>`}
          </span>
          <span class="s">${esc(u.email)} · ${u.product_count} 件商品 · ${esc(timeAgo(u.created_at))}加入</span>
        </div>
        <div class="row-side">
          ${u.id === state.user.id ? `<span class="s">当前账号</span>` : `
            <button class="btn btn-outline btn-sm" data-toggle-active="${u.id}" data-active="${u.is_active}" type="button">${u.is_active ? '禁用' : '启用'}</button>
            <button class="btn btn-outline btn-sm" data-toggle-admin="${u.id}" data-admin="${u.is_admin}" type="button">${u.is_admin ? '取消管理员' : '设为管理员'}</button>`}
        </div>
      </div>`).join('')}</div>${pager(pg)}`;

    const patch = async (id, body, message) => {
      try {
        await api('/admin/users/' + id, { method: 'PATCH', body });
        toast(message);
        render();
      } catch (e) { toast(e.message, true); }
    };

    wrap.querySelectorAll('[data-toggle-active]').forEach(btn => {
      btn.addEventListener('click', () => {
        const active = btn.dataset.active === 'true';
        if (active && !confirm('禁用后该用户会立即被登出，确定？')) return;
        patch(btn.dataset.toggleActive, { is_active: !active }, active ? '用户已禁用' : '用户已启用');
      });
    });
    wrap.querySelectorAll('[data-toggle-admin]').forEach(btn => {
      btn.addEventListener('click', () => {
        const isAdmin = btn.dataset.admin === 'true';
        if (isAdmin && !confirm('取消该用户的管理员权限？')) return;
        patch(btn.dataset.toggleAdmin, { is_admin: !isAdmin }, isAdmin ? '已取消管理员' : '已设为管理员');
      });
    });
  };

  const loadReviews = async () => {
    toolbar.innerHTML = '<p class="form-hint">隐藏只会让内容从公开页面消失，不会删除数据。</p>';
    const res = await api('/admin/reviews?page=' + page + '&per_page=10');
    const rows = res.data || [];
    const pg = res.meta.pagination || {};
    if (rows.length === 0) { wrap.innerHTML = '<div class="empty">还没有评价</div>'; return; }

    wrap.innerHTML = `<div class="list">${rows.map(r => `
      <div class="row">
        <div class="row-main">
          <span class="t">${esc(r.buyer_name)} → ${esc(r.seller_name)} · <span class="stars">${stars(r.rating)}</span>
            ${r.is_hidden ? `<span class="tag tag-damaged">已隐藏</span>` : ''}</span>
          <span class="s">${esc(r.product_title)} · ${esc(timeAgo(r.created_at))}</span>
          <span class="s">${esc(r.comment || '（没有留言）')}</span>
          ${r.reply_text ? `<div class="review-reply"><strong>卖家回复：</strong>${esc(r.reply_text)}</div>` : ''}
        </div>
        <div class="row-side">
          <button class="btn btn-outline btn-sm" data-hide-review="${r.id}" data-hidden="${r.is_hidden}" type="button">${r.is_hidden ? '恢复' : '隐藏'}</button>
        </div>
      </div>`).join('')}</div>${pager(pg)}`;

    wrap.querySelectorAll('[data-hide-review]').forEach(btn => {
      btn.addEventListener('click', async () => {
        const hidden = btn.dataset.hidden === 'true';
        try {
          await api('/admin/reviews/' + btn.dataset.hideReview, { method: 'PATCH', body: { is_hidden: !hidden } });
          toast(hidden ? '评价已恢复' : '评价已隐藏');
          render();
        } catch (e) { toast(e.message, true); }
      });
    });
  };

  const loadProductComments = async () => {
    toolbar.innerHTML = '<p class="form-hint">商品留言是售前问答，不影响评分。</p>';
    const res = await api('/admin/product-comments?page=' + page + '&per_page=10');
    const rows = res.data || [];
    const pg = res.meta.pagination || {};
    if (rows.length === 0) { wrap.innerHTML = '<div class="empty">还没有商品留言</div>'; return; }

    wrap.innerHTML = `<div class="list">${rows.map(c => `
      <div class="row">
        <div class="row-main">
          <span class="t">${esc(c.user_name)} · ${esc(c.product_title)}
            ${c.is_hidden ? `<span class="tag tag-damaged">已隐藏</span>` : ''}</span>
          <span class="s">${esc(c.body)}</span>
          <span class="s">${esc(timeAgo(c.created_at))}</span>
        </div>
        <div class="row-side">
          <button class="btn btn-outline btn-sm" data-hide-pc="${c.id}" data-hidden="${c.is_hidden}" type="button">${c.is_hidden ? '恢复' : '隐藏'}</button>
        </div>
      </div>`).join('')}</div>${pager(pg)}`;

    wrap.querySelectorAll('[data-hide-pc]').forEach(btn => {
      btn.addEventListener('click', async () => {
        const hidden = btn.dataset.hidden === 'true';
        try {
          await api('/admin/product-comments/' + btn.dataset.hidePc, { method: 'PATCH', body: { is_hidden: !hidden } });
          toast(hidden ? '留言已恢复' : '留言已隐藏');
          render();
        } catch (e) { toast(e.message, true); }
      });
    });
  };

  const loadProfileComments = async () => {
    toolbar.innerHTML = '<p class="form-hint">个人主页留言板内容。</p>';
    const res = await api('/admin/profile-comments?page=' + page + '&per_page=10');
    const rows = res.data || [];
    const pg = res.meta.pagination || {};
    if (rows.length === 0) { wrap.innerHTML = '<div class="empty">还没有主页留言</div>'; return; }

    wrap.innerHTML = `<div class="list">${rows.map(c => `
      <div class="row">
        <div class="row-main">
          <span class="t">${esc(c.author_name)} → ${esc(c.profile_name)}
            ${c.is_hidden ? `<span class="tag tag-damaged">已隐藏</span>` : ''}</span>
          <span class="s">${esc(c.body)}</span>
          <span class="s">${esc(timeAgo(c.created_at))}</span>
        </div>
        <div class="row-side">
          <button class="btn btn-outline btn-sm" data-hide-pfc="${c.id}" data-hidden="${c.is_hidden}" type="button">${c.is_hidden ? '恢复' : '隐藏'}</button>
        </div>
      </div>`).join('')}</div>${pager(pg)}`;

    wrap.querySelectorAll('[data-hide-pfc]').forEach(btn => {
      btn.addEventListener('click', async () => {
        const hidden = btn.dataset.hidden === 'true';
        try {
          await api('/admin/profile-comments/' + btn.dataset.hidePfc, { method: 'PATCH', body: { is_hidden: !hidden } });
          toast(hidden ? '留言已恢复' : '留言已隐藏');
          render();
        } catch (e) { toast(e.message, true); }
      });
    });
  };

  try {
    if (tab === 'users') await loadUsers();
    else if (tab === 'reviews') await loadReviews();
    else if (tab === 'product-comments') await loadProductComments();
    else await loadProfileComments();
  } catch (e) {
    wrap.innerHTML = `<div class="empty"><h3>加载失败</h3><p>${esc(e.message)}</p></div>`;
  }
}

/* ============================ 12. 视图：404 ============================ */

function viewNotFound(root) {
  root.innerHTML = `<div class="empty">
    <h3>页面不存在</h3>
    <p>地址可能打错了。<a href="#/" data-link>回到首页</a></p>
  </div>`;
}

/* ============================ 13. 会话与导航状态 ============================ */

async function refreshCartBadge() {
  if (!state.user) { updateCartBadge(0); return; }
  try {
    const { data } = await api('/cart');
    updateCartBadge(data.item_count || 0);
  } catch { /* 忽略 */ }
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

function syncNav() {
  const loggedIn = !!state.user;
  document.querySelectorAll('[data-auth-only]').forEach(el => { el.hidden = !loggedIn; });
  document.querySelectorAll('[data-guest-only]').forEach(el => { el.hidden = loggedIn; });
  document.querySelectorAll('[data-admin-only]').forEach(el => {
    el.hidden = !(loggedIn && state.user && state.user.is_admin);
  });

  const chip = document.getElementById('userchip');
  const name = document.getElementById('userchip-name');
  chip.hidden = !loggedIn;
  if (loggedIn) {
    name.innerHTML = `<a href="#/account" data-link>${esc(state.user.username)}</a>`;
  }

  const sideUser = document.getElementById('sidebar-user');
  if (sideUser) {
    sideUser.hidden = !loggedIn;
    if (loggedIn) {
      document.getElementById('sidebar-avatar').innerHTML = avatarTag(state.user, 'avatar', 36);
      document.getElementById('sidebar-name').textContent = state.user.username;
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
  const active = map[route.name] || '';
  document.querySelectorAll('.sidebar-nav a[data-nav]').forEach(a => {
    a.classList.toggle('active', a.dataset.nav === active);
  });
}

/* ============================ 14. 渲染调度 ============================ */

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

  // 需要登录的页面：未登录直接跳登录页，并记住原地址
  if (AUTH_REQUIRED.has(route.name) && !state.user) {
    navigate('/login?next=' + encodeURIComponent('#/' + route.name));
    return;
  }

  // 已登录用户访问登录/注册页 → 回首页
  if ((route.name === 'login' || route.name === 'register') && state.user) {
    navigate('/');
    return;
  }

  const root = document.getElementById('app');
  const token = ++renderToken;

  // 切换页面时回到顶部（翻页时不滚，否则用户看不到自己在哪一页）
  if (route.name !== 'home') window.scrollTo({ top: 0 });

  try {
    await VIEWS[route.name](root, route);
  } catch (e) {
    if (token !== renderToken) return;   // 已经被下一次渲染取代，丢弃
    root.innerHTML = `<div class="empty"><h3>出错了</h3><p>${esc(e.message)}</p></div>`;
    console.error(e);
  }
}

/* ============================ 15. 启动 ============================ */

function boot() {
  // 侧边栏（移动端抽屉）
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

  // 顶栏搜索
  document.getElementById('search-form').addEventListener('submit', (ev) => {
    ev.preventDefault();
    const kw = document.getElementById('search-input').value.trim();
    navigate('/' + (kw ? '?search=' + encodeURIComponent(kw) : ''));
  });

  // 登出
  document.getElementById('logout-btn').addEventListener('click', async () => {
    try {
      await api('/auth/logout', { method: 'POST' });
    } catch { /* 即使请求失败也把本地状态清掉 */ }
    setUser(null);
    updateCartBadge(0);
    toast('已登出');
    navigate('/');
  });

  // 会话恢复：刷新页面后从后端问一次"我是谁"
  api('/auth/me')
    .then(({ data }) => {
      setUser(data);
      refreshCartBadge();
    })
    .catch(() => setUser(null))
    .finally(() => {
      // 首屏数据到位后再渲染，避免闪一下"登录"又变回用户名
      window.addEventListener('hashchange', render);
      render();
    });

  onAuthChange(() => { syncNav(); refreshCartBadge(); });
  syncNav();
}

document.addEventListener('DOMContentLoaded', boot);
