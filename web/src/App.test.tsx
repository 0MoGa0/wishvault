import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { App } from "./App";
import { admin, info, jsonResponse, wish } from "./test/util";

const fetchMock = vi.fn<typeof fetch>();
beforeEach(() => {
  fetchMock.mockReset();
  vi.stubGlobal("fetch", fetchMock);
});

function route(me: unknown, wishes: unknown) {
  fetchMock.mockImplementation(async (input) => {
    const url = String(input);
    if (url === "/api/v1/me") return jsonResponse(me);
    if (url.startsWith("/api/v1/wishes")) return wishes as Response;
    return jsonResponse({ error: { code: "not_found", message: "" } }, 404);
  });
}

describe("App", () => {
  it("shows the unavailable state without info", () => {
    render(<App info={null} />);
    expect(screen.getByText("The wishlist service is not reachable.")).toBeTruthy();
  });

  it("renders the page for an admin", async () => {
    route(admin, jsonResponse({ items: [wish()], total: 30 }));
    render(<App info={{ ...info, name: "LAN Wishes", client_download_url: "https://example.com/client" }} />);
    expect(await screen.findByText("Portal 2")).toBeTruthy();
    expect(document.title).toBe("LAN Wishes");
    expect(screen.getByText("root")).toBeTruthy();
    expect(screen.getByText("Service status")).toBeTruthy();
    expect(screen.getByRole("link", { name: /Download/ }).getAttribute("href")).toBe("https://example.com/client");
    expect(screen.getAllByRole("tab").map((t) => t.textContent)).toEqual(["Open", "Available", "My votes", "Rejected", "All"]);
    expect(screen.getByRole("button", { name: "Load more" })).toBeTruthy();
    expect(fetchMock).toHaveBeenCalledWith("/api/v1/wishes?filter=open&sort=votes&limit=24&offset=0", expect.anything());
  });

  it("shows not_implemented gracefully and falls back to a guest when /me fails", async () => {
    fetchMock.mockImplementation(async (input) =>
      String(input) === "/api/v1/me"
        ? new Response("", { status: 501 })
        : jsonResponse({ error: { code: "not_implemented", message: "" } }, 501),
    );
    render(<App info={info} />);
    expect(await screen.findByText("This function is not available on this server yet.")).toBeTruthy();
    expect(screen.getAllByRole("tab")).toHaveLength(3);
    expect(screen.getByRole("button", { name: "Log in" })).toBeTruthy();
  });
});
