// Fails if a locale misses keys of en.json, has unknown keys, or placeholders differ.
import { readdirSync, readFileSync } from "node:fs";
import { join } from "node:path";

const dir = join(import.meta.dirname, "../src/locales");
const read = (f) => JSON.parse(readFileSync(join(dir, f), "utf8"));
const en = read("en.json");
const vars = (s) => [...s.matchAll(/\{(\w+)\}/g)].map((m) => m[1]).sort().join(",");
let errors = 0;

for (const file of readdirSync(dir).filter((f) => f.endsWith(".json") && f !== "en.json")) {
  const loc = read(file);
  for (const key of Object.keys(en)) {
    if (!(key in loc)) (errors++, console.error(`${file}: missing "${key}"`));
    else if (vars(en[key]) !== vars(loc[key])) (errors++, console.error(`${file}: placeholders differ in "${key}"`));
  }
  for (const key of Object.keys(loc)) {
    if (!(key in en)) (errors++, console.error(`${file}: unknown key "${key}"`));
  }
}
if (errors) process.exit(1);
console.log("i18n ok");
