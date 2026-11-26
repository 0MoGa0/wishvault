import { act, fireEvent, screen } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import type { SearchResponse, SearchResult } from "../api/client";
import { anonymous, info, jsonResponse, renderWithApp } from "../test/util";
import { SEARCH_DEBOUNCE_MS, SearchPanel } from "./SearchPanel";

const fetchMock = vi.fn<typeof fetch>();

function result(patch: Partial<SearchResult> = {}): SearchResult {
  return {
    source: "steam",
    source_id: "620",
    title: "Portal 2",
    image_url: null,
    release_date: "2011-04-18",
    coming_soon: false,
    store_url: null,
    wish_id: null,
    voted: false,
    gamevault_game_id: null,
    ...patch,
  };
}

const response = (results: SearchResult[], patch: Partial<SearchResponse> = {}): SearchResponse => ({
  source: "steam",
  fallback: false,
  provider: null,
  results,
  ...patch,
});

async function type(value: string) {
  fireEvent.change(screen.getByRole("searchbox"), { target: { value } });
}

async function settle(ms = SEARCH_DEBOUNCE_MS) {
  await act(async () => {
    await vi.advanceTimersByTimeAsync(ms);
  });
}

beforeEach(() => {
  vi.useFakeTimers({ shouldAdvanceTime: true });
  fetchMock.mockReset();
  vi.stubGlobal("fetch", fetchMock);
});
afterEach(() => vi.useRealTimers());

