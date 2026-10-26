/**
 * Single-request connectivity + status probe for the /api/v1/general routes.
 * Usage: node tests/performance/probe.mjs [base-url]
 */
const BASE = process.argv[2] ?? "http://localhost:8000";
const PATHS = [
  "/api/v1/general/nav-data",
  "/api/v1/general/categories",
  "/api/v1/general/brands",
  "/api/v1/general/banners",
  "/api/v1/general/sliders",
  "/api/v1/general/tags",
  "/api/v1/general/promotions",
  "/api/v1/general/coupons",
  "/api/v1/general/coupons/mine", // should be 401 (guest-protected)
];

for (const path of PATHS) {
  const t0 = performance.now();
  try {
    const res = await fetch(BASE + path, { headers: { Accept: "application/json" } });
    const body = await res.text();
    let shape = "?";
    try {
      const j = JSON.parse(body);
      shape = Object.keys(j).join(",");
    } catch {
      shape = `non-json(${body.slice(0, 60).replace(/\s+/g, " ")})`;
    }
    console.log(`${res.status}  ${String(performance.now() - t0).padStart(6)}ms  ${path}  keys=[${shape}]`);
  } catch (e) {
    console.log(`ERR         ${path}  ${e.cause?.code ?? e.message}`);
  }
}
