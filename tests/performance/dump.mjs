import { readFileSync } from "node:fs";
const file = process.argv[2];
const s = readFileSync(file, "utf8");
console.log(JSON.stringify(s));
