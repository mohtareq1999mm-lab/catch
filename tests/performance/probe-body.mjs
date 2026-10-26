/** Print full message body of a single endpoint probe. Usage: node probe-body.mjs */
const BASE = "http://localhost:8000";
const res = await fetch(BASE + "/api/v1/general/nav-data", { headers: { Accept: "application/json" } });
const body = await res.text();
console.log("STATUS", res.status);
console.log("HEADERS", JSON.stringify(Object.fromEntries(res.headers)));
console.log("BODY", body.slice(0, 800));
