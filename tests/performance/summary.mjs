/** Print concise top-level stats from one or more stage result JSON files. */
import { readFileSync, readdirSync } from "node:fs";
import { join } from "node:path";

const dir = "D:/work/meem/tests/performance/results";
const files = process.argv.slice(2).length ? process.argv.slice(2)
  : readdirSync(dir).filter((f) => f.endsWith(".json")).sort();
for (const f of files) {
  const p = join(dir, f);
  const j = JSON.parse(readFileSync(p, "utf8"));
  const s = j.summary ?? {};
  console.log(`${f}`);
  console.log(`  rate=${j.target_rate}/${j.actual_rate} rps wall=${j.wall_s}s aborted=${j.aborted} total=${s.total} byStatus=${JSON.stringify(s.byStatus)} envFail=${s.envelopes_failed}${s.client_errors ? ` clientTimeouts=${s.client_errors}` : ""}`);
  if (s.lat_200) console.log(`  200-latency n=${s.lat_200.n} p50=${s.lat_200.p50} p90=${s.lat_200.p90} p95=${s.lat_200.p95} p99=${s.lat_200.p99} max=${s.lat_200.max}`);
  if (s.lat_429) console.log(`  429-latency n=${s.lat_429.n} p50=${s.lat_429.p50} p95=${s.lat_429.p95} max=${s.lat_429.max}`);
}
