import { useState } from "react";
import { endpoints, type Wish } from "../api/client";
import { isAccount, isAdmin, useApp } from "../context";
import { useT } from "../i18n";
import { errorMessage } from "../lib/errors";
import { formatNumber, formatReleaseDate } from "../lib/format";
import { AdminEditDialog } from "./AdminEditDialog";
import { GameArt } from "./GameArt";
import { SourceBadge } from "./SourceMark";
import { StatusBadge } from "./StatusBadge";
import { TagsDialog } from "./TagsDialog";
import { Button, ConfirmDialog, Icon } from "./ui";

const MAX_CATEGORIES = 4;
const MAX_NAMES = 3;

/** Name of a provider wish's source, from its link (wishes do not carry the provider name). */
export function providerNameOf(wish: Wish): string | null {
  return wish.store_url && /^https:\/\/(www\.)?igdb\.com\//.test(wish.store_url) ? "IGDB" : null;
}

export function isClosed(wish: Wish): boolean {
  return wish.availability === "available" || wish.status === "rejected" || wish.status === "available";
}

export function WishCard({
  wish,
  onChange,
  onRemove,
}: {
  wish: Wish;
  onChange: (wish: Wish) => void;
  onRemove: (id: number) => void;
}) {
  const t = useT();
  const { me, toast, reloadWishes } = useApp();
  const admin = isAdmin(me);
  const account = isAccount(me);
  const [busy, setBusy] = useState(false);
  const [dialog, setDialog] = useState<"edit" | "tags" | "delete" | null>(null);
  const [deleting, setDeleting] = useState(false);
  const closed = isClosed(wish);
  const release = formatReleaseDate(wish.release_date);
  const names = wish.wished_by ?? [];
  const providerName = providerNameOf(wish) ?? t("wish.providerShort");

  async function toggleVote() {
    setBusy(true);
    try {
      if (wish.voted) {
        const res = await endpoints.unvote(wish.id);
        if (res.deleted || !res.wish) {
          toast(t("wish.removedLastVote", { title: wish.title }), "info", {
            label: t("common.undo"),
            onClick: () => void undoRemoval(),
          });
          onRemove(wish.id);
        } else {
          onChange(res.wish);
        }
      } else {
        onChange(await endpoints.vote(wish.id));
      }
    } catch (e) {
      toast(errorMessage(e), "error");
    } finally {
      setBusy(false);
    }
  }

  /** Re-creates a wish that the last vote removed (it was `new`, so nothing else is lost). */
  async function undoRemoval() {
    try {
      await endpoints.createWish(wish.source, wish.source_id);
      toast(t("wish.restored", { title: wish.title }), "success");
      reloadWishes();
    } catch (e) {
      toast(errorMessage(e), "error");
    }
  }

  async function remove() {
    setDeleting(true);
    try {
      await endpoints.deleteWish(wish.id);
      toast(t("admin.deleted", { title: wish.title }), "success");
      setDialog(null);
      onRemove(wish.id);
    } catch (e) {
      toast(errorMessage(e), "error");
    } finally {
      setDeleting(false);
    }
  }

  const voteHint = !me.can_wish ? t("wish.cannotVote") : closed ? t("wish.closed") : undefined;

  return (
    <article
      aria-labelledby={`wish-${wish.id}-title`}
      className="group flex flex-col overflow-hidden rounded-wv border border-border bg-surface shadow-wv-sm transition duration-150 hover:-translate-y-0.5 hover:border-accent/50 hover:shadow-wv"
    >
      <div className="relative">
        {wish.store_url ? (
          <a href={wish.store_url} target="_blank" rel="noopener noreferrer" tabIndex={-1} aria-hidden="true">
            <GameArt src={wish.image_url} title={wish.title} className="aspect-[460/215]" />
          </a>
        ) : (
          <GameArt src={wish.image_url} title={wish.title} className="aspect-[460/215]" />
        )}
        <div className="absolute top-2 left-2">
          <StatusBadge wish={wish} />
        </div>
        <SourceBadge source={wish.source} providerName={providerName} className="absolute right-2 bottom-2" />
      </div>

      <div className="flex flex-1 flex-col gap-3 p-4">
        <div className="flex flex-col gap-1">
          <h3 id={`wish-${wish.id}-title`} className="font-heading text-base leading-snug font-semibold">
            {wish.title}
          </h3>
          <p className="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-muted">
            {release && (
              <span>{wish.coming_soon ? t("wish.comingSoon", { date: release }) : t("wish.released", { date: release })}</span>
            )}
            {!release && wish.coming_soon && <span>{t("wish.comingSoonNoDate")}</span>}
            {wish.store_url && (
              <a
                href={wish.store_url}
                target="_blank"
                rel="noopener noreferrer"
                className="inline-flex items-center gap-1 underline-offset-2 hover:text-accent hover:underline"
              >
                {wish.source === "steam" ? t("wish.sourceSteam") : providerName}
                <Icon name="external" className="size-3" />
              </a>
            )}
          </p>
        </div>

        {wish.categories.length > 0 && (
          <ul className="flex flex-wrap gap-1" aria-label={t("wish.categories")}>
            {wish.categories.slice(0, MAX_CATEGORIES).map((c, i) => (
              <li key={`${i}-${c}`} className="rounded-full bg-surface-2 px-2 py-0.5 text-xs text-muted">
                {c}
              </li>
            ))}
            {wish.categories.length > MAX_CATEGORIES && (
              <li className="px-1 py-0.5 text-xs text-muted" title={wish.categories.slice(MAX_CATEGORIES).join(", ")}>
                +{wish.categories.length - MAX_CATEGORIES}
              </li>
            )}
          </ul>
        )}

        {account && wish.reason && (
          <p className="rounded-wv-sm border-l-2 border-accent/50 bg-surface-2 px-3 py-2 text-sm">
            <span className="sr-only">{t("wish.reason")}: </span>
            {wish.reason}
          </p>
        )}

        {admin && wish.note && (
          <p className="rounded-wv-sm border border-dashed border-warning/50 px-3 py-2 text-sm">
            <span className="font-semibold text-warning">{t("wish.note")}:</span> {wish.note}
          </p>
        )}

        {account && names.length > 0 && (
          <p className="text-xs text-muted">
            {t("wish.wishedBy", {
              names:
                names
                  .slice(0, MAX_NAMES)
                  .map((u) => u.username)
                  .join(", ") + (names.length > MAX_NAMES ? ` +${names.length - MAX_NAMES}` : ""),
            })}
          </p>
        )}

        <div className="mt-auto flex items-center justify-between gap-3 pt-1">
          <div className="flex flex-col">
            <span className="text-sm">
              <strong className="text-lg tabular-nums">{formatNumber(wish.votes)}</strong>{" "}
              <span className="text-muted">{t(wish.votes === 1 ? "wish.vote" : "wish.votes")}</span>
            </span>
            {admin && wish.anonymous_votes !== null && wish.anonymous_votes > 0 && (
              <span className="text-xs text-muted">{t("wish.anonymousVotes", { count: wish.anonymous_votes })}</span>
            )}
            {wish.created_by_me && <span className="text-xs text-accent">{t("wish.yourWish")}</span>}
          </div>
          {!closed && (
            <Button
              size="sm"
              variant={wish.voted ? "secondary" : "primary"}
              aria-pressed={wish.voted}
              busy={busy}
              disabled={!me.can_wish}
              title={voteHint ?? (wish.voted ? t("wish.unvoteHint") : undefined)}
              onClick={() => void toggleVote()}
              aria-label={`${t(wish.voted ? "wish.voted" : "wish.voteAction")}: ${wish.title}`}
            >
              <Icon name={wish.voted ? "check" : "up"} />
              {t(wish.voted ? "wish.voted" : "wish.voteAction")}
            </Button>
          )}
        </div>

        {admin && (
          <div className="flex items-center gap-1 border-t border-border pt-3">
            <Button size="sm" variant="ghost" onClick={() => setDialog("edit")} aria-label={`${t("admin.edit")}: ${wish.title}`}>
              <Icon name="edit" />
              {t("admin.edit")}
            </Button>
            {wish.source === "steam" && (
              <Button size="sm" variant="ghost" onClick={() => setDialog("tags")} aria-label={`${t("admin.tags")}: ${wish.title}`}>
                <Icon name="tag" />
                {t("admin.tags")}
              </Button>
            )}
            <Button
              size="sm"
              variant="ghost"
              className="ml-auto px-2 text-danger hover:bg-danger hover:text-accent-text"
              onClick={() => setDialog("delete")}
              title={t("admin.delete")}
              aria-label={`${t("admin.delete")}: ${wish.title}`}
            >
              <Icon name="trash" />
            </Button>
          </div>
        )}
      </div>

      {admin && (
        <>
          <AdminEditDialog
            wish={wish}
            open={dialog === "edit"}
            onClose={() => setDialog(null)}
            onSaved={(w) => {
              onChange(w);
              setDialog(null);
            }}
          />
          <TagsDialog wish={wish} open={dialog === "tags"} onClose={() => setDialog(null)} />
          <ConfirmDialog
            open={dialog === "delete"}
            title={t("admin.deleteTitle")}
            message={t("admin.deleteConfirm", { title: wish.title, votes: wish.votes })}
            confirmLabel={t("admin.delete")}
            busy={deleting}
            onConfirm={() => void remove()}
            onClose={() => setDialog(null)}
          />
        </>
      )}
    </article>
  );
}
