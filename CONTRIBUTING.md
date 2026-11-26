# Contributing

Issues and pull requests are welcome. Please keep pull requests focused on one
change and make sure the checks pass (`composer lint stan test`,
`pnpm --dir web check:i18n build`).

## Translations

The web UI is translated with flat JSON files in `web/src/locales/`.
English (`en.json`) is the source and the fallback.

To add a language:

1. Copy `web/src/locales/en.json` to `web/src/locales/<code>.json`
   (`<code>` = two-letter ISO 639-1 code, e.g. `fr`).
2. Translate the values, keep the keys and placeholders like `{count}` as they are.
3. Run `pnpm --dir web check:i18n` – it reports missing, unknown or broken keys.
4. Open a pull request.

The new language appears in the language menu automatically.

## Themes

A theme is a single CSS file in `web/src/themes/` that only sets the `--wv-*`
tokens (see `default.css`). Components never use fixed colours or fonts.
