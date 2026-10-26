/** Fetch ID/slug lists from the API for semantic cross-check vs. semantic-db.php */
const BASE = "http://localhost:8000";
const G = "/api/v1/general";

async function j(path) { const r = await fetch(BASE + path, { headers: { Accept: "application/json" } }); const b = await r.text(); return JSON.parse(b); }

// tags: paginated nested data
const tags = await j(`${G}/tags?limit=30&order=asc`);
const tArr = Array.isArray(tags.data) ? tags.data : (tags.data?.data ?? []);
console.log("### tags fetch limit=30 n=" + tArr.length);
for (const t of tArr.slice(0, 12)) console.log(`  [${t.id}] ${t.slug}`);

// brands
const brands = await j(`${G}/brands`);
const bArr = Array.isArray(brands.data) ? brands.data : (brands.data?.data ?? []);
console.log("### brands n=" + bArr.length);
for (const b of bArr.slice(0, 10)) console.log(`  [${b.id}] ${b.slug ?? b.name}`);

// brand single slug semantic: reuse the first brand slug to verify 200-by-id match
if (bArr.length && bArr[0]?.slug) {
  const single = await j(`${G}/brands/${encodeURIComponent(bArr[0].slug)}`);
  console.log(`### brand-by-slug ${bArr[0].slug} -> id=${single.data?.id} name=${single.data?.name ?? '?'}`);
}

// banners / sliders
for (const fam of ["banners", "sliders"]) {
  const r = await j(`${G}/${fam}`);
  const arr = Array.isArray(r.data) ? r.data : (r.data?.data ?? []);
  console.log(`### ${fam} n=${arr.length}`);
  for (const x of arr.slice(0, 10)) console.log(`  [${x.id}] ${x.slug ?? x.name ?? '?'}`);
}

// brand-products & nav
const bp = await j(`${G}/brands-products`);
console.log("### brands-products n=" + (Array.isArray(bp.data) ? bp.data.length : "?"));
const nav = await j(`${G}/nav-data`);
console.log("### nav-data n=" + (Array.isArray(nav.data) ? nav.data.length : "?"));

// coupons listing: only shape + counts, never print codes
const cp = await j(`${G}/coupons`);
const cpArr = Array.isArray(cp.data) ? cp.data : (cp.data?.data ?? []);
console.log(`### coupons n=${cpArr.length}; keys=${cpArr[0] ? Object.keys(cpArr[0]).join(",") : "-"}`);
const cpKeys = new Set(); for (const c of cpArr) for (const k of Object.keys(c)) cpKeys.add(k);
console.log(`### coupons union-keys=${[...cpKeys].join(",")}`);
console.log("### coupons codes-exposed:", [...cpKeys].some((k) => /code/i.test(k)));

// promotions
const pr = await j(`${G}/promotions`);
const prArr = Array.isArray(pr.data) ? pr.data : (pr.data?.data ?? []);
console.log("### promotions n=" + prArr.length);
