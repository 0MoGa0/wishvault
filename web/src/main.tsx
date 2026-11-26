import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import "./themes/default.css";
import "./index.css";
import { App } from "./App";
import { loadLocale, locales } from "./i18n";

const preferred = navigator.languages
  .map((l) => l.slice(0, 2).toLowerCase())
  .find((l) => locales.includes(l));

loadLocale(preferred ?? "en").finally(() => {
  createRoot(document.getElementById("root")!).render(
    <StrictMode>
      <App />
    </StrictMode>,
  );
});
