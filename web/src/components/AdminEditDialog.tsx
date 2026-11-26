import { useEffect, useId, useState, type FormEvent } from "react";
import { endpoints, STATUSES, type Status, type UpdateWishRequest, type Wish } from "../api/client";
import { useApp } from "../context";
import { useT } from "../i18n";
import { errorMessage } from "../lib/errors";
import { Button, Dialog, Notice } from "./ui";
import { statusKey } from "./StatusBadge";

const field = "rounded-wv-sm border border-border bg-bg px-3 py-2 text-sm text-text";

/** Only the fields that changed; empty texts clear the value (null). */
export function buildPatch(wish: Wish, status: Status, reason: string, note: string): UpdateWishRequest {
  const patch: UpdateWishRequest = {};
  if (status !== wish.status) patch.status = status;
  const r = reason.trim() || null;
  const n = note.trim() || null;
  if (r !== (wish.reason ?? null)) patch.reason = r;
  if (n !== (wish.note ?? null)) patch.note = n;
  return patch;
}

export function AdminEditDialog({
  wish,
  open,
  onClose,
  onSaved,
}: {
  wish: Wish;
  open: boolean;
  onClose: () => void;
  onSaved: (wish: Wish) => void;
}) {
  const t = useT();
  const { toast } = useApp();
  const id = useId();
  const [status, setStatus] = useState<Status>(wish.status ?? "new");
  const [reason, setReason] = useState(wish.reason ?? "");
  const [note, setNote] = useState(wish.note ?? "");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!open) return;
    setStatus(wish.status ?? "new");
    setReason(wish.reason ?? "");
    setNote(wish.note ?? "");
    setError(null);
  }, [open, wish]);

  const patch = buildPatch(wish, status, reason, note);
  const dirty = Object.keys(patch).length > 0;

  async function save(e: FormEvent) {
    e.preventDefault();
    if (!dirty) return onClose();
    setBusy(true);
    setError(null);
    try {
      onSaved(await endpoints.updateWish(wish.id, patch));
      toast(t("admin.saved", { title: wish.title }), "success");
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Dialog open={open} onClose={onClose} title={t("admin.editTitle", { title: wish.title })} wide>
      <form onSubmit={(e) => void save(e)} className="flex flex-col gap-4">
        <div className="flex flex-col gap-1.5">
          <label htmlFor={`${id}-status`} className="text-sm font-medium">
            {t("admin.status")}
          </label>
          <select
            id={`${id}-status`}
            value={status}
            onChange={(e) => setStatus(e.target.value as Status)}
            className={`${field} h-10 py-0`}
          >
            {STATUSES.map((s) => (
              <option key={s} value={s}>
                {t(statusKey(s))}
              </option>
            ))}
          </select>
          <p className="text-xs text-muted">{t(`status.${status}.help`)}</p>
        </div>
        <div className="flex flex-col gap-1.5">
          <label htmlFor={`${id}-reason`} className="text-sm font-medium">
            {t("admin.reason")}
          </label>
          <textarea
            id={`${id}-reason`}
            rows={2}
            maxLength={500}
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            aria-describedby={`${id}-reason-help`}
            className={field}
          />
          <p id={`${id}-reason-help`} className="text-xs text-muted">
            {t("admin.reasonHelp")}
          </p>
        </div>
        <div className="flex flex-col gap-1.5">
          <label htmlFor={`${id}-note`} className="text-sm font-medium">
            {t("admin.note")}
          </label>
          <textarea
            id={`${id}-note`}
            rows={3}
            maxLength={2000}
            value={note}
            onChange={(e) => setNote(e.target.value)}
            aria-describedby={`${id}-note-help`}
            className={field}
          />
          <p id={`${id}-note-help`} className="text-xs text-muted">
            {t("admin.noteHelp")}
          </p>
        </div>
        {error && (
          <Notice tone="danger">
            <span role="alert">{error}</span>
          </Notice>
        )}
        <div className="flex justify-end gap-2">
          <Button onClick={onClose}>{t("common.cancel")}</Button>
          <Button type="submit" variant="primary" busy={busy} disabled={!dirty}>
            {t("common.save")}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}
