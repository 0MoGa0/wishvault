import { useLayoutEffect, type RefObject } from "react";

/** Smallest height a scroll area keeps, even on very short windows. */
export const FIT_MIN_PX = 256;
/** Gap kept between the bottom of the box and the window edge. */
export const FIT_MARGIN_PX = 16;

/**
 * Sets the max height of a scrollable list so that its whole box (list plus what
 * follows it inside `box`) fits into the window below the sticky header.
 * Measured live (resize + scroll), because the space above it varies.
 * `trigger`: falsy = no list shown; a new value re-measures (new results).
 */
export function useFitToViewport(
  list: RefObject<HTMLElement | null>,
  box: RefObject<HTMLElement | null>,
  trigger: unknown,
): void {

  useLayoutEffect(() => {
    if (!trigger) return;
    let frame = 0;
    const measure = () => {
      frame = 0;
      const el = list.current;
      const container = box.current;
      if (!el || !container) return;
      const header = parseFloat(getComputedStyle(document.documentElement).getPropertyValue("--wv-header-h")) || 0;
      const listRect = el.getBoundingClientRect();
      // space taken below the list inside the box (switch, padding)
      const below = container.getBoundingClientRect().bottom - listRect.bottom;
      const top = Math.max(listRect.top, header);
      const available = window.innerHeight - top - below - FIT_MARGIN_PX;
      // layout only (no theme value), set directly to avoid a style attribute
      el.style.maxHeight = `${Math.max(FIT_MIN_PX, Math.floor(available))}px`;
    };
    const schedule = () => {
      if (!frame) frame = requestAnimationFrame(measure);
    };
    measure();
    window.addEventListener("resize", schedule);
    window.addEventListener("scroll", schedule, { passive: true });
    return () => {
      if (frame) cancelAnimationFrame(frame);
      window.removeEventListener("resize", schedule);
      window.removeEventListener("scroll", schedule);
    };
  }, [list, box, trigger]);

}
