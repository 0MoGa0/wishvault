import { useEffect, useState } from "react";
import { endpoints, type Wish } from "../api/client";
import { useT } from "../i18n";
import { errorMessage } from "../lib/errors";
import { Button, Dialog, Icon, Notice, Spinner } from "./ui";

async function copy(text: string): Promise<boolean> {
  try {
    await navigator.clipboard.writeText(text);
    return true;
  } catch {
    return false;
  }
}

export function TagsDialog({ wish, open, onClose }: { wish: Wish; open: boolean; onClose: () => void }) {
  const t = useT();
  const [tags, setTags] = useState<string[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [copied, setCopied] = useState<string | null>(null);

  useEffect(() => {
    if (!open) return;
    let active = true;
    setTags(null);
    setError(null);
    setCopied(null);
    endpoints.tags(wish.id).then(
      (res) => active && setTags(res.tags),
      (e: unknown) => active && setError(errorMessage(e)),
    );
    return () => {
      active = false;
    };
  }, [open, wish.id]);

  async function copyText(text: string, key: string) {
    setCopied((await copy(text)) ? key : "!");
  }

  return (
    <Dialog
      open={open}
      onClose={onClose}
      title={t("tags.title", { title: wish.title })}
      wide
      footer={
        <>
          {tags && tags.length > 0 && (
            <Button variant="primary" onClick={() => void copyText(tags.join(", "), "*")}>
              <Icon name="copy" />
              {copied === "*" ? t("tags.copied") : t("tags.copyAll")}
            </Button>
          )}
          <Button onClick={onClose}>{t("common.close")}</Button>
        </>
      }
    >
      {error && <Notice tone="danger">{error}</Notice>}
      {!error && !tags && <Spinner label={t("app.loading")} />}
      {tags && tags.length === 0 && <p className="text-sm text-muted">{t("tags.empty")}</p>}
      {tags && tags.length > 0 && (
        <>
          <p className="mb-3 text-sm text-muted">{t("tags.hint")}</p>
          <ul className="flex flex-wrap gap-2">
            {tags.map((tag, i) => (
              <li key={`${i}-${tag}`}>
                <button
                  type="button"
                  onClick={() => void copyText(tag, tag)}
                  aria-label={t("tags.copyOne", { tag })}
                  className="inline-flex items-center gap-1.5 rounded-full border border-border bg-surface-2 px-3 py-1 text-sm hover:border-accent hover:text-accent"
                >
                  {copied === tag ? <Icon name="check" className="size-3.5 text-success" /> : <Icon name="copy" className="size-3.5" />}
                  {tag}
                </button>
              </li>
            ))}
          </ul>
        </>
      )}
      {copied === "!" && (
        <Notice tone="danger" className="mt-3">
          {t("tags.copyFailed")}
        </Notice>
      )}
      <p aria-live="polite" className="sr-only">
        {copied && copied !== "!" ? t("tags.copied") : ""}
      </p>
    </Dialog>
  );
}
