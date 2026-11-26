import { readdirSync, readFileSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";
import { DEFAULT_THEME, resolveTheme, themes } from "./theme";

const src = import.meta.dirname;
const themeFiles = readdirSync(join(src, "themes")).filter((f) => f.endsWith(".css"));
const read = (...p: string[]) => readFileSync(join(src, ...p), "utf8");

function sourceFiles(dir: string): string[] {
  return readdirSync(dir, { withFileTypes: true }).flatMap((e) =>
    e.isDirectory() ? sourceFiles(join(dir, e.name)) : /\.(tsx?|css)$/.test(e.name) ? [join(dir, e.name)] : [],
  );
}

describe("themes", () => {
  it("are all bundled, default included", () => {
    expect(Object.keys(themes).sort()).toEqual(themeFiles.map((f) => f.replace(".css", "")).sort());
    expect(themes).toHaveProperty(DEFAULT_THEME);
  });

  it("fall back to the default theme", () => {
    expect(resolveTheme("gamevault", { default: "a", gamevault: "b" })).toBe("gamevault");
    expect(resolveTheme("gamevault", { default: "a" })).toBe("default");
    expect(resolveTheme("../../etc", themes)).toBe("default");
    expect(resolveTheme(null)).toBe("default");
    expect(resolveTheme("toString")).toBe("default");
  });

  it("set every token the UI uses, and nothing but tokens", () => {
    const used = new Set([...read("index.css").matchAll(/var\((--wv-[\w-]+)\)/g)].map((m) => m[1]));
    expect(used.size).toBeGreaterThan(10);
    for (const file of themeFiles) {
      const css = read("themes", file);
      const defined = new Set([...css.matchAll(/(--wv-[\w-]+)\s*:/g)].map((m) => m[1]));
      for (const token of used) expect(defined, `${file} misses ${token}`).toContain(token);
      // only :root blocks (optionally inside a colour-scheme media query)
      const selectors = [...css.replace(/\/\*[\s\S]*?\*\//g, "").matchAll(/([^{};]+)\{/g)].map((m) => m[1]!.trim());
      for (const sel of selectors) expect(sel).toMatch(/^(:root|@media \(prefers-color-scheme: (dark|light)\))$/);
    }
  });

  it("components use no fixed colours, fonts or style attributes", () => {
    const palette =
      /\b(?:bg|text|border|ring|from|to|via|fill|stroke|outline|shadow|decoration|divide)-(?:slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose|black|white)\b/;
    for (const file of sourceFiles(src)) {
      if (file.includes(`${join("src", "themes")}`) || file.endsWith(".test.ts") || file.endsWith(".test.tsx")) continue;
      if (file.includes(join("src", "test"))) continue;
      const code = read(file.slice(src.length + 1)).replace(/\/\*[\s\S]*?\*\/|\/\/.*$/gm, "");
      expect(code, file).not.toMatch(/#[0-9a-fA-F]{3,8}\b(?![\w-])/);
      expect(code, file).not.toMatch(/\brgba?\(|\bhsla?\(|oklch\(/);
      expect(code, file).not.toMatch(palette);
      expect(code, file).not.toMatch(/\bstyle=\{/);
      expect(code, file).not.toMatch(/font-family:(?!\s*var\()/);
    }
  });
});
