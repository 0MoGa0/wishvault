import type { components } from "./schema";

type Schemas = components["schemas"];
export type Info = Schemas["Info"];
export type Me = Schemas["Me"];
export type User = Schemas["User"];
export type Wish = Schemas["Wish"];
export type WishList = Schemas["WishList"];
export type Status = Schemas["Status"];
export type Source = Schemas["Source"];
export type SearchResult = Schemas["SearchResult"];
export type SearchResponse = Schemas["SearchResponse"];
export type LoginResponse = Schemas["LoginResponse"];
export type CreateWishResponse = Schemas["CreateWishResponse"];
export type UpdateWishRequest = Schemas["UpdateWishRequest"];
export type UnvoteResponse = Schemas["UnvoteResponse"];
export type AdminStatus = Schemas["AdminStatus"];
export type ErrorCode = Schemas["ErrorCode"];
export type ApiErrorBody = Schemas["Error"];

export type WishFilter = "open" | "available" | "voted" | "rejected" | "all";
export type WishSort = "votes" | "new";

export const STATUSES = ["new", "queued", "paused", "rejected", "available"] as const satisfies readonly Status[];

/** Error codes the UI knows: the contract's ErrorCode plus client-side ones. */
export type ClientErrorCode = ErrorCode | "network";

export class ApiError extends Error {
  constructor(
    readonly status: number,
    readonly code: ClientErrorCode,
    /** Seconds from the Retry-After header (429). */
    readonly retryAfter: number | null = null,
    readonly details: Record<string, string> | null = null,
  ) {
    super(code);
    this.name = "ApiError";
  }
}

const BASE = "/api/v1";

// Cookie-authenticated writes need the X-Requested-With header (CSRF, see openapi.yaml).
export async function api<T>(path: string, init: RequestInit = {}): Promise<T> {
  let res: Response;
  try {
    res = await fetch(`${BASE}${path}`, {
      credentials: "same-origin",
      ...init,
      headers: {
        Accept: "application/json",
        "X-Requested-With": "WishVault",
        ...(init.body ? { "Content-Type": "application/json" } : {}),
        ...init.headers,
      },
    });
  } catch (e) {
    if (e instanceof DOMException && e.name === "AbortError") throw e;
    throw new ApiError(0, "network");
  }
  if (res.status === 204) return undefined as T;
  const data: unknown = await res.json().catch(() => null);
  if (!res.ok) {
    const body = (data as ApiErrorBody | null)?.error;
    const retry = Number.parseInt(res.headers.get("Retry-After") ?? "", 10);
    throw new ApiError(
      res.status,
      body?.code ?? (res.status === 501 ? "not_implemented" : "internal_error"),
      Number.isFinite(retry) && retry >= 0 ? retry : null,
      body?.details ?? null,
    );
  }
  return data as T;
}

const send = (method: string, body?: unknown): RequestInit => ({
  method,
  ...(body === undefined ? {} : { body: JSON.stringify(body) }),
});

export const endpoints = {
  info: () => api<Info>("/info"),
  me: () => api<Me>("/me"),
  login: (username: string, password: string) =>
    api<LoginResponse>("/auth/login", send("POST", { username, password })),
  logout: () => api<void>("/auth/logout", send("POST")),
  search: (q: string, source: Source, signal?: AbortSignal) =>
    api<SearchResponse>(`/search?${new URLSearchParams({ q, source })}`, signal ? { signal } : {}),
  wishes: (filter: WishFilter, sort: WishSort, limit: number, offset: number) =>
    api<WishList>(
      `/wishes?${new URLSearchParams({ filter, sort, limit: String(limit), offset: String(offset) })}`,
    ),
  createWish: (source: Source, sourceId: string) =>
    api<CreateWishResponse>("/wishes", send("POST", { source, source_id: sourceId })),
  vote: (id: number) => api<Wish>(`/wishes/${id}/vote`, send("PUT")),
  unvote: (id: number) => api<UnvoteResponse>(`/wishes/${id}/vote`, send("DELETE")),
  updateWish: (id: number, patch: UpdateWishRequest) => api<Wish>(`/wishes/${id}`, send("PATCH", patch)),
  deleteWish: (id: number) => api<void>(`/wishes/${id}`, send("DELETE")),
  tags: (id: number) => api<{ tags: string[] }>(`/wishes/${id}/tags`),
  adminStatus: () => api<AdminStatus>("/admin/status"),
  sync: () => api<{ requested_at: string }>("/admin/sync", send("POST")),
};
