/** Cold-cache timing: clear app cache, then time ONE cold + ONE warm request each for nav-data and brands-products. Read-only API usage. */
const BASE = "http://localhost:8000";

async function timedOnce(path) {
  const t0 = performance.now();
  const res = await fetch(BASE + path, { headers: { Accept: "application/json" } });
  const body = await res.text();
  const ms = +(performance.now() - t0).toFixed(1);
  let n = null; try { n = JSON.parse(body); } catch {}
  return { status: res.status, ms, bytes: Buffer.byteLength(body), envelopeOk: !!n && n.success === true };
}

console.log("clearing app cache…");
const proc = await import("node:child_process");
proc.execSync("php artisan cache:clear", { cwd: "D:/work/meem", stdio: "inherit" });

for (const p of ["/api/v1/general/nav-data", "/api/v1/general/brands-products"]) {
  const cold = await timedOnce(p);
  const warm = await timedOnce(p);
  console.log(`${p}\n  cold: HTTP ${cold.status} ${cold.ms}ms bytes=${cold.bytes} envelope=${cold.envelopeOk}\n  warm: HTTP ${warm.status} ${warm.ms}ms bytes=${warm.bytes} envelope=${warm.envelopeOk}`);
}
