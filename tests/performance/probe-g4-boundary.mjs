// G4 boundary probe: POST site-reviews guest behavior + product-review comparison.
const BASE = "http://127.0.0.1:8000";

const probe = async (label, path, body) => {
  const t0 = performance.now();
  try {
    const res = await fetch(`${BASE}${path}`, {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify(body ?? {}),
      signal: AbortSignal.timeout(8000),
    });
    const text = await res.text();
    console.log(
      `${label}: status=${res.status} took=${(performance.now() - t0).toFixed(0)}ms body=${text.slice(0, 300)}`
    );
  } catch (e) {
    console.log(`${label}: ERROR ${e?.cause?.code ?? e?.name ?? e} body=${e?.cause?.message?.slice(0, 200) ?? ""}`);
  }
};

await probe("site-review-empty", "/api/v1/general/site-reviews", {});
await probe("site-review-valid-guest", "/api/v1/general/site-reviews", { rating: 4, comment: "probe-should-401-not-write" });
await probe("product-review-empty", "/api/v1/general/products/1/reviews", {});
await probe("currency-select-empty", "/api/v1/general/currencies/select", {});
await probe("currency-select-valid-guest", "/api/v1/general/currencies/select", { currency_code: "AED" });
await probe("fast-checkout-guest", "/api/v1/general/fast-shipping/checkout", {});
