import { act, fireEvent, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { jsonResponse, renderWithApp, user } from "../test/util";
import { LoginDialog } from "./LoginDialog";
import { buildPatch } from "./AdminEditDialog";
import { wish } from "../test/util";

const fetchMock = vi.fn<typeof fetch>();
beforeEach(() => {
  fetchMock.mockReset();
  vi.stubGlobal("fetch", fetchMock);
});

async function submit() {
  fireEvent.change(screen.getByLabelText("Username"), { target: { value: "alice" } });
  fireEvent.change(screen.getByLabelText("Password"), { target: { value: "secret" } });
  await act(async () => {
    fireEvent.click(screen.getByRole("button", { name: "Log in" }));
  });
}

describe("LoginDialog", () => {
  it("logs in and reports moved votes", async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse({ me: user, moved: { votes: 2, wishes: 1 } }));
    const onClose = vi.fn();
    const { state } = renderWithApp(<LoginDialog open onClose={onClose} />);
    await submit();
    expect(fetchMock.mock.calls[0]![1]?.body).toBe('{"username":"alice","password":"secret"}');
    expect(state.setMe).toHaveBeenCalledWith(user);
    expect(state.toast).toHaveBeenCalledWith(
      "Logged in. Moved from this browser to your account: 2 vote(s), 1 wish(es).",
      "success",
    );
    expect(state.reloadWishes).toHaveBeenCalled();
    expect(onClose).toHaveBeenCalled();
  });

  it("shows translated errors with the waiting time", async () => {
    fetchMock.mockResolvedValueOnce(
      jsonResponse({ error: { code: "rate_limited", message: "slow down" } }, 429, { "Retry-After": "30" }),
    );
    const onClose = vi.fn();
    renderWithApp(<LoginDialog open onClose={onClose} />);
    await submit();
    expect(screen.getByRole("alert").textContent).toBe("Too many requests. Try again in 30 s.");
    expect(onClose).not.toHaveBeenCalled();

    fetchMock.mockResolvedValueOnce(jsonResponse({ error: { code: "login_failed", message: "x" } }, 401));
    await submit();
    expect(screen.getByRole("alert").textContent).toBe("Wrong username or password, or the account is disabled.");
  });
});

describe("AdminEditDialog patch", () => {
  it("sends only changed fields and clears empty texts", () => {
    const w = wish({ status: "new", reason: "old", note: null });
    expect(buildPatch(w, "new", "old", "")).toEqual({});
    expect(buildPatch(w, "rejected", "  ", "n")).toEqual({ status: "rejected", reason: null, note: "n" });
  });
});
