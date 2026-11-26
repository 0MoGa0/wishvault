import type { Status, Wish } from "../api/client";
import { useT, type MessageKey } from "../i18n";
import { Badge, type Tone } from "./ui";

export const statusTone: Record<Status, Tone> = {
  new: "neutral",
  queued: "accent",
  paused: "warning",
  rejected: "danger",
  available: "success",
};

export function statusKey(s: Status): MessageKey {
  return `status.${s}`;
}

/** Accounts see the status, anonymous callers only available / not available. */
export function StatusBadge({ wish }: { wish: Pick<Wish, "status" | "availability"> }) {
  const t = useT();
  if (wish.status) {
    return <Badge tone={statusTone[wish.status]}>{t(statusKey(wish.status))}</Badge>;
  }
  return wish.availability === "available" ? (
    <Badge tone="success">{t("availability.available")}</Badge>
  ) : (
    <Badge>{t("availability.not_available")}</Badge>
  );
}
