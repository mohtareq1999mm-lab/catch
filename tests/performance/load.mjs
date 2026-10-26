/**
 * Load harness — Group 1 public catalog endpoints (/api/v1/general/*).
 * Node >= 20 (native fetch). No dependencies.
 *
 * Safety: localhost-only; hard caps; per-request timeout; aborts on 25 consecutive
 * connection failures; designed 429s (limiter) are measured, not treated as crash.
 *
 * Usage:
 *   node tests/performance/load.mjs --stage smoke|lists|pages|products|g4|target|sustained|burst|stress|recovery|combined
 *        [--base http://localhost:8000] [--seconds N] [--rate R] [--concurrency C]
 *        [--out tests/performance/results] [--label mylabel]
 */
import { mkdirSync, appendFileSync } from "node:fs";
import { join } from "node:path";

const BASE = (process.argv.includes("--base") ? process.argv[process.argv.indexOf("--base") + 1] : "http://localhost:8000").replace(/\/$/, "");
if (!/^https?:\/\/(localhost|127\.0\.0\.1)/.test(BASE)) {
  console.error("REFUSED: base URL must be localhost/127.0.0.1");
  process.exit(2);
}

const secondsOf = (d) => (process.argv.includes("--seconds") ? +process.argv[process.argv.indexOf("--seconds") + 1] : d);
const rateOf = (d) => (process.argv.includes("--rate") ? +process.argv[process.argv.indexOf("--rate") + 1] : d);

const EP = {
  nav: { p: "/api/v1/general/nav-data" },
  nav1: { p: "/api/v1/general/nav-data?level=1" },
  cat: { p: "/api/v1/general/categories" },
  catMiss: { p: "/api/v1/general/categories/%D8%B3%20nope-404" },
  brands: { p: "/api/v1/general/brands" },
  brandMiss: { p: "/api/v1/general/brands/nope-404" },
  brandsProducts: { p: "/api/v1/general/brands-products" },
  banners: { p: "/api/v1/general/banners" },
  bannerMiss: { p: "/api/v1/general/banners/nope-404" },
  sliders: { p: "/api/v1/general/sliders" },
  sliderMiss: { p: "/api/v1/general/sliders/nope-404" },
  tags: { p: "/api/v1/general/tags" },
  tagMiss: { p: "/api/v1/general/tags/nope-404" },
  promotions: { p: "/api/v1/general/promotions" },
  promoMiss: { p: "/api/v1/general/promotions/nope-404" },
  coupons: { p: "/api/v1/general/coupons" },
  // ---- Group 2 ----
  cp: { p: "/api/v1/general/content-pages" },
  cpHome: { p: "/api/v1/general/content-pages/home" },
  cpMiss: { p: "/api/v1/general/content-pages/g2-nope-404" },
  sp: { p: "/api/v1/general/static-pages" },
  spAbout: { p: "/api/v1/general/static-pages/about-us" },
  spMiss: { p: "/api/v1/general/static-pages/g2-nope-404" },
  // ---- Group 3 ----
  products: { p: "/api/v1/general/products" },
  prodFlash: { p: "/api/v1/general/products?type=flash_sales_product" },
  prodDetail: { p: "/api/v1/general/products/nyx-epic-ink-eyeliner-ARzOX" },   // home-visible anchor (fast=0)
  prodMiss: { p: "/api/v1/general/products/g3-nope-404" },                     // uncached detail 404 path
  // ---- Group 4 ----
  fSales: { p: "/api/v1/general/flash-sales" },
  fSaleSlug: { p: "/api/v1/general/flash-sales/this-week-mega-discount" },
  fSaleMiss: { p: "/api/v1/general/flash-sales/g4-nope-404" },
  fQtySet: { p: "/api/v1/general/flash-sale-products?limit=5" },
  fWeek: { p: "/api/v1/general/flash-sale-products-ending-this-week" },
  fToday: { p: "/api/v1/general/flash-sale-products-ending-today" },
  settings: { p: "/api/v1/general/settings" },
  faqs: { p: "/api/v1/general/faqs" },
  geos: { p: "/api/v1/general/governorates" },
  geo1: { p: "/api/v1/general/governorates/1" },
  geoMiss: { p: "/api/v1/general/governorates/99999" },
  countries: { p: "/api/v1/general/countries" },
  cities: { p: "/api/v1/general/cities" },
  pickups: { p: "/api/v1/general/pickup-locations" },
  shipStatus: { p: "/api/v1/general/fast-shipping/status" },
  siteReviews: { p: "/api/v1/general/site-reviews" },
  currencies: { p: "/api/v1/general/currencies" },
};
const NAMES = Object.keys(EP);

