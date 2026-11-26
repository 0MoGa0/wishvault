import type { components } from "./schema";

export type Info = components["schemas"]["Info"];
export type ApiErrorBody = components["schemas"]["Error"];

export class ApiError extends Error {
  constructor(
    readonly status: number,
    readonly code: string,
  ) {
    super(code);
  }
}

// Cookie-authenticated writes need this header (CSRF, see openapi.yaml).
export async function api<T>(path: string, init: RequestInit = {}): Promise<T> {
  const res = await fetch(`/api/v1${path}`, {
    credentials: "same-origin",
    ...init,
    headers: {
      Accept: "application/json",
      "X-Requested-With": "WishVault",
      ...(init.body ? { "Content-Type": "application/json" } : {}),
      ...init.headers,
    },
  });
  if (res.status === 204) return undefined as T;
  const data: unknown = await res.json().catch(() => null);
  if (!res.ok) {
    const code = (data as ApiErrorBody | null)?.error?.code ?? "internal_error";
    throw new ApiError(res.status, code);
  }
  return data as T;
}
