// G4-F-03 effect check — cache MISS vs HIT latency on the newly-lazy endpoints.
// Protocol: (external `php artisan cache:clear` first) → phase A: 1 request per endpoint
// (all cache MISSES: query + resource build + cache write) → settle 500ms → phase B: 25
// timed requests per endpoint (all cache HITs: cache store read instead of DB query).
// Post-fix structural claim verified if hit p50 < miss time per endpoint (pre-fix both
// phases paid the same DB cost because queries ran before remember()).
const BASE = "http://127.0.0.1:8000";
const paths = [
  ["faqs", "/api/v1/general/faqs"],
  ["settings", "/api/v1/general/settings"],
  ["site-reviews", "/api/v1/general/site-reviews"],
  ["currencies", "/api/v1/general/currencies"],
  ["governorates", "/api/v1/general/governorates"],
];

const timedGet = async (p) => {
  const t0 = performance.now();
  const res = await fetch(BASE + p, { headers: { Accept: "application/json" }, signal: AbortSignal.timeout(8000) });
  const body = await res.text();
  const ms = +(performance.now() - t0).toFixed(1);
  let j = null; try { j = JSON.parse(body); } catch {}
  const okEnv = j && j.success === true && "data" in j;
  return { ms, status: res.status, okEnv };
};

const stats = (arr) => {
  const s = [...arr].sort((a, b) => a - b);
  return { p50: s[Math.floor(s.length / 2)], p95: s[Math.floor((s.length * 95) / 100)], max: s[s.length - 1] };
};

console.log("PHASE A — misses (1 fresh request each, immediately after cache:clear)");
const miss = {};
for (const [name, p] of paths) {
  const r = await timedGet(p);
  miss[name] = r.ms;
  console.log(`MISS ${name.padEnd(14)} ${r.ms}ms status=${r.status} env=${r.okEnv ? "ok" : "BAD"}`);
  await new Promise((q) => setTimeout(q, 300));
}

await new Promise((q) => setTimeout(q, 500));

console.log("\nPHASE B — hits (10 warm requests each, 1050ms pacing = limiter-safe ~0.95 rps)");
const hits = {};
for (const [name, p] of paths) {
  const samples = [];
  let envBad = 0;
  for (let i = 0; i < 10; i++) {
    const r = await timedGet(p);
    if (!r.okEnv || r.status !== 200) envBad++;
    samples.push(r.ms);
    await new Promise((q) => setTimeout(q, 1050));
  }
  hits[name] = stats(samples);
  console.log(`HIT  ${name.padEnd(14)} p50=${hits[name].p50} p95=${hits[name].p95} max=${hits[name].max} envBad=${envBad} (miss=${miss[name]}ms)`);
}

console.log("\nVERDICT (G4-F-03 structural effect):");
for (const [name] of paths) {
  const d = +(miss[name] - hits[name].p50).toFixed(1);
  console.log(`${name.padEnd(14)} miss=${miss[name]} hit_p50=${hits[name].p50} delta=${d}ms ⇒ cache-avoids-work=${hits[name].p50 < miss[name] ? "YES" : "NO(instrument floor)"}`);
}
