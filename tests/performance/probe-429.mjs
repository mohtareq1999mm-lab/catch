/** Blast to trigger the per-minute limiter, then capture the raw 429 shape (no secrets). */
const BASE = "http://localhost:8000";
let body429 = null;
const codes = [];
const jobs = [];
for (let i = 0; i < 80; i++) {
  jobs.push(new Promise((r) => setTimeout(async () => {
    try {
      const res = await fetch(BASE + "/api/v1/general/sliders", { headers: { Accept: "application/json" } });
      codes.push(res.status);
      if (res.status === 429 && !body429) {
        body429 = (await res.text()).slice(0, 300);
      }
    } catch {} finally { r(); }
  }, i * 15)));
}
await Promise.all(jobs);
const dist = codes.reduce((a, c) => (a[c] = (a[c] || 0) + 1, a), {});
console.log("dist:", JSON.stringify(dist));
if (body429) console.log("429 BODY:", body429);
