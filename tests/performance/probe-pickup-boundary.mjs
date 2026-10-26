// Post-fix pickup boundary verification: show() 404 contract preserved.
const BASE = "http://127.0.0.1:8000";
const G = `${BASE}/api/v1/general/pickup-locations`;
for (const [label, path] of [
  ["valid id=1", `${G}/1`],
  ["inactive id=5", `${G}/5`],
  ["missing id=99999", `${G}/99999`],
]) {
  const t0 = performance.now();
  const r = await fetch(path, { headers: { Accept: "application/json" }, signal: AbortSignal.timeout(8000) });
  const body = await r.text();
  console.log(`${label.padEnd(18)} status=${r.status} bytes=${Buffer.byteLength(body)} ms=${(performance.now() - t0).toFixed(0)} body=${body.slice(0, 80)}`);
  await new Promise((q) => setTimeout(q, 1100));
}
