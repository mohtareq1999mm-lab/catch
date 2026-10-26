/** Print sample bodies for shape documentation. */
const BASE = "http://localhost:8000";
const G = "/api/v1/general";
const probes = [
  ["404-cat", `${G}/categories/zwast-404`],
  ["404-brd", `${G}/brands/zwast-404`],
  ["401-mine", `${G}/coupons/mine`],
  ["200-cat", `${G}/categories`],
  ["200-tags", `${G}/tags`],
  ["500-tags-orderbad", `${G}/tags?order=abc%27--`],
  ["200-coupons-bad-bearer", `${G}/coupons`, { Authorization: "Bearer deadbeef-notreal", Accept: "application/json" }],
];
for (const [name, path, headers] of probes) {
  const r = await fetch(BASE + path, { headers: { Accept: "application/json", ...(headers || {}) } });
  const body = await r.text();
  console.log(`\n### ${name} status=${r.status}`);
  console.log(body.slice(0, 500));
}
