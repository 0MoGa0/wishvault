import { useState } from "react";
import { cx } from "./ui";

/** Header artwork (Steam) or cover (provider), with a neutral placeholder. */
export function GameArt({ src, title, className }: { src: string | null; title: string; className?: string }) {
  const [failed, setFailed] = useState(false);
  const initials = title
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w[0]?.toUpperCase())
    .join("");
  return (
    <div className={cx("relative overflow-hidden bg-surface-2", className)}>
      {src && !failed ? (
        <img
          src={src}
          alt=""
          loading="lazy"
          decoding="async"
          referrerPolicy="no-referrer"
          onError={() => setFailed(true)}
          className="absolute inset-0 size-full object-cover"
        />
      ) : (
        <div
          aria-hidden="true"
          className="absolute inset-0 grid place-items-center bg-linear-to-br from-accent/25 to-surface-2 font-heading text-2xl font-bold text-muted"
        >
          {initials}
        </div>
      )}
    </div>
  );
}
