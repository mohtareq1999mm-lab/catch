// Group 4 live smoke probe — all public GETs + channel split + 404s + POST boundaries.
const BASE = "http://127.0.0.1:8000";
const G = `${BASE}/api/v1/general`;

const wants = (u, extra = {}) => `${u} | ${extra.headers ? (extra.headers["X-Channel"] ?? "default") : "default"}`;

const probe = async (label, u, extra = {}) => {
  try {
    const res = await fetch(u, { signal: AbortSignal.timeout(8000), ...extra });
    const text = await res.text();
    let j = null;
    try { j = JSON.parse(text); } catch {}
    const okEnv = j && j.success === true && "data" in j;
    const data = j?.data;
    const kind = Array.isArray(data) ? `array(${data.length})`
      : data && typeof data === "object" ? (data.data ? `paginator(${data.data?.length ?? "?"})` : "object")
      : typeof data;
    console.log(
      `${res.status === 200 && okEnv ? "GET-OK " : "!!     "} ${label.padEnd(34)} status=${res.status} kind=${kind} bytes=${Buffer.byteLength(text)}${res.status !== 200 ? ` body=${text.slice(0, 140)}` : ""}`
    );
    return { res, j, text };
  } catch (e) {
    console.log(`NETFAIL ${label} ${e?.cause?.code ?? e?.name ?? e}`);
    return null;
  }
};

// ---------- channel split: ENDING THIS WEEK (G4-F-01 proof) ----------
{
  const fast = await probe(`flash-week [fast-shipping]`, `${G}/flash-sale-products-ending-this-week?limit=100`, { headers: { "X-Channel": "fast-shipping" } });
  const home = await probe(`flash-week [home]`, `${G}/flash-sale-products-ending-this-week?limit=100`, { headers: { "X-Channel": "home" } });
  const none = await probe(`flash-week [default]`, `${G}/flash-sale-products-ending-this-week?limit=100`);
  const ids = (j) => (j?.data?.data ?? j?.data ?? []).map((p) => p.id);
  if (fast && home) {
    const fi = new Set(ids(fast.j)), hi = new Set(ids(home.j));
    const eq = (a, b) => [...a].sort((x, y) => x - y).join() === [...b].sort((x, y) => x - y).join();
    const leak = [...hi].filter((i) => fi.has(i));
    const pass = fi.size === 6 && hi.size === 8 && leak.length === 0 && eq(fi, [1, 9, 25, 57, 69, 165]) && eq(hi, [29, 41, 53, 77, 105, 109, 149, 169]);
    console.log(
      `WEEK-SPLIT fast=${[...fi].join(",")} (${fi.size}) home=${[...hi].join(",")} (${hi.size}) home_leak=${leak.length ? leak.join(",") : "none"} → ${pass ? "PASS" : "MISMATCH-DB-OR-FIX"}`
    );
  }
  if (none && fast && home) {
    console.log(`WEEK-SPLIT default=${ids(none.j).length} (default channel = home via ChannelMiddleware)`);
  }
}

// ---------- channel split: ENDING TODAY (empty by data) ----------
await probe(`flash-today [home]`, `${G}/flash-sale-products-ending-today`, { headers: { "X-Channel": "home" } });
await probe(`flash-today [fast-shipping]`, `${G}/flash-sale-products-ending-today`, { headers: { "X-Channel": "fast-shipping" } });

// ---------- remaining public GETs ----------
await probe(`flash-sale-products (qty set)`, `${G}/flash-sale-products?limit=5`);
const fq = await probe(`flash-sale-products (qty set)`, `${G}/flash-sale-products?limit=5`);
if (fq) {
  const list = fq.j?.data ?? [];
  const uniq = new Set(list.map((p) => p.id)).size;
  console.log(`QTY-SET count=${list.length} unique=${uniq} (limit=5 per sale; flattened unique across sales)`);
}
await probe(`flash-sales`, `${G}/flash-sales`);
await probe(`flash-sales?slug=... directive`, `${G}/flash-sales?slug=this-week-mega-discount`);
await probe(`flash-sales/{slug} valid`, `${G}/flash-sales/this-week-mega-discount`);
await probe(`flash-sales/{slug} miss`, `${G}/flash-sales/no-such-sale-xyz`);
await probe(`settings`, `${G}/settings`);
await probe(`faqs`, `${G}/faqs`);
const gov = await probe(`governorates`, `${G}/governorates`);
const cnt = await probe(`countries`, `${G}/countries`);
const cit = await probe(`cities`, `${G}/cities`);
const pick = await probe(`pickup-locations`, `${G}/pickup-locations`);
await probe(`pickup-locations/{id}` , `${G}/pickup-locations/1`);
await probe(`pickup-locations/{id} inactive`, `${G}/pickup-locations/5`);
await probe(`pickup-locations/{id} miss`, `${G}/pickup-locations/99999`);
await probe(`fast-shipping/status`, `${G}/fast-shipping/status`);
const sr = await probe(`site-reviews`, `${G}/site-reviews`);
const cur = await probe(`currencies`, `${G}/currencies`);
await probe(`governorates/1`, `${G}/governorates/1`);
await probe(`governorates/99999`, `${G}/governorates/99999`);
await probe(`governorates/abc (whereNumber)`, `${G}/governorates/abc`);
await probe(`countries/1`, `${G}/countries/1`);
await probe(`countries/99999`, `${G}/countries/99999`);
await probe(`cities/1`, `${G}/cities/1`);
await probe(`cities/99999`, `${G}/cities/99999`);
await probe(`cities/abc (whereNumber)`, `${G}/cities/abc`);

// data sanity spot-checks
if (sr) {
  const list = sr.j?.data ?? [];
  const statuses = list.map((r) => r.status);
  console.log(`SR-CHECK count=${list.length} status_values=${[...new Set(statuses)].join(",")} (approved-only expected: none but approved)`);
}
if (cur) {
  const list = cur.j?.data ?? [];
  console.log(`CUR-CHECK count=${list.length} codes=${list.map((c) => c.code).join(",")} active_all=${list.every((c) => c.is_active)}`);
}
if (gov) console.log(`GOV-CHECK count=${gov.j?.data?.length} (db active=35)`);
if (cnt) console.log(`CNT-CHECK count=${cnt.j?.data?.length} (db active=2)`);
if (cit) console.log(`CIT-CHECK count=${cit.j?.data?.length} (db total=102)`);
if (pick) console.log(`PICK-CHECK count=${pick.j?.data?.data?.length ?? pick.j?.data?.length} total=${pick.j?.data?.total ?? "?"} (db active=4 inactive=1)`);
