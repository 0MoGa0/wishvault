import { useCallback, useEffect, useState } from "react";
import { endpoints, type AdminStatus } from "../api/client";
import { useApp } from "../context";
import { useT } from "../i18n";
import { errorMessage } from "../lib/errors";
import { formatDateTime, formatNumber, formatRelative } from "../lib/format";
import { Badge, Button, Icon, Notice, Spinner } from "./ui";

function When({ iso }: { iso: string | null | undefined }) {
  const t = useT();
  if (!iso) return <span className="text-muted">{t("admin.never")}</span>;
  return <time dateTime={iso} title={formatDateTime(iso)}>{formatRelative(iso) ?? formatDateTime(iso)}</time>;
}

export function AdminStatusPanel() {
  const t = useT();
  const { toast } = useApp();
  const [open, setOpen] = useState(false);
  const [status, setStatus] = useState<AdminStatus | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [syncing, setSyncing] = useState(false);

  const refresh = useCallback(async () => {
    try {
      setStatus(await endpoints.adminStatus());
      setError(null);
    } catch (e) {
      setError(errorMessage(e));
    }
  }, []);

  useEffect(() => {
    if (open) void refresh();
  }, [open, refresh]);

  async function sync() {
    setSyncing(true);
    try {
      await endpoints.sync();
      toast(t("admin.syncRequested"), "success");
      setStatus((s) => s && { ...s, sync_pending: true });
      setTimeout(() => void refresh(), 12000);
    } catch (e) {
      toast(errorMessage(e), "error");
    } finally {
      setSyncing(false);
    }
  }

  const last = status?.last_sync ?? null;

  return (
    <details
      onToggle={(e) => setOpen(e.currentTarget.open)}
      className="group rounded-wv border border-border bg-surface shadow-wv-sm"
    >
      <summary className="flex cursor-pointer list-none items-center gap-2 rounded-wv px-4 py-3 font-medium select-none">
        <Icon name="status" className="text-accent" />
        {t("admin.panelTitle")}
        <span aria-hidden="true" className="ml-auto text-muted transition-transform group-open:rotate-180">
          ▾
        </span>
      </summary>
      <div className="border-t border-border px-4 py-4">
        {error && <Notice tone="danger">{error}</Notice>}
        {!error && !status && <Spinner label={t("app.loading")} />}
        {status && (
          <div className="flex flex-col gap-4">
            <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
              <div>
                <dt className="text-muted">{t("admin.worker")}</dt>
                <dd>
                  <When iso={status.worker_seen_at} />
                </dd>
              </div>
              <div>
                <dt className="text-muted">{t("admin.lastSync")}</dt>
                <dd className="flex flex-wrap items-center gap-2">
                  <When iso={last?.finished_at ?? last?.started_at} />
                  {last && (
                    <Badge tone={last.ok ? "success" : "danger"}>{last.ok ? t("admin.syncOk") : t("admin.syncFailed")}</Badge>
                  )}
                  {status.sync_pending && <Badge tone="warning">{t("admin.syncPending")}</Badge>}
                </dd>
                {last && (
                  <dd className="text-xs text-muted">
                    {t("admin.syncStats", { games: last.games, matched: last.matched })}
                  </dd>
                )}
                {last?.error && <dd className="mt-1 text-xs break-words text-danger">{last.error}</dd>}
              </div>
              <div>
                <dt className="text-muted">{t("admin.lastReleaseCheck")}</dt>
                <dd>
                  <When iso={status.last_release_check_at} />
                </dd>
              </div>
            </dl>
            <dl className="grid grid-cols-2 gap-2 sm:grid-cols-4">
              {(
                [
                  ["admin.countWishes", status.counts.wishes],
                  ["admin.countVotes", status.counts.votes],
                  ["admin.countAnonymous", status.counts.anonymous_voters],
                  ["admin.countUsers", status.counts.users],
                ] as const
              ).map(([key, value]) => (
                <div key={key} className="rounded-wv-sm bg-surface-2 px-3 py-2">
                  <dt className="text-xs text-muted">{t(key)}</dt>
                  <dd className="text-xl font-semibold tabular-nums">{formatNumber(value)}</dd>
                </div>
              ))}
            </dl>
          </div>
        )}
        <div className="mt-4 flex flex-wrap gap-2">
          <Button variant="primary" size="sm" busy={syncing} onClick={() => void sync()}>
            <Icon name="sync" />
            {t("admin.syncNow")}
          </Button>
          <Button size="sm" variant="ghost" onClick={() => void refresh()}>
            {t("common.refresh")}
          </Button>
        </div>
      </div>
    </details>
  );
}
