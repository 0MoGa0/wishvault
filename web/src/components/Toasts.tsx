import { useT } from "../i18n";
import type { ToastAction, ToastKind } from "../context";
import { cx, Icon } from "./ui";

export interface ToastItem {
  id: number;
  message: string;
  kind: ToastKind;
  action?: ToastAction;
}

const kinds: Record<ToastKind, string> = {
  info: "border-border",
  success: "border-success/50",
  error: "border-danger/60",
};

export function Toasts({ items, onDismiss }: { items: ToastItem[]; onDismiss: (id: number) => void }) {
  const t = useT();
  return (
    <div
      aria-live="polite"
      className="pointer-events-none fixed inset-x-0 bottom-0 z-50 flex flex-col items-center gap-2 p-4 sm:items-end"
    >
      {items.map((item) => (
        <div
          key={item.id}
          role={item.kind === "error" ? "alert" : "status"}
          className={cx(
            "pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-wv border-l-4 bg-surface px-4 py-3 text-sm shadow-wv",
            "border border-border",
            kinds[item.kind],
          )}
        >
          <span
            aria-hidden="true"
            className={cx(
              "mt-1.5 size-2 shrink-0 rounded-full",
              item.kind === "error" ? "bg-danger" : item.kind === "success" ? "bg-success" : "bg-accent",
            )}
          />
          <p className="flex-1">{item.message}</p>
          {item.action && (
            <button
              type="button"
              onClick={() => {
                item.action?.onClick();
                onDismiss(item.id);
              }}
              className="-my-1 shrink-0 rounded-wv-sm px-2 py-1 font-semibold text-accent hover:bg-accent/15"
            >
              {item.action.label}
            </button>
          )}
          <button
            type="button"
            onClick={() => onDismiss(item.id)}
            aria-label={t("common.close")}
            className="-mr-1 grid size-6 place-items-center rounded-wv-sm text-muted hover:bg-surface-2 hover:text-text"
          >
            <Icon name="close" className="size-3.5" />
          </button>
        </div>
      ))}
    </div>
  );
}
