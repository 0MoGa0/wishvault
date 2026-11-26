import { act, fireEvent, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { renderWithApp } from "../test/util";
import { SCROLL_TOP_THRESHOLD, ScrollTop } from "./ScrollTop";

describe("ScrollTop", () => {
  it("appears after scrolling down and scrolls back up", () => {
    const scrollTo = vi.fn();
    vi.stubGlobal("scrollTo", scrollTo);
    renderWithApp(<ScrollTop />);
    const button = screen.getByTitle("Back to top");
    expect(button.getAttribute("aria-hidden")).toBe("true");

    act(() => {
      Object.defineProperty(window, "scrollY", { value: SCROLL_TOP_THRESHOLD + 1, configurable: true });
      window.dispatchEvent(new Event("scroll"));
    });
    expect(button.getAttribute("aria-hidden")).toBe("false");
    fireEvent.click(button);
    expect(scrollTo).toHaveBeenCalledWith(expect.objectContaining({ top: 0 }));
    vi.unstubAllGlobals();
  });
});
