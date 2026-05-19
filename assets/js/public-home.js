/**
 * Public marketing home: catalog fetch, search, personalize (fork session → editor).
 * Light bundle: no editor/renderer; incremental grid + optional auto-fork from ?fork=.
 * Session reuse: same browser may POST `reuse` to avoid duplicate guest forks (server validates).
 */
(function () {
    const cfg = window.ekoSampaPublic || {};
    const root = String(cfg.restRoot || '').replace(/\/?$/, '/');
    const nonce = cfg.nonce || '';
    const editorBase = String(cfg.editorBaseUrl || '').replace(/\/?$/, '/');

    const cacheTtlMs = 45000;
    const responseCache = new Map();
    const FORK_STORAGE_KEY = 'eko_sampa_pub_fork_v1';
    const FORK_CLIENT_TTL_MS = 36 * 60 * 60 * 1000;

    function el(id) {
        return document.getElementById(id);
    }

    function readForkMap() {
        try {
            const r = sessionStorage.getItem(FORK_STORAGE_KEY);
            const o = r ? JSON.parse(r) : {};
            return o && typeof o === 'object' ? o : {};
        } catch (e) {
            void e;
            return {};
        }
    }

    function writeForkEntry(masterId, sessionTemplateId, sessionToken) {
        try {
            const m = readForkMap();
            m[String(masterId)] = {
                tid: sessionTemplateId,
                tok: String(sessionToken || ''),
                t: Date.now(),
            };
            sessionStorage.setItem(FORK_STORAGE_KEY, JSON.stringify(m));
        } catch (e) {
            void e;
        }
    }

    function reuseBodyForMaster(masterId) {
        const m = readForkMap();
        const e = m[String(masterId)];
        if (!e || !e.tid || !e.tok) {
            return {};
        }
        if (!e.t || Date.now() - e.t > FORK_CLIENT_TTL_MS) {
            return {};
        }
        return {
            reuse: {
                template_id: parseInt(String(e.tid), 10) || 0,
                session_token: String(e.tok),
            },
        };
    }

    function thumbUrl(item) {
        if (item && item.thumbnail_url) {
            return item.thumbnail_url;
        }
        return '';
    }

    function escHtml(t) {
        return String(t || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function isNewTemplateItem(item) {
        const raw = item && item.created_at;
        if (!raw) {
            return false;
        }
        try {
            const d = new Date(String(raw).replace(' ', 'T'));
            if (Number.isNaN(d.getTime())) {
                return false;
            }
            return Date.now() - d.getTime() < 14 * 24 * 60 * 60 * 1000;
        } catch (e) {
            void e;
            return false;
        }
    }

    function catalogBadgesHtml(item) {
        const id = item && item.id ? parseInt(String(item.id), 10) : 0;
        if (!id) {
            return '';
        }
        const parts = [];
        const trend = Array.isArray(state.trendingIds) && state.trendingIds.indexOf(id) >= 0;
        const feat = Array.isArray(state.featuredIds) && state.featuredIds.indexOf(id) >= 0;
        if (trend) {
            parts.push(
                '<span class="rounded bg-emerald-600/95 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white shadow-sm">Popular</span>'
            );
        }
        if (feat) {
            parts.push(
                '<span class="rounded bg-indigo-600/95 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white shadow-sm">Destaque</span>'
            );
        }
        if (isNewTemplateItem(item)) {
            parts.push(
                '<span class="rounded bg-slate-700/90 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white shadow-sm">Novo</span>'
            );
        }
        if (!parts.length) {
            return '';
        }
        return '<div class="pointer-events-none absolute left-2 top-2 z-10 flex flex-wrap gap-1">' + parts.join('') + '</div>';
    }

    function cardHtml(item) {
        const id = item && item.id ? parseInt(String(item.id), 10) : 0;
        const nome = escHtml((item && item.nome) || '');
        const cat = escHtml((item && item.categoria) || '');
        const thumb = thumbUrl(item);
        const qp = item && item.guest_quick_print_enabled;
        const qpBadge = qp
            ? '<span class="absolute right-2 top-2 z-10 rounded bg-emerald-600/90 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white">QP</span>'
            : '';
        const badges = catalogBadgesHtml(item);
        const img = thumb
            ? '<img src="' +
              escHtml(thumb) +
              '" alt="" class="h-36 w-full object-cover transition duration-300 group-hover:scale-[1.03]" loading="lazy" decoding="async" />'
            : '<div class="flex h-36 w-full items-center justify-center bg-slate-100 text-xs text-slate-400">Preview</div>';
        return (
            '<article class="group relative flex flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm ring-1 ring-transparent transition duration-200 hover:-translate-y-0.5 hover:shadow-md hover:ring-indigo-200/50">' +
            '<div class="eko-pub-card-thumb relative block overflow-hidden">' +
            badges +
            qpBadge +
            img +
            '</div>' +
            '<div class="flex flex-1 flex-col gap-2 p-3">' +
            '<h3 class="line-clamp-2 text-sm font-semibold text-slate-900">' +
            nome +
            '</h3>' +
            (cat ? '<p class="text-xs text-slate-500">' + cat + '</p>' : '') +
            '<div class="mt-auto flex flex-wrap gap-2">' +
            '<button type="button" data-act="edit" data-id="' +
            id +
            '" class="eko-pub-btn rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-indigo-700">Personalizar</button>' +
            '<button type="button" data-act="print" data-id="' +
            id +
            '" class="eko-pub-btn rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 shadow-sm hover:bg-slate-50">Imprimir rápido</button>' +
            '</div></div></article>'
        );
    }

    function cacheGet(key) {
        const row = responseCache.get(key);
        if (!row) {
            return null;
        }
        if (Date.now() - row.t > cacheTtlMs) {
            responseCache.delete(key);
            return null;
        }
        return row.data;
    }

    function cacheSet(key, data) {
        responseCache.set(key, { t: Date.now(), data: data });
    }

    /**
     * Public catalog GET must not send X-WP-Nonce: cached HTML often embeds a stale nonce and WP
     * rejects the request even for permission_callback __return_true routes.
     * POST (e.g. fork session) still sends the nonce for CSRF where the server expects it.
     */
    function publicCatalogReadNoNonce(path) {
        const full = path.replace(/^\//, '');
        return /^public\/(catalog|categories)(\?|$)/.test(full);
    }

    async function apiGet(path) {
        const full = path.replace(/^\//, '');
        const ck = 'GET:' + full;
        const hit = cacheGet(ck);
        if (hit) {
            return hit;
        }
        if (!root || !/^https?:\/\//i.test(String(root).trim())) {
            throw new Error('REST root missing or invalid (ekoSampaPublic.restRoot).');
        }
        const url = root + full;
        const headers = {};
        if (!publicCatalogReadNoNonce(full) && nonce) {
            headers['X-WP-Nonce'] = nonce;
        }
        const res = await fetch(url, {
            credentials: 'same-origin',
            headers,
        });
        if (!res.ok) {
            let detail = res.statusText || 'Request failed';
            try {
                const j = await res.json();
                if (j && j.message) {
                    detail = String(j.message);
                }
            } catch (e) {
                void e;
            }
            throw new Error(detail);
        }
        const data = await res.json();
        cacheSet(ck, data);
        return data;
    }

    async function apiPost(path, body) {
        const url = root + path.replace(/^\//, '');
        const res = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-WP-Nonce': nonce,
                'Content-Type': 'application/json; charset=UTF-8',
            },
            body: body ? JSON.stringify(body) : '{}',
        });
        if (!res.ok) {
            let msg = res.statusText;
            try {
                const j = await res.json();
                if (j && j.message) {
                    msg = j.message;
                }
            } catch (e) {
                void e;
            }
            throw new Error(msg);
        }
        return res.json();
    }

    const state = {
        page: 1,
        perPage: 12,
        scope: 'all',
        search: '',
        categoria: '',
        loading: false,
        items: [],
        hasMore: false,
        err: '',
        io: null,
        featuredIds: [],
        trendingIds: [],
    };

    function setSkeleton(on) {
        const sk = el('eko-pub-skeleton');
        const grid = el('eko-pub-grid');
        if (sk) {
            sk.classList.toggle('hidden', !on);
        }
        if (grid) {
            grid.classList.toggle('hidden', on && state.page === 1);
        }
    }

    function renderGrid() {
        const grid = el('eko-pub-grid');
        const err = el('eko-pub-error');
        const loadRow = el('eko-pub-load-row');
        if (!grid) {
            return;
        }
        if (err) {
            err.textContent = state.err || '';
            err.classList.toggle('hidden', !state.err);
        }
        if (state.loading && state.page === 1) {
            setSkeleton(true);
            grid.innerHTML = '';
            return;
        }
        setSkeleton(false);
        grid.innerHTML = '';
        state.items.forEach(function (item) {
            const wrap = document.createElement('div');
            wrap.className = 'min-w-0';
            wrap.innerHTML = cardHtml(item);
            grid.appendChild(wrap);
        });
        if (loadRow) {
            loadRow.classList.toggle('hidden', !state.hasMore);
        }
    }

    async function load(reset) {
        if (state.loading) {
            return;
        }
        if (reset) {
            state.page = 1;
            state.items = [];
        }
        state.loading = true;
        state.err = '';
        renderGrid();
        try {
            const q = new URLSearchParams();
            q.set('page', String(state.page));
            q.set('per_page', String(state.perPage));
            q.set('scope', state.scope);
            if (state.search) {
                q.set('search', state.search);
            }
            if (state.categoria) {
                q.set('categoria', state.categoria);
            }
            const data = await apiGet('public/catalog?' + q.toString());
            const chunk = Array.isArray(data.items) ? data.items : [];
            state.featuredIds = Array.isArray(data.featured_ids)
                ? data.featured_ids.map(function (x) {
                      return parseInt(String(x), 10) || 0;
                  })
                : [];
            state.trendingIds = Array.isArray(data.trending_ids)
                ? data.trending_ids.map(function (x) {
                      return parseInt(String(x), 10) || 0;
                  })
                : [];
            if (state.page === 1) {
                state.items = chunk;
            } else {
                state.items = state.items.concat(chunk);
            }
            state.hasMore = !!data.has_more;
        } catch (e) {
            state.err = String((e && e.message) || e || 'Erro');
        } finally {
            state.loading = false;
            renderGrid();
        }
    }

    /**
     * @param {number} masterId
     * @param {{ openQp?: boolean }} [opts]
     */
    async function startSession(masterId, opts) {
        opts = opts || {};
        const body = Object.assign({}, reuseBodyForMaster(masterId));
        const data = await apiPost('public/templates/' + masterId + '/session', body);
        const tid = data && data.id ? parseInt(String(data.id), 10) : 0;
        const tok = data && data.session_token ? String(data.session_token) : '';
        if (!tid || !tok) {
            throw new Error('Resposta de sessão inválida');
        }
        writeForkEntry(masterId, tid, tok);
        let base = String(editorBase || '').trim();
        if (base.indexOf('http') !== 0) {
            base = window.location.origin.replace(/\/?$/, '/') + base.replace(/^\//, '');
        }
        const u = new URL(base);
        u.searchParams.set('template_id', String(tid));
        u.searchParams.set('session_token', tok);
        if (opts.openQp) {
            u.searchParams.set('eko_open_qp', '1');
        }
        window.location.assign(u.toString());
    }

    function bind() {
        const grid = el('eko-pub-grid');
        if (!grid) {
            return;
        }
        grid.addEventListener('click', function (ev) {
            const t = ev.target;
            if (!t || !t.closest) {
                return;
            }
            const btn = t.closest('.eko-pub-btn');
            if (!btn || btn.disabled) {
                return;
            }
            const id = parseInt(String(btn.getAttribute('data-id') || '0'), 10);
            const act = String(btn.getAttribute('data-act') || '');
            if (!id) {
                return;
            }
            ev.preventDefault();
            if (act === 'edit' || act === 'print') {
                btn.disabled = true;
                startSession(id, { openQp: act === 'print' })
                    .catch(function (e) {
                        state.err = String((e && e.message) || e);
                        renderGrid();
                    })
                    .finally(function () {
                        btn.disabled = false;
                    });
            }
        });

        const sb = el('eko-pub-search');
        if (sb) {
            let timer;
            sb.addEventListener('input', function () {
                clearTimeout(timer);
                timer = setTimeout(function () {
                    state.search = String(sb.value || '').trim();
                    void load(true);
                }, 400);
            });
        }

        const cat = el('eko-pub-category');
        if (cat) {
            cat.addEventListener('change', function () {
                state.categoria = String(cat.value || '');
                const u = new URL(window.location.href);
                if (state.categoria) {
                    u.searchParams.set('categoria', state.categoria);
                } else {
                    u.searchParams.delete('categoria');
                }
                window.history.replaceState({}, '', u.toString());
                void load(true);
            });
        }

        const tabs = document.querySelectorAll('.eko-pub-tab');
        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                const sc = String(tab.getAttribute('data-scope') || 'all');
                state.scope = sc;
                tabs.forEach(function (x) {
                    x.classList.remove('border-indigo-600', 'text-indigo-700');
                    x.classList.add('border-transparent', 'text-slate-600');
                });
                tab.classList.add('border-indigo-600', 'text-indigo-700');
                tab.classList.remove('border-transparent', 'text-slate-600');
                void load(true);
            });
        });

        const more = el('eko-pub-more');
        if (more) {
            more.addEventListener('click', function () {
                if (!state.hasMore || state.loading) {
                    return;
                }
                state.page += 1;
                void load(false);
            });
        }

        const sent = el('eko-pub-sentinel');
        if (sent && 'IntersectionObserver' in window) {
            state.io = new IntersectionObserver(
                function (ents) {
                    ents.forEach(function (en) {
                        if (!en.isIntersecting || !state.hasMore || state.loading) {
                            return;
                        }
                        state.page += 1;
                        void load(false);
                    });
                },
                { root: null, rootMargin: '240px', threshold: 0 }
            );
            state.io.observe(sent);
        }

        try {
            const u = new URL(window.location.href);
            const preCat = u.searchParams.get('categoria') || '';
            if (preCat && cat) {
                state.categoria = preCat;
            }
        } catch (e) {
            void e;
        }

        void load(true);

        apiGet('public/categories')
            .then(function (d) {
                const sel = el('eko-pub-category');
                if (!sel || !d || !Array.isArray(d.categories)) {
                    return;
                }
                d.categories.forEach(function (c) {
                    const o = document.createElement('option');
                    o.value = c;
                    o.textContent = c;
                    sel.appendChild(o);
                });
                if (state.categoria) {
                    sel.value = state.categoria;
                }
            })
            .catch(function () {
                void 0;
            });

        try {
            const u = new URL(window.location.href);
            const fork = parseInt(String(u.searchParams.get('fork') || '0'), 10);
            if (fork > 0) {
                void startSession(fork, {}).catch(function () {
                    void 0;
                });
            }
        } catch (e) {
            void e;
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bind);
    } else {
        bind();
    }
})();
