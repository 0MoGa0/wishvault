import { act, fireEvent, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { admin, anonymous, jsonResponse, renderWithApp, user, wish } from "../test/util";
import { WishCard } from "./WishCard";

const fetchMock = vi.fn<typeof fetch>();
beforeEach(() => {
  fetchMock.mockReset();
  vi.stubGlobal("fetch", fetchMock);
});

describe("WishCard", () => {
  it("shows anonymous callers only the availability", () => {
    renderWithApp(
      <WishCard
        wish={wish({ status: null, reason: null, note: null, wished_by: null, anonymous_votes: null })}
        onChange={vi.fn()}
        onRemove={vi.fn()}
      />,
      { me: anonymous },
    );
    expect(screen.getByText("Not available yet")).toBeTruthy();
    expect(screen.queryByText("Queued")).toBeNull();
    expect(screen.queryByText(/Wished by/)).toBeNull();
    expect(screen.queryByRole("button", { name: /Edit/ })).toBeNull();
    expect(screen.getByRole("link", { name: /Steam store/ }).getAttribute("href")).toBe(
      "https://store.example.com/app/620",
    );
  });

  it("shows accounts status, reason and who wished, but no admin data", () => {
    renderWithApp(<WishCard wish={wish({ note: null, anonymous_votes: null })} onChange={vi.fn()} onRemove={vi.fn()} />, {
      me: user,
    });
    expect(screen.getByText("Queued")).toBeTruthy();
    expect(screen.getByText("Planned for the next LAN")).toBeTruthy();
    expect(screen.getByText("Wished by alice")).toBeTruthy();
    expect(screen.queryByText(/anonymous/)).toBeNull();
    expect(screen.queryByRole("button", { name: /Delete/ })).toBeNull();
  });

  it("gives admins note, anonymous votes and actions", async () => {
    const onRemove = vi.fn();
    renderWithApp(<WishCard wish={wish()} onChange={vi.fn()} onRemove={onRemove} />, { me: admin });
    expect(screen.getByText("Buy key")).toBeTruthy();
    expect(screen.getByText("of these anonymous: 1")).toBeTruthy();
    expect(screen.getByRole("button", { name: "Steam tags: Portal 2" })).toBeTruthy();

    fireEvent.click(screen.getByRole("button", { name: "Delete: Portal 2" }));
    expect(screen.getByText(/will be deleted with all its votes \(3\)/)).toBeTruthy();
    fetchMock.mockResolvedValueOnce(new Response(null, { status: 204 }));
    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "Delete" }));
    });
    expect(fetchMock.mock.calls[0]![0]).toBe("/api/v1/wishes/7");
    expect(fetchMock.mock.calls[0]![1]?.method).toBe("DELETE");
    expect(onRemove).toHaveBeenCalledWith(7);
  });

  it("votes and withdraws the last vote", async () => {
    const onChange = vi.fn();
    const onRemove = vi.fn();
    const { rerender } = renderWithApp(<WishCard wish={wish()} onChange={onChange} onRemove={onRemove} />, { me: user });
    fetchMock.mockResolvedValueOnce(jsonResponse(wish({ voted: true, votes: 4 })));
    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "Vote: Portal 2" }));
    });
    expect(fetchMock.mock.calls[0]![1]?.method).toBe("PUT");
    expect(onChange).toHaveBeenCalledWith(expect.objectContaining({ votes: 4 }));
    rerender(<div />);

    renderWithApp(<WishCard wish={wish({ voted: true, status: "new" })} onChange={onChange} onRemove={onRemove} />, {
      me: user,
    });
    fetchMock.mockResolvedValueOnce(jsonResponse({ wish: null, deleted: true }));
    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "Voted: Portal 2" }));
    });
    expect(fetchMock.mock.calls[1]![1]?.method).toBe("DELETE");
    expect(onRemove).toHaveBeenCalledWith(7);
  });

  it("has no vote button on closed wishes", () => {
    renderWithApp(
      <WishCard wish={wish({ status: "available", availability: "available" })} onChange={vi.fn()} onRemove={vi.fn()} />,
      { me: user },
    );
    expect(screen.queryByRole("button", { name: /Vote/ })).toBeNull();
  });
});
