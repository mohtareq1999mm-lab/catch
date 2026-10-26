/** G2 live probe: content-pages + static-pages contract smoke (GET only). */
const B = "http://localhost:8000";
const grab = async (name, path) => {
  const r = await fetch(B + path, { headers: { Accept: "application/json" } });
  const body = await r.text();
  let j = null; try { j = JSON.parse(body); } catch {}
  const data = j?.data;
  const arr = Array.isArray(data) ? data : (Array.isArray(data?.data) ? data.data : null);
  console.log(`\n### ${name} -> ${r.status} bytes=${Buffer.byteLength(body)} success=${j?.success} status=${j?.status}`);
  if (arr) {
    console.log("  items:", arr.map((x) => `${x.id}:${x.slug}:active=${x.is_active}:sections=${x.sections?.length}`).join(" | ") || "(empty)");
  } else if (data) {
    console.log("  single:", data.id, data.slug, `active=${data.is_active}`, `sections=${data.sections?.length}`);
  } else {
    console.log("  body:", body.slice(0, 180));
  }
};

await grab("cp-index", "/api/v1/general/content-pages");
await grab("cp-show-home", "/api/v1/general/content-pages/home");
await grab("cp-show-404", "/api/v1/general/content-pages/nope-404");
await grab("sp-index", "/api/v1/general/static-pages");
await grab("sp-show-about", "/api/v1/general/static-pages/about-us");
await grab("sp-show-404", "/api/v1/general/static-pages/nope-404");
