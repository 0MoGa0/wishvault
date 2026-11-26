import { beforeEach, describe, expect, it, vi } from "vitest";
import { jsonResponse } from "../test/util";
import { ApiError, endpoints } from "./client";
import { errorMessage } from "../lib/errors";

const fetchMock = vi.fn<typeof fetch>();

beforeEach(() => {
  fetchMock.mockReset();
  vi.stubGlobal("fetch", fetchMock);
});

async function caught(p: Promise<unknown>): Promise<ApiError> {
  try {
    await p;
  } catch (e) {
    if (e instanceof ApiError) return e;
  }
  throw new Error("no ApiError");
}

describe("api client", () => {
  it("sends the CSRF header and JSON body", async () => {
    fetchMock.mockResolvedValue(jsonResponse({ wish: {}, created: true }, 201));
    await endpoints.createWish("steam", "620");
    const [url, init] = fetchMock.mock.calls[0]!;
    expect(url).toBe("/api/v1/wishes");
    expect(init?.method).toBe("POST");
    expect(init?.credentials).toBe("same-origin");
    expect(init?.headers).toMatchObject({ "X-Requested-With": "WishVault", "Content-Type": "application/json" });
    expect(init?.body).toBe('{"source":"steam","source_id":"620"}');
  });

  it("builds list queries", async () => {
    fetchMock.mockResolvedValue(jsonResponse({ items: [], total: 0 }));
    await endpoints.wishes("available", "new", 24, 48);
    expect(fetchMock.mock.calls[0]![0]).toBe("/api/v1/wishes?filter=available&sort=new&limit=24&offset=48");
  });

  it("maps error bodies and Retry-After", async () => {
    fetchMock.mockResolvedValue(
      jsonResponse({ error: { code: "login_locked", message: "x" } }, 429, { "Retry-After": "120" }),
    );
    const err = await caught(endpoints.login("a", "b"));
    expect(err.code).toBe("login_locked");
    expect(err.retryAfter).toBe(120);
    expect(errorMessage(err)).toBe("Too many failed logins. Try again in 2 min.");
  });

  it("handles 501 without body, 204 and network errors", async () => {
    fetchMock.mockResolvedValueOnce(new Response("nope", { status: 501 }));
    expect((await caught(endpoints.me())).code).toBe("not_implemented");
    fetchMock.mockResolvedValueOnce(new Response(null, { status: 204 }));
    await expect(endpoints.logout()).resolves.toBeUndefined();
    fetchMock.mockRejectedValueOnce(new TypeError("Failed to fetch"));
    const err = await caught(endpoints.info());
    expect(err.code).toBe("network");
    expect(errorMessage(err)).toBe("No connection to the server.");
  });

  it("falls back to a generic message for unknown codes", () => {
    expect(errorMessage(new ApiError(500, "weird" as never))).toBe("Something went wrong. Please try again.");
    expect(errorMessage(new Error("x"))).toBe("Something went wrong. Please try again.");
  });
});
