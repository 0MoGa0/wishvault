import { useEffect, useState } from "react";
import { useT } from "../i18n";
import { cx, Icon } from "./ui";

/** Shown once the page is scrolled down; brings the reader back to the top. */
export const SCROLL_TOP_THRESHOLD = 600;

export function ScrollTop() {
  const t = useT();
  const [visible, setVisible] = useState(false);

  useEffect(() => {
    const update = () => setVisible(window.scrollY > SCROLL_TOP_THRESHOLD);
    update();
    window.addEventListener("scroll", update, { passive: true });
    return () => window.removeEventListener("scroll", update);
  }, []);

  return (
    <button
      type="button"
      onClick={() => {
        const reduce = window.matchMedia?.("(prefers-reduced-motion: reduce)").matches;
        window.scrollTo({ top: 0, behavior: reduce ? "auto" : "smooth" });
      }}
      aria-label={t("common.scrollTop")}
      title={t("common.scrollTop")}
      tabIndex={visible ? 0 : -1}
      aria-hidden={!visible}
      className={cx(
        "fixed right-4 bottom-4 z-40 grid size-11 place-items-center rounded-full bg-accent text-accent-text shadow-wv transition duration-200 hover:bg-accent/80 sm:right-6 sm:bottom-6",
        visible ? "translate-y-0 opacity-100" : "pointer-events-none translate-y-4 opacity-0",
      )}
    >
      <Icon name="up" className="size-5" />
    </button>
  );
}
