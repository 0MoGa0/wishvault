// Minimal i18n: one flat JSON file per locale in src/locales, English is the
// fallback. Contributors add a locale by adding <code>.json (CONTRIBUTING.md).
import { useSyncExternalStore } from "react";
import en from "./locales/en.json";

type Messages = Record<string, string>;
export type MessageKey = keyof typeof en;
export type Translate = (key: MessageKey, vars?: Record<string, string | number>) => string;

const loaders = import.meta.glob<{ default: Messages }>(["./locales/*.json", "!./locales/en.json"]);
export const locales = ["en", ...Object.keys(loaders).map((p) => p.slice(10, -5))].sort();

const STORAGE_KEY = "wishvault.locale";

let current: Messages = en;
let currentCode = "en";
const listeners = new Set<() => void>();

export function isMessageKey(key: string): key is MessageKey {
  return key in en;
}

export async function loadLocale(code: string): Promise<string> {
  const loader = loaders[`./locales/${code}.json`];
  if (code === "en" || !loader) {
    current = en;
    currentCode = "en";
  } else {
    current = (await loader()).default;
    currentCode = code;
  }
  if (typeof document !== "undefined") document.documentElement.lang = currentCode;
  listeners.forEach((l) => l());
  return currentCode;
}

export function getLocale(): string {
  return currentCode;
}

/** Switch the language and remember the choice in this browser. */
export async function setLocale(code: string): Promise<string> {
  const loaded = await loadLocale(code);
  try {
    localStorage.setItem(STORAGE_KEY, loaded);
  } catch {
    // storage blocked: the choice only lasts for this page view
  }
  return loaded;
}

export function storedLocale(): string | null {
  try {
    return localStorage.getItem(STORAGE_KEY);
  } catch {
    return null;
  }
}

/** Saved choice → browser languages → server default → English. */
export function detectLocale(
  available: readonly string[],
  stored: string | null,
  browser: readonly string[],
  serverDefault: string | null | undefined,
): string {
  if (stored && available.includes(stored)) return stored;
  for (const lang of browser) {
    const code = lang.slice(0, 2).toLowerCase();
    if (available.includes(code)) return code;
  }
  if (serverDefault && available.includes(serverDefault)) return serverDefault;
  return "en";
}

export function t(key: MessageKey, vars: Record<string, string | number> = {}): string {
  const text = current[key] ?? (en as Messages)[key] ?? key;
  return text.replace(/\{(\w+)\}/g, (_, name: string) => String(vars[name] ?? `{${name}}`));
}

/** Language name in its own language, e.g. "Deutsch". */
export function localeName(code: string): string {
  try {
    const name = new Intl.DisplayNames([code], { type: "language" }).of(code);
    return name ? name.charAt(0).toLocaleUpperCase(code) + name.slice(1) : code;
  } catch {
    return code;
  }
}

function subscribe(listener: () => void) {
  listeners.add(listener);
  return () => listeners.delete(listener);
}

/** Current locale code; re-renders the component when the language changes. */
export function useLocale(): string {
  return useSyncExternalStore(subscribe, getLocale, getLocale);
}

/** `t` bound to a re-render on language change. */
export function useT(): Translate {
  useLocale();
  return t;
}
