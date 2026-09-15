/**
 * Products Cursor Pagination — Frontend Flow
 *
 * Backend: GET /api/v1/general/products?pagination=cursor
 * Contract: backend owns cursor, returns it via data.links.next_page_url
 * Frontend: consumes next_page_url, appends, rotates cursor, never generates cursor
 *
 * Usage:
 *   import { createProductCursorStore } from './products-cursor-pagination';
 *   const store = createProductCursorStore({ limit: 15, onUpdate: render });
 *   await store.fetchFirstPage({ category: 'care' });
 *   // on Load More click: await store.loadMore();
 *   // on filter change:   await store.fetchFirstPage({ category: 'electronics', brand: 'nike' });
 *
 * This module is framework-agnostic (plain JS, Vue, React). It uses window.axios
 * if available, otherwise fetch. It is the ONLY place that handles pagination=cursor
 * continuation — filters are passed through unchanged.
 */

const ENDPOINT = '/api/v1/general/products';

/**
 * Build a query string from filters object.
 * Filters are serialized as comma-separated values, URL-encoded.
 * pagination=cursor is always added by the store.
 *
 * @param {object} filters - e.g. { category:'care', brand:'nike', min_price:10, max_price:100, limit:15, order:'desc', order_price:'asc', type:'index', tags:'summer' }
 * @param {number|null} limitOverride - optional limit override
 * @returns {string} "?pagination=cursor&category=care&..."
 */
export function buildProductQuery(filters = {}, limitOverride = null) {
    const params = new URLSearchParams();
    params.set('pagination', 'cursor');

    const limit = limitOverride ?? filters.limit;
    if (limit != null && limit !== '') params.set('limit', String(limit));

    // Copy all known listing params as-is — never rename, never drop
    const passthroughKeys = [
        'type', 'order', 'order_price', 'search',
        'category', 'categories', 'brand', 'brands', 'tags', 'tag',
        'promotion', 'flash_sale', 'banner', 'slider',
        'min_price', 'max_price', 'minPrice', 'maxPrice', 'price_min', 'price_max',
        'rating', 'rating_min', 'rating_max',
        'productsId', 'categoriesId', 'brandsId', 'tagsId', 'promotionsId', 'flashSalesId', 'bannersId', 'slidersId', 'couponsId',
        'height', 'width', 'length', 'weight',
        'height_min', 'height_max', 'width_min', 'width_max', 'length_min', 'length_max', 'weight_min', 'weight_max',
    ];

    // Also pass through any dynamic attribute slugs and any other filter keys present
    for (const [key, value] of Object.entries(filters)) {
        if (key === 'limit' || key === 'pagination' || key === 'cursor' || key === 'page') continue;
        if (value == null || value === '') continue;
        if (passthroughKeys.includes(key) || !params.has(key)) {
            params.set(key, String(value));
        }
    }

    const qs = params.toString();
    return qs ? `?${qs}` : '?pagination=cursor';
}

/**
 * Extract next_page_url and prev_page_url from API response.
 * Handles both wrapped {success,data:{data,links}} and direct shapes.
 */
export function extractPaginationLinks(responseData) {
    // Laravel ApiResponse: { success, message, data: { data:[], links:{}, filters:[], categories:[] } }
    const data = responseData?.data ?? responseData;
    const links = data?.links ?? {};
    return {
        nextPageUrl: links.next_page_url ?? null,
        prevPageUrl: links.prev_page_url ?? null,
        perPage: links.per_page ?? null,
    };
}

export function extractProducts(responseData) {
    const data = responseData?.data ?? responseData;
    return data?.data ?? [];
}

/**
 * Perform GET request. Prefers window.axios, falls back to fetch.
 */
async function httpGet(url) {
    if (window.axios) {
        const res = await window.axios.get(url);
        return res.data;
    }
    const res = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return res.json();
}

/**
 * Create a cursor pagination store.
 *
 * State:
 *   products: [] — accumulated list (append-only)
 *   nextPageUrl: string|null — latest backend continuation (null = end)
 *   prevPageUrl: string|null
 *   hasMore: boolean — derived from nextPageUrl !== null
 *   isLoading: boolean — first page in flight
 *   isLoadingMore: boolean — loadMore in flight (prevents duplicates)
 *   error: string|null
 *   currentFilters: object — last filters used for first page
 *
 * Methods:
 *   fetchFirstPage(filters) — resets and loads page 1 without cursor
 *   loadMore() — loads nextPageUrl, appends, rotates cursor
 *   reset() — clears products/nextPageUrl/hasMore
 *   getState() — snapshot
 *
 * Filters are NOT modified; pagination=cursor is added, cursor comes only from backend.
 */