describe("SearchPanel", () => {
  it("waits for 2 characters and debounces", async () => {
    renderWithApp(<SearchPanel />);
    await type("p");
    await settle();
    expect(fetchMock).not.toHaveBeenCalled();
    expect(screen.getByText("Type at least 2 characters.")).toBeTruthy();

    fetchMock.mockResolvedValue(jsonResponse(response([result()])));
    await type("po");
    await type("por");
    await settle(SEARCH_DEBOUNCE_MS - 150);
    expect(fetchMock).not.toHaveBeenCalled();
    await settle(150);
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(fetchMock.mock.calls[0]![0]).toBe("/api/v1/search?q=por&source=steam");
    expect(await screen.findByText("Portal 2")).toBeTruthy();
  });

  it("ignores stale responses", async () => {
    let resolveFirst: (r: Response) => void = () => {};
    fetchMock
      .mockImplementationOnce(() => new Promise((r) => (resolveFirst = r)))
      .mockResolvedValueOnce(jsonResponse(response([result({ title: "Second" })])));
    renderWithApp(<SearchPanel />);
    await type("first");
    await settle();
    await type("second");
    await settle();
    expect(await screen.findByText("Second")).toBeTruthy();
    await act(async () => resolveFirst(jsonResponse(response([result({ title: "First" })]))));
    expect(screen.queryByText("First")).toBeNull();
    expect(screen.getByText("Second")).toBeTruthy();
  });

  it("shows markers, the fallback note and wishes", async () => {
    fetchMock.mockResolvedValueOnce(
      jsonResponse(
        response(
          [
            result({ source: "provider", source_id: "1", title: "In Library", gamevault_game_id: 5 }),
            result({ source: "provider", source_id: "2", title: "Voted Game", wish_id: 3, voted: true }),
            result({ source: "provider", source_id: "3", title: "New Game" }),
          ],
          { source: "provider", fallback: true, provider: { slug: "igdb", name: "IGDB" } },
        ),
      ),
    );
    const { state } = renderWithApp(<SearchPanel />);
    await type("game");
    await settle();
    expect(await screen.findByText("Not found on Steam – results from IGDB.")).toBeTruthy();
    expect(screen.getByText("Already in GameVault")).toBeTruthy();
    expect(screen.getByText("Wished – you voted")).toBeTruthy();
    expect(screen.getByRole("radio", { name: "IGDB" }).getAttribute("aria-checked")).toBe("true");

    fetchMock.mockResolvedValueOnce(
      jsonResponse({ created: true, wish: { id: 9, title: "New Game" } }, 201),
    );
    fireEvent.click(screen.getByRole("button", { name: "Wish: New Game" }));
    await settle(0);
    const [url, init] = fetchMock.mock.calls[1]!;
    expect(url).toBe("/api/v1/wishes");
    expect(init?.body).toBe('{"source":"provider","source_id":"3"}');
    expect(state.toast).toHaveBeenCalledWith("Wished: New Game", "success");
    expect(state.reloadWishes).toHaveBeenCalled();
    // the search is cleared after wishing
    expect((screen.getByRole("searchbox") as HTMLInputElement).value).toBe("");
    expect(screen.queryByText("New Game")).toBeNull();
  });

  it("switches back to the empty Steam result after a fallback without a new request", async () => {
    fetchMock.mockResolvedValueOnce(
      jsonResponse(
        response([result({ source: "provider", source_id: "3", title: "Obscure" })], {
          source: "provider",
          fallback: true,
          provider: { slug: "igdb", name: "IGDB" },
        }),
      ),
    );
    renderWithApp(<SearchPanel />);
    await type("obscure");
    await settle();
    expect(await screen.findByText("Obscure")).toBeTruthy();
    fireEvent.click(screen.getByRole("radio", { name: "Steam" }));
    expect(screen.getByText("No games found on Steam.")).toBeTruthy();
    fireEvent.click(screen.getByRole("radio", { name: "IGDB" }));
    expect(screen.getByText("Obscure")).toBeTruthy();
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it("names the provider from /info before the first provider search", async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse(response([result()])));
    renderWithApp(<SearchPanel />, { info: { ...info, provider: { slug: "igdb", name: "IGDB" } } });
    await type("portal");
    await settle();
    expect(await screen.findByRole("radio", { name: "IGDB" })).toBeTruthy();
  });

  it("clears the search with the clear button", async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse(response([result()])));
    renderWithApp(<SearchPanel />);
    await type("portal");
    await settle();
    expect(await screen.findByText("Portal 2")).toBeTruthy();
    fireEvent.click(screen.getByRole("button", { name: "Clear search" }));
    expect((screen.getByRole("searchbox") as HTMLInputElement).value).toBe("");
    expect(screen.queryByText("Portal 2")).toBeNull();
  });

  it("offers the provider search", async () => {
    fetchMock
      .mockResolvedValueOnce(jsonResponse(response([result()])))
      .mockResolvedValueOnce(
        jsonResponse(response([], { source: "provider", provider: { slug: "igdb", name: "IGDB" } })),
      );
    renderWithApp(<SearchPanel />);
    await type("portal");
    await settle();
    expect(await screen.findByText("Portal 2")).toBeTruthy();
    fireEvent.click(screen.getByRole("radio", { name: "Provider" }));
    // skeleton while the provider loads
    expect(screen.getByText("Searching…")).toBeTruthy();
    await settle();
    expect(fetchMock.mock.calls[1]![0]).toBe("/api/v1/search?q=portal&source=provider");
    expect(await screen.findByText("Results from IGDB.")).toBeTruthy();

    // and back to Steam
    fetchMock.mockResolvedValueOnce(jsonResponse(response([result()])));
    fireEvent.click(screen.getByRole("radio", { name: "Steam" }));
    await settle();
    expect(fetchMock.mock.calls[2]![0]).toBe("/api/v1/search?q=portal&source=steam");
    expect(await screen.findByText("Portal 2")).toBeTruthy();
  });

  it("hides the provider button when the feature is off and blocks guests without rights", async () => {
    fetchMock.mockResolvedValue(jsonResponse(response([result()])));
    renderWithApp(<SearchPanel />, {
      info: { ...info, features: { ...info.features, provider_search: false } },
      me: { ...anonymous, can_wish: false },
    });
    expect(screen.getByText("Log in to wish for games and vote.")).toBeTruthy();
    await type("portal");
    await settle();
    expect(await screen.findByText("Portal 2")).toBeTruthy();
    expect(screen.queryByRole("radiogroup")).toBeNull();
    expect((screen.getByRole("button", { name: "Wish: Portal 2" }) as HTMLButtonElement).disabled).toBe(true);
  });
});
