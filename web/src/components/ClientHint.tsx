import { useState } from "react";
import { useApp } from "../context";
import { useT } from "../i18n";
import { Button, Icon } from "./ui";

const KEY = "wishvault.clientHintHidden";

function hiddenFor(url: string): boolean {
  try {
    return localStorage.getItem(KEY) === url;
  } catch {
    return false;
  }
}

/** Optional box "Get the client" (CLIENT_DOWNLOAD_URL). */
export function ClientHint() {
  const t = useT();
  const { info } = useApp();
  const url = info.client_download_url;
  const [hidden, setHidden] = useState(() => (url ? hiddenFor(url) : true));
  if (!url || hidden) return null;

  function hide() {
    setHidden(true);
    try {
      localStorage.setItem(KEY, url!);
    } catch {
      // storage blocked: hidden for this page view only
    }
  }

  return (
    <aside
      aria-label={t("client.title")}
      className="flex flex-col gap-3 rounded-wv border border-accent/30 bg-accent/8 p-4 sm:flex-row sm:items-center"
    >
      <span className="grid size-10 shrink-0 place-items-center rounded-wv-sm bg-accent text-accent-text">
        <Icon name="gamepad" className="size-5" />
      </span>
      <div className="flex-1">
        <p className="font-semibold">{t("client.title")}</p>
        <p className="text-sm text-muted">{t("client.text")}</p>
      </div>
      <div className="flex gap-2">
        <a
          href={url}
          target="_blank"
          rel="noopener noreferrer"
          className="inline-flex h-10 items-center gap-1.5 rounded-wv-sm bg-accent px-4 text-sm font-medium text-accent-text hover:bg-accent/90"
        >
          <Icon name="download" />
          {t("client.download")}
        </a>
        <Button variant="ghost" onClick={hide}>
          {t("client.hide")}
        </Button>
      </div>
    </aside>
  );
}