const MIXES = {
  lists: ["nav", "cat", "brands", "banners", "sliders", "tags", "promotions", "coupons"],
  pages: ["cp", "cpHome", "sp", "spAbout", "sp", "cp"],   // B2 reads + shows; light on 404 traffic
  products: ["products", "products", "products", "prodFlash", "prodDetail", "prodMiss"], // 3×index, flash list, detail 200, uncached 404
  g4: [
    "fSales", "fSaleSlug", "fQtySet", "fWeek", "fToday", "fSaleMiss",             // flash block (1 uncached 404)
    "settings", "faqs", "geos", "geo1", "geoMiss", "countries", "cities",        // settings + geography
    "pickups", "shipStatus", "siteReviews", "currencies",
  ],
  combined: [
    ["nav", 0.10], ["nav1", 0.10],
    ["cat", 0.06], ["brands", 0.05], ["brandsProducts", 0.10], ["banners", 0.05],
    ["sliders", 0.05], ["tags", 0.05], ["promotions", 0.05], ["coupons", 0.05],
    ["catMiss", 0.09], ["brandMiss", 0.075], ["bannerMiss", 0.075],
    ["sliderMiss", 0.075], ["tagMiss", 0.075], ["promoMiss", 0.075],
  ],
};

function pick(mixKey) {
  if (mixKey === "combined") {
    let r = Math.random();
    for (const [name, w] of MIXES.combined) { r -= w; if (r <= 0) return name; }
    return MIXES.combined[0][0];
  }
  const list = MIXES[mixKey] ?? MIXES.lists;
  return list[Math.floor(Math.random() * list.length)];
}

function pct(arr, p) {
  if (!arr.length) return null;
  const a = [...arr].sort((x, y) => x - y);
  return +a[Math.min(a.length - 1, Math.floor((a.length * p) / 100))].toFixed(1);
}

const t0 = Date.now();