export function createProductCursorStore(options = {}) {
    const limit = options.limit ?? 15;
    const onUpdate = options.onUpdate ?? (() => {});
    const onError = options.onError ?? (() => {});

    let state = {
        products: [],
        nextPageUrl: null,
        prevPageUrl: null,
        hasMore: true,
        isLoading: false,
        isLoadingMore: false,
        error: null,
        currentFilters: {},
    };

    function setState(patch) {
        state = { ...state, ...patch };
        onUpdate({ ...state });
        return state;
    }

    function getState() {
        return { ...state, products: [...state.products] };
    }

    async function fetchFirstPage(filters = {}) {
        // Reset pagination state per spec: products=[], next_page_url=null, hasMore=true
        const queryFilters = { ...filters };
        if (queryFilters.limit == null) queryFilters.limit = limit;

        setState({
            products: [],
            nextPageUrl: null,
            prevPageUrl: null,
            hasMore: true,
            isLoading: true,
            isLoadingMore: false,
            error: null,
            currentFilters: { ...queryFilters },
        });

        const queryString = buildProductQuery(queryFilters);
        const url = `${ENDPOINT}${queryString}`;

        try {
            const json = await httpGet(url);
            const products = extractProducts(json);
            const { nextPageUrl, prevPageUrl } = extractPaginationLinks(json);
            const hasMore = nextPageUrl !== null;

            setState({
                products: [...products],
                nextPageUrl,
                prevPageUrl,
                hasMore,
                isLoading: false,
                error: null,
            });
            return getState();
        } catch (err) {
            const msg = err?.response?.data?.message || err.message || 'Failed to load products';
            setState({ isLoading: false, error: msg });
            onError(err);
            throw err;
        }
    }

    async function loadMore() {
        // AC8: prevent duplicate concurrent requests
        if (state.isLoadingMore || state.isLoading) return getState();
        // AC7: stop when end reached
        if (!state.hasMore || !state.nextPageUrl) return getState();

        setState({ isLoadingMore: true, error: null });

        // Required: use backend-provided nextPageUrl directly — never rebuild query or generate cursor
        // This preserves category, brand, price, tags, sorting, limit, type etc. via withQueryString()
        const url = state.nextPageUrl;

        try {
            const json = await httpGet(url);
            const newProducts = extractProducts(json);
            const { nextPageUrl, prevPageUrl } = extractPaginationLinks(json);
            const hasMore = nextPageUrl !== null;

            // AC5: append, not replace
            // AC6: rotate cursor — replace old nextPageUrl with new
            setState({
                products: [...state.products, ...newProducts],
                nextPageUrl,
                prevPageUrl,
                hasMore,
                isLoadingMore: false,
                error: null,
            });
            return getState();
        } catch (err) {
            const msg = err?.response?.data?.message || err.message || 'Failed to load more';
            setState({ isLoadingMore: false, error: msg });
            onError(err);
            throw err;
        }
    }

    function reset() {
        setState({
            products: [],
            nextPageUrl: null,
            prevPageUrl: null,
            hasMore: true,
            isLoading: false,
            isLoadingMore: false,
            error: null,
            currentFilters: {},
        });
    }

    return {
        getState,
        fetchFirstPage,
        loadMore,
        reset,
        // Exposed for testing
        _buildQuery: buildProductQuery,
        _extractLinks: extractPaginationLinks,
        _extractProducts: extractProducts,
    };
}

// Default export for convenience
export default createProductCursorStore;

/**
 * Example wiring — Plain JS
 *
 * ```html
 * <div id="products"></div>
 * <button id="loadMore">Load More</button>
 * <div id="status"></div>
 * ```
 * ```js
 * import { createProductCursorStore } from './products-cursor-pagination.js';
 *
 * const store = createProductCursorStore({
 *   limit: 15,
 *   onUpdate: (s) => {
 *     document.getElementById('products').innerHTML = s.products.map(p => `<div>${p.name}</div>`).join('');
 *     document.getElementById('loadMore').style.display = s.hasMore ? 'block' : 'none';
 *     document.getElementById('status').textContent = s.isLoadingMore ? 'Loading...' : (s.hasMore ? '' : 'No more products');
 *     document.getElementById('loadMore').disabled = s.isLoadingMore;
 *   }
 * });
 *
 * // First load
 * store.fetchFirstPage({ category: 'care', brand: 'nike', min_price: 10, max_price: 100 });
 *
 * // Load More click — guarded against duplicates by isLoadingMore
 * document.getElementById('loadMore').addEventListener('click', () => store.loadMore());
 *
 * // Filter change — resets cursor and starts new first request without old cursor
 * document.getElementById('categorySelect').addEventListener('change', (e) => {
 *   store.fetchFirstPage({ category: e.target.value, brand: 'nike' });
 * });
 * ```
 *
 * Example wiring — Vue 2/3
 *
 * ```js
 * import { createProductCursorStore } from '@/js/products-cursor-pagination';
 * export default {
 *   data() { return { store: null, ui: { products:[], hasMore:true, isLoadingMore:false } }; },
 *   created() {
 *     this.store = createProductCursorStore({
 *       limit: 15,
 *       onUpdate: (s) => { this.ui = { ...s }; }
 *     });
 *     this.store.fetchFirstPage({ category: 'care' });
 *   },
 *   methods: {
 *     onLoadMore() { this.store.loadMore(); },
 *     onFilterChange(filters) { this.store.fetchFirstPage(filters); }
 *   }
 * };
 * ```
 *
 * Example wiring — React
 *
 * ```jsx
 * import { useEffect, useState, useRef } from 'react';
 * import { createProductCursorStore } from './products-cursor-pagination';
 * function ProductList({ filters }) {
 *   const [ui, setUi] = useState({ products:[], hasMore:true, isLoadingMore:false });
 *   const storeRef = useRef(null);
 *   if (!storeRef.current) storeRef.current = createProductCursorStore({ onUpdate: setUi });
 *   useEffect(() => { storeRef.current.fetchFirstPage(filters); }, [filters]);
 *   return (<><div>{ui.products.map(p=><div key={p.id}>{p.name}</div>)}</div>
 *     {ui.hasMore ? <button disabled={ui.isLoadingMore} onClick={()=>storeRef.current.loadMore()}>Load More</button> : <div>No more products</div>}
 *   </>);
 * }
 * ```
 */
