import { getLocale } from "../i18n";

export function formatNumber(n: number): string {
  return new Intl.NumberFormat(getLocale()).format(n);
}

export function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return "–";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return new Intl.DateTimeFormat(getLocale(), { dateStyle: "medium", timeStyle: "short" }).format(d);
}

/** Release dates come as free text from the source ("Q3 2027") or ISO dates. */
export function formatReleaseDate(value: string | null | undefined): string | null {
  if (!value) return null;
  if (!/^\d{4}-\d{2}-\d{2}/.test(value)) return value;
  const d = new Date(value.length === 10 ? `${value}T00:00:00Z` : value);
  if (Number.isNaN(d.getTime())) return value;
  return new Intl.DateTimeFormat(getLocale(), { dateStyle: "medium", timeZone: "UTC" }).format(d);
}

const units: [Intl.RelativeTimeFormatUnit, number][] = [
  ["year", 31536000],
  ["month", 2592000],
  ["week", 604800],
  ["day", 86400],
  ["hour", 3600],
  ["minute", 60],
  ["second", 1],
];

/** "5 minutes ago" in the current language. */
export function formatRelative(iso: string | null | undefined, now: number = Date.now()): string | null {
  if (!iso) return null;
  const time = new Date(iso).getTime();
  if (Number.isNaN(time)) return null;
  const diff = Math.round((time - now) / 1000);
  const rtf = new Intl.RelativeTimeFormat(getLocale(), { numeric: "auto" });
  for (const [unit, secs] of units) {
    if (Math.abs(diff) >= secs || unit === "second") return rtf.format(Math.round(diff / secs), unit);
  }
  return null;
}