async function one(name) {
  const time = Date.now() - t0;
  try {
    const ctl = new AbortController();
    const timer = setTimeout(() => ctl.abort(), 8000);
    const started = performance.now();
    const res = await fetch(BASE + EP[name].p, { headers: { Accept: "application/json" }, signal: ctl.signal });
    clearTimeout(timer);
    const body = await res.text();
    const took = +(performance.now() - started).toFixed(1);
    const bytes = Buffer.byteLength(body);
    let j = null; try { j = JSON.parse(body); } catch {}
    let ok = true, why = [];
    switch (res.status) {
      case 200: if (!j || j.success !== true || !("data" in j)) { ok = false; why.push("envelope"); } break;
      case 404: case 401: if (!j || j.success !== false) { ok = false; why.push("envelope"); } break;
      case 409: if (!j || typeof j.message !== "string") { ok = false; why.push("envelope"); } break;
      // 429 by design. VERIFIED live shapes: (a) Handler/ThrottleRequests default {message:string,status:false},
      // (b) AppServiceProvider custom limiter closure {success:false,message,data:null} (rarely engaged).
      case 429: if (!j || typeof j.message !== "string") { ok = false; why.push("envelope"); } break;
      default: ok = false; why.push("unexpected_status");
    }
    if (typeof body === "string" && /#\d+ /.test(body)) { ok = false; why.push("stack_trace_leak"); }
    return { name, status: res.status, took, bytes, ok, why: why.join(","), at: time };
  } catch (e) {
    return { name, status: -1, took: 0, bytes: 0, ok: false, why: e.cause?.code ?? e.name, at: Date.now() - t0 };
  }
}

const famOf = (r) => (r.status === 429 ? "429" : r.status === 200 ? "200" : r.status === -1 ? "error" : String(r.status));

function summarize(sink) {
  const byName = {};
  for (const name of NAMES) {
    const rows = sink.filter((r) => r.name === name);
    if (!rows.length) continue;
    byName[name] = {
      n: rows.length,
      byStatus: rows.reduce((a, r) => (a[r.status] = (a[r.status] || 0) + 1, a), {}),
      bytes_avg: +(rows.reduce((s, r) => s + r.bytes, 0) / rows.length).toFixed(0),
      ok200: rows.filter((r) => r.status === 200).length,
      bad: rows.filter((r) => !r.ok).slice(0, 6).map((r) => `${r.status}:${r.why}`),
    };
    for (const fam of ["200", "429"]) {
      const lant = rows.filter((r) => famOf(r) === fam).map((r) => r.took);
      if (!lant.length) continue;
      byName[name][`lat_${fam}`] = { n: lant.length, p50: pct(lant, 50), p90: pct(lant, 90), p95: pct(lant, 95), p99: pct(lant, 99), max: pct(lant, 100) };
    }
  }
  const lant200 = sink.filter((r) => r.status === 200).map((r) => r.took);
  const lant429 = sink.filter((r) => r.status === 429).map((r) => r.took);
  return {
    total: sink.length,
    byStatus: sink.reduce((a, r) => (a[r.status] = (a[r.status] || 0) + 1, a), {}),
    envelope_ok: sink.filter((r) => r.ok).length,
    envelopes_failed: sink.filter((r) => !r.ok && r.status !== -1).length, // real HTTP responses with a bad envelope only
    client_errors: sink.filter((r) => r.status === -1).length,            // network/timeout aborts (e.g. client 8s cap at saturation) — NOT server envelope failures
    lat_200: lant200.length ? { n: lant200.length, p50: pct(lant200, 50), p90: pct(lant200, 90), p95: pct(lant200, 95), p99: pct(lant200, 99), max: pct(lant200, 100) } : null,
    lat_429: lant429.length ? { n: lant429.length, p50: pct(lant429, 50), p90: pct(lant429, 90), p95: pct(lant429, 95), p99: pct(lant429, 99), max: pct(lant429, 100) } : null,
    byName,
  };
}

async function run(rateRps, durationSec, concurrency, mixKey, outDir, label) {
  const sink = [];
  const gap = rateRps > 0 ? 1000 / rateRps : 5e6;
  const startedAt = Date.now();
  const scheduledTotal = Math.min(Math.floor((durationSec * 1000) / gap), 25000);
  const limit = Math.max(1, Math.min(concurrency || 8, 32));
  let inflight = 0, failedStreak = 0, aborted = "";
  const issuers = [];
  for (let i = 0; i < scheduledTotal && !aborted; i++) {
    const fireAt = startedAt + i * gap;
    const wait = fireAt - Date.now();
    if (wait > 0) await new Promise((r) => setTimeout(r, wait));
    if (Date.now() - startedAt >= (durationSec + 2) * 1000) break;
    while (inflight >= limit) { await new Promise((r) => setTimeout(r, 10)); }
    inflight++;
    const name = pick(mixKey);
    issuers.push(one(name).then((r) => {
      sink.push(r);
      if (r.status === -1) { failedStreak++; if (failedStreak >= 25) aborted = "connection_lost"; } else failedStreak = 0;
      inflight--;
    }));
  }
  await Promise.all(issuers);
  const wall = +((Date.now() - startedAt) / 1000).toFixed(1);
  mkdirSync(outDir, { recursive: true });
  const summary = summarize(sink);
  const payload = {
    stage: label, base: BASE, mix: mixKey, target_rate: rateRps,
    actual_rate: +(sink.length / wall).toFixed(2), wall_s: wall,
    started_at: new Date(startedAt).toISOString(), aborted: aborted || null,
    summary,
  };
  appendFileSync(join(outDir, `${label}.json`), JSON.stringify(payload, null, 2));
  console.log(JSON.stringify({
    stage: label, reqs: sink.length, actual_rps: payload.actual_rate, wall: wall,
    status: summary.byStatus, envelopes_failed: summary.envelopes_failed, aborted: aborted || null,
    p50_200: summary.lat_200?.p50 ?? null, p95_200: summary.lat_200?.p95 ?? null, p99_200: summary.lat_200?.p99 ?? null,
    p50_429: summary.lat_429?.p50 ?? null, p95_429: summary.lat_429?.p95 ?? null, max: summary.lat_200?.max,
  }));
  for (const [n, s] of Object.entries(summary.byName)) {
    const l = s.lat_200 ?? s.lat_429;
    console.log(`  ${n.padEnd(14)} n=${s.n} stats=${JSON.stringify(s.byStatus)} p95=${l?.p95 ?? "?"} bytes~${s.bytes_avg}`);
  }
  return payload;
}

const OUT = process.argv.includes("--out") ? process.argv[process.argv.indexOf("--out") + 1] : "tests/performance/results";
if (process.argv.includes("--list-endpoints")) { console.log("endpoints:", NAMES.join(",")); process.exit(0); }
const stagename = process.argv.includes("--stage") ? process.argv[process.argv.indexOf("--stage") + 1] : "smoke";

const STAGES = {
  smoke: { rate: 1, dur: secondsOf(30), conc: 2, mix: "lists" },
  baseline: { rate: rateOf(2), dur: secondsOf(120), conc: 4, mix: "lists" },
  target: { rate: rateOf(6.67), dur: secondsOf(300), conc: 8, mix: "lists" },
  sustained: { rate: rateOf(6.67), dur: secondsOf(900), conc: 8, mix: "lists" },
  burst: { rate: rateOf(20), dur: secondsOf(30), conc: 12, mix: "lists" },
  recovery: { rate: 2, dur: secondsOf(120), conc: 4, mix: "lists" },
  combined: { rate: rateOf(6.67), dur: secondsOf(300), conc: 8, mix: "combined" },
  cold: { rate: 2, dur: secondsOf(60), conc: 4, mix: "lists" },
  pages: { rate: rateOf(6.67), dur: secondsOf(300), conc: 8, mix: "pages" },
  products: { rate: rateOf(6.67), dur: secondsOf(300), conc: 8, mix: "products" },
  g4: { rate: rateOf(6.67), dur: secondsOf(300), conc: 8, mix: "g4" },
};

const S = STAGES[stagename];
if (!S) {
  if (stagename === "stress") {
    console.log("STRESS ladder 8->10->12->16->20 rps, 60s each");
    for (const r of [8, 10, 12, 16, 20]) {
      const p = await run(r, 60, 10, "lists", OUT, `stress-${r}rps-${new Date().toISOString().replace(/[:.]/g, "-")}`);
      if (p.aborted) break;
    }
    process.exit(0);
  }
  console.error("unknown stage", stagename);
  process.exit(2);
}

await run(S.rate, S.dur, S.conc, S.mix, OUT, process.argv.includes("--label") ? process.argv[process.argv.indexOf("--label") + 1] : `${stagename}-${new Date().toISOString().replace(/[:.]/g, "-")}`);
