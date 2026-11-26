import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import "./index.css";
import { endpoints, type Info } from "./api/client";
import { App } from "./App";
import { detectLocale, loadLocale, locales, storedLocale } from "./i18n";
import { applyCustomCss, applyTheme } from "./theme";

async function boot() {
  const raw = await endpoints.info().catch(() => null);
  const info: Info | null = raw?.service === "wishvault" ? raw : null;
  // Theme first, so the page never shows unstyled tokens.
  await applyTheme(info?.theme);
  applyCustomCss(info?.custom_css === true);
  await loadLocale(detectLocale(locales, storedLocale(), navigator.languages ?? [], info?.default_locale));
  createRoot(document.getElementById("root")!).render(
    <StrictMode>
      <App info={info} />
    </StrictMode>,
  );
}

void boot();
