// G4b extended-boundary probe: uncovered public reads + payment POST boundaries (guest).
const BASE = "http://127.0.0.1:8000";
const G = `${BASE}/api/v1/general`;

const probe = async (label, path, init = {}) => {
  const t0 = performance.now();
  try {
    const res = await fetch(path, { headers: { Accept: "application/json", ...(init.body ? { "content-type": "application/json" } : {}) }, signal: AbortSignal.timeout(8000), ...init });
    const text = await res.text();
    let j = null; try { j = JSON.parse(text); } catch {}
    const data = j?.data;
    const kind = Array.isArray(data) ? `array(${data.length})` : data && typeof data === "object" ? "object" : typeof data;
    console.log(`${label.padEnd(34)} status=${res.status} kind=${kind} bytes=${Buffer.byteLength(text)} ${res.status !== 200 ? `body=${text.slice(0, 110)}` : ""}`);
    return { res, j };
  } catch (e) {
    console.log(`${label.padEnd(34)} NETFAIL ${e?.name} ${e?.cause?.code ?? ""}`);
    return null;
  }
};

// reads
const pg = await probe("payment-gateways", `${G}/payment-gateways`);
const of = await probe("order-flows/available", `${G}/order-flows/available`);
const ofl = await probe("order-flows/by-shipping-type/local", `${G}/order-flows/by-shipping-type/local`);
const ofi = await probe("order-flows/by-shipping-type/international", `${G}/order-flows/by-shipping-type/international`);
const ofx = await probe("order-flows/by-shipping-type/xyz", `${G}/order-flows/by-shipping-type/xyz`);

// safe spot-checks
if (pg) {
  const list = pg.j?.data;
  const kinds = Array.isArray(list) ? list.map((g) => g.key ?? g.name ?? "?") : Object.keys(list ?? {});
  const n = Array.isArray(list) ? list.length : kinds.length;
  console.log(`PG-CHECK kind=${Array.isArray(list) ? "array" : "object"} entries=${n} keys=${kinds.slice(0, 6).join(",")}`);
}
if (of) console.log(`OF-CHECK available=${JSON.stringify(of.j?.data)?.slice(0, 220)}`);
if (ofl) console.log(`OF-LOCAL=${JSON.stringify(ofl.j?.data)?.slice(0, 220)}`);
if (ofx) console.log(`OF-XYZ=${JSON.stringify(ofx.j)?.slice(0, 160)}`);

// payment POST boundaries (guest-safe proofs: reject before any processing; no writes)
await probe("track-order POST empty guest", `${G}/track-order`, { method: "POST", body: "{}" });
await probe("webhook stripe POST empty", `${G}/checkout/webhooks/stripe`, { method: "POST", body: "{}" });
await probe("callback GET", `${G}/checkout/callback`);
