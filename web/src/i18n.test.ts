import { afterEach, describe, expect, it } from "vitest";
import en from "./locales/en.json";
import { detectLocale, loadLocale, locales, t } from "./i18n";
import { ERROR_CODES } from "./lib/errors";

describe("i18n", () => {
  afterEach(() => loadLocale("en"));

  it("finds the locales", () => {
    expect(locales).toContain("en");
    expect(locales).toContain("de");
  });

  it("detects the language: stored, browser, server default, English", () => {
    const av = ["de", "en"];
    expect(detectLocale(av, "de", ["en-US"], "en")).toBe("de");
    expect(detectLocale(av, "xx", ["fr-FR", "de-AT"], "en")).toBe("de");
    expect(detectLocale(av, null, ["fr"], "de")).toBe("de");
    expect(detectLocale(av, null, ["fr"], "garbage")).toBe("en");
  });

  it("replaces placeholders and falls back to English", async () => {
    expect(t("list.shown", { shown: 3, total: 9 })).toBe("3 of 9");
    expect(await loadLocale("de")).toBe("de");
    expect(t("list.shown", { shown: 3, total: 9 })).toBe("3 von 9");
    expect(document.documentElement.lang).toBe("de");
    expect(await loadLocale("xx")).toBe("en");
  });

  it("has a message for every error code", () => {
    for (const code of ERROR_CODES) expect(en).toHaveProperty(`error.${code}`);
  });
});
