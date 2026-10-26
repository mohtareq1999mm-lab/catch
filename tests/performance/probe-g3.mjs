/** G3 live probe: products list + slug detail contract smoke (GET only). */
const B = "http://localhost:8000";
const grab = async (name, path) => {
  const r = await fetch(B + path, { headers: { Accept: "application/json" } });
  const body = await r.text();
  let j = null; try { j = JSON.parse(body); } catch {}
  const data = j?.data;
  const list = Array.isArray(data?.data) ? data.data : (Array.isArray(data) ? data : null);
  console.log(`\n### ${name} -> ${r.status} bytes=${Buffer.byteLength(body)} success=${j?.success} status=${j?.status}`);
  if (list) {
    const flat = list.map((p) => `id=${p.id} slug=${p.slug} price=${p.price} cur=${p.current_price} curc=${p.currency}`).slice(0, 3).join(" | ");
    console.log("  page:", `total=${data?.total} per=${data?.per_page} last=${data?.last_page} items=${list.length}`);
    console.log("  items:", flat || "(empty)");
    console.log("  filterKeys:", data?.filters ? Object.keys(data.filters).join(",") : "-", " cats:", Array.isArray(data?.categories) ? data.categories.length : "-");
  } else if (data && typeof data === "object") {
    console.log("  detail:", `id=${data.id} slug=${data.slug} price=${data.price} cur=${data.current_price} curc=${data.currency} related=${data.related_products?.length} reviews=${data.reviews?.length} variants=${data.variations?.length}`);
  } else {
    console.log("  body:", body.slice(0, 200));
  }
};

await grab("p-index-default", "/api/v1/general/products");
await grab("p-index-type-best", "/api/v1/general/products?type=best_product_sales");
await grab("p-index-type-newarrivals", "/api/v1/general/products?type=new_arrivals");
await grab("p-index-type-discounts", "/api/v1/general/products?type=all_product_discounts");
await grab("p-index-type-flashsale", "/api/v1/general/products?type=flash_sales_product");
await grab("p-index-type-unknown", "/api/v1/general/products?type=nonsense-type");
await grab("p-index-order-bad", "/api/v1/general/products?order=middle");
await grab("p-index-cursor-422", "/api/v1/general/products?pagination=cursor");
await grab("p-index-search", "/api/v1/general/products?search=maybelline");
await grab("p-index-search-nocache", "/api/v1/general/products?search=zzzznope");
await grab("p-index-unknown-param", "/api/v1/general/products?derp=1");
await grab("p-index-limit-hi", "/api/v1/general/products?limit=500");
await grab("p-index-limit-lo", "/api/v1/general/products?limit=-5");
await grab("p-index-price", "/api/v1/general/products?min_price=1&max_price=5");
await grab("p-index-paramsid", "/api/v1/general/products?productsId=1,2,3");
await grab("p-detail-slug", "/api/v1/general/products/maybelline-fit-me-foundation-vp4G9");
await grab("p-detail-numeric", "/api/v1/general/products/1");
await grab("p-detail-404", "/api/v1/general/products/g3-nope-404");

const rev = await fetch(B + "/api/v1/general/products/1/reviews", { method: "POST", headers: { Accept: "application/json" }, body: "{}" });
console.log(`\n### review-guest-post -> ${rev.status}`); console.log("  body:", (await rev.text()).slice(0, 140));
const rev2 = await fetch(B + "/api/v1/general/products/reviews/1", { method: "PUT", headers: { Accept: "application/json" }, body: "{}" });
console.log(`### review-guest-put -> ${rev2.status}`); console.log("  body:", (await rev2.text()).slice(0, 140));
