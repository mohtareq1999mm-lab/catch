/** G3-F-02 POC: does the products cache key ignore the channel header? */
const B = "http://localhost:8000";
const get = async (name, path, extra = {}) => {
  const r = await fetch(B + path, { headers: { Accept: "application/json", ...extra } });
  const j = await r.json().catch(() => null);
  const list = j?.data?.data;
  const ids = list?.map((p) => p.id).slice(0, 6);
  console.log(`${name} -> ${r.status} firstIds=${JSON.stringify(ids)}`);
  return ids;
};

// Step 1: fresh build under FAST-SHIPPING channel.
await get("FAST-build", "/api/v1/general/products?limit=6&order=asc", { "X-Channel": "fast-shipping" });
// Step 2: HOME request, SAME URL — served from step-1 cache entry? (bug: identical key)
await get("HOME-reuse", "/api/v1/general/products?limit=6&order=asc");
// Step 3: clear + fresh HOME build for comparison.
await fetch(B + "/__nonexistent-never", {}).catch(() => {});
