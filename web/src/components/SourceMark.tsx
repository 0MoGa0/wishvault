import { useId } from "react";
import type { Source } from "../api/client";
import { cx, Icon } from "./ui";

/** Simplified Steam mark (circle with piston), drawn for WishVault. */
export function SteamIcon({ className }: { className?: string }) {
  // Each instance needs its own mask id, a shared one may resolve to a hidden copy.
  const maskId = `wv-steam-${useId().replace(/[^a-zA-Z0-9_-]/g, "")}`;
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true" className={cx("size-4 shrink-0", className)}>
      <defs>
        <mask id={maskId}>
          <rect width="24" height="24" fill="white" />
          <circle cx="15.2" cy="9" r="3.6" fill="black" />
          <circle cx="15.2" cy="9" r="2" fill="white" />
          <circle cx="8.4" cy="15.6" r="2.4" fill="black" />
          <path d="M3 13.2l5.6 2.3" stroke="black" strokeWidth="1.6" />
          <path d="M8.9 15.1l4.6-4.4" stroke="black" strokeWidth="2.2" />
        </mask>
      </defs>
      <circle cx="12" cy="12" r="10.5" fill="currentColor" mask={`url(#${maskId})`} />
    </svg>
  );
}

/** Icon + name of a search source ("Steam" or the provider name, e.g. IGDB). */
export function SourceLabel({ source, providerName }: { source: Source; providerName: string }) {
  return source === "steam" ? (
    <span className="inline-flex items-center gap-1.5">
      <SteamIcon />
      Steam
    </span>
  ) : providerName.length <= 5 ? (
    // Short provider names (IGDB) as a compact wordmark
    <span className="rounded-[3px] border border-current px-1 text-xs leading-4 font-black tracking-tight">
      {providerName.toUpperCase()}
    </span>
  ) : (
    <span className="inline-flex items-center gap-1.5">
      <Icon name="globe" />
      {providerName}
    </span>
  );
}

/** Small source mark on top of a cover image. */
export function SourceBadge({ source, providerName, className }: { source: Source; providerName: string; className?: string }) {
  return (
    <span
      title={source === "steam" ? "Steam" : providerName}
      className={cx(
        "inline-flex items-center rounded-wv-sm bg-bg/85 px-1.5 py-0.5 text-[0.65rem] font-semibold text-text shadow-wv-sm backdrop-blur-sm",
        className,
      )}
    >
      {source === "steam" ? (
        <>
          <SteamIcon className="size-3.5" />
          <span className="sr-only">Steam</span>
        </>
      ) : (
        providerName.toUpperCase()
      )}
    </span>
  );
}

/** Segmented switch between Steam and the metadata provider. */
export function SourceSwitch({
  value,
  providerName,
  onChange,
  label,
  disabled = false,
}: {
  value: Source;
  providerName: string;
  onChange: (source: Source) => void;
  label: string;
  disabled?: boolean;
}) {
  const sources: Source[] = ["steam", "provider"];
  return (
    <div role="radiogroup" aria-label={label} className="inline-flex rounded-wv-sm border border-border bg-bg p-0.5">
      {sources.map((s) => (
        <button
          key={s}
          type="button"
          role="radio"
          aria-checked={value === s}
          disabled={disabled}
          onClick={() => value !== s && onChange(s)}
          className={cx(
            "h-8 rounded-[calc(var(--wv-radius-sm)-2px)] px-3 text-sm font-medium transition-colors disabled:opacity-55",
            value === s ? "bg-accent text-accent-text shadow-wv-sm" : "text-muted hover:bg-surface-2 hover:text-text",
          )}
        >
          <SourceLabel source={s} providerName={providerName} />
        </button>
      ))}
    </div>
  );
}
