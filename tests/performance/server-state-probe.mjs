// Quick server-state probe: 3 rounds × 5 concurrent small GETs.
const BASE = "http://127.0.0.1:8000";
for (let round = 1; round <= 3; round++) {
  const t0 = performance.now();
  const res = await Promise.all(
    Array.from({ length: 5 }, () =>
      fetch(BASE + "/api/v1/general/settings", { headers: { Accept: "application/json" }, signal: AbortSignal.timeout(15000) })
        .then(async (r) => `${r.status}@${performance.now() - t0 | 0}ms`)
        .catch((e) => `ERR ${e?.name}@${performance.now() - t0 | 0}ms`)
    )
  );
  console.log(`round ${round}: ${res.join(" | ")} wall=${performance.now() - t0 | 0}ms`);
  await new Promise((q) => setTimeout(q, 800));
}
