import { appendFileSync, readFileSync } from "node:fs";

const rows = JSON.parse(readFileSync("D:/work/meem/tests/performance/results/g4-02.json", "utf8")).summary;
console.log("top byStatus:", JSON.stringify(rows.byStatus));
const sample = [];
for (const [name, s] of Object.entries(rows.byName)) {
  console.log(`${name.padEnd(14)} n=${s.n} byStatus=${JSON.stringify(s.byStatus)} bad=${JSON.stringify(s.bad?.slice(0, 2))}`);
}
// quick live check
for (const p of ["/api/v1/general/settings", "/api/v1/general/flash-sales"]) {
  const t0 = Date.now();
  try {
    const r = await fetch("http://127.0.0.1:8000" + p, { headers: { Accept: "application/json" }, signal: AbortSignal.timeout(9000) });
    await r.text();
    console.log(`LIVE ${p} ${r.status} in ${Date.now() - t0}ms`);
  } catch (e) {
    console.log(`LIVE ${p} FAILED in ${Date.now() - t0}ms: ${e?.name} ${e?.cause?.code ?? ""} ${e?.message?.slice(0, 80)}`);
  }
}
