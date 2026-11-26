// Minimal i18n: one flat JSON file per locale in src/locales, English is the
// fallback. Contributors add a locale by adding <code>.json (CONTRIBUTING.md).
import en from "./locales/en.json";

type Messages = Record<string, string>;
export type MessageKey = keyof typeof en;

const loaders = import.meta.glob<{ default: Messages }>(["./locales/*.json", "!./locales/en.json"]);
export const locales = ["en", ...Object.keys(loaders).map((p) => p.slice(10, -5))];

let current: Messages = en;

export async function loadLocale(code: string): Promise<string> {
  const loader = loaders[`./locales/${code}.json`];
  if (!loader) {
    current = en;
    return "en";
  }
  current = (await loader()).default;
  return code;
}

export function t(key: MessageKey, vars: Record<string, string | number> = {}): string {
  const text = current[key] ?? (en as Messages)[key] ?? key;
  return text.replace(/\{(\w+)\}/g, (_, name: string) => String(vars[name] ?? `{${name}}`));
}
