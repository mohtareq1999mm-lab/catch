/** Coupons guest-listing audit: per-item policy fields; never prints the code itself. */
const BASE = "http://localhost:8000";
const res = await fetch(BASE + "/api/v1/general/coupons?limit=50", { headers: { Accept: "application/json" } });
const j = await res.json();
const arr = Array.isArray(j.data) ? j.data : (j.data?.data ?? []);
console.log("n=" + arr.length);
const tally = {};
for (const c of arr) {
  const k = `vis=${c.visibility} claim=${c.requires_claim} elig=${c.eligible} code=${c.code != null}`;
  tally[k] = (tally[k] || 0) + 1;
}
console.log(tally);
// IMPORTANT: never dump code values
for (const c of arr.slice(0, 3)) console.log(`id=${c.id} name=${c.name} slug=${c.slug} vis=${c.visibility} codeLen=${c.code ? String(c.code).length : 0}`);
