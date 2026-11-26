// Runtime theme selection (Info.theme). Every file in src/themes/ is built as
// its own stylesheet; only the active one is loaded. Removing a theme file
// removes the theme, nothing else needs to change.
const themeUrls = import.meta.glob<string>("./themes/*.css", { query: "?url", import: "default", eager: true });

export const DEFAULT_THEME = "default";

export const themes: Record<string, string> = Object.fromEntries(
  Object.entries(themeUrls).map(([path, url]) => [path.slice("./themes/".length, -".css".length), url]),
);

/** Name of the theme to use: the requested one if it exists, else the default. */
export function resolveTheme(name: string | null | undefined, available: Record<string, string> = themes): string {
  return name && Object.hasOwn(available, name) ? name : DEFAULT_THEME;
}

function addStylesheet(href: string, id: string): Promise<boolean> {
  document.getElementById(id)?.remove();
  const link = document.createElement("link");
  link.rel = "stylesheet";
  link.href = href;
  link.id = id;
  return new Promise((resolve) => {
    const timer = setTimeout(() => resolve(true), 3000);
    link.onload = () => (clearTimeout(timer), resolve(true));
    link.onerror = () => (clearTimeout(timer), resolve(false));
    document.head.appendChild(link);
  });
}

/** Load the theme stylesheet (falls back to the default theme on errors). */
export async function applyTheme(name: string | null | undefined): Promise<string> {
  const theme = resolveTheme(name);
  const href = themes[theme];
  if (href && (await addStylesheet(href, "wv-theme"))) {
    document.documentElement.dataset.theme = theme;
    return theme;
  }
  if (theme !== DEFAULT_THEME && themes[DEFAULT_THEME]) {
    await addStylesheet(themes[DEFAULT_THEME], "wv-theme");
  }
  document.documentElement.dataset.theme = DEFAULT_THEME;
  return DEFAULT_THEME;
}

/** Operator stylesheet from the data volume, loaded after the theme. */
export function applyCustomCss(enabled: boolean): void {
  if (enabled) void addStylesheet("/custom.css", "wv-custom");
}
