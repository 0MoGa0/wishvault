import { render } from "@testing-library/react";
import type { ReactNode } from "react";
import { vi } from "vitest";
import type { Info, Me, Wish } from "../api/client";
import { AppContext, type AppState } from "../context";

export const info: Info = {
  service: "wishvault",
  name: "WishVault",
  version: "1.0.0",
  api_version: "1",
  logo_url: null,
  theme: "default",
  custom_css: false,
  default_locale: "en",
  locales: ["en", "de"],
  client_download_url: null,
  gamevault_url: "https://gamevault.example.com",
  features: { anonymous: true, provider_search: true, web_login: true },
  roles: { wish_min_role: 1, admin_min_role: 3 },
  sync_interval_seconds: 900,
  last_sync_at: null,
};

export const anonymous: Me = { kind: "anonymous", user: null, can_wish: true, unread_events: 0 };
export const user: Me = {
  kind: "user",
  user: { id: 2, username: "alice", role: 1, is_admin: false },
  can_wish: true,
  unread_events: 0,
};
export const admin: Me = {
  kind: "user",
  user: { id: 1, username: "root", role: 3, is_admin: true },
  can_wish: true,
  unread_events: 0,
};

export function wish(patch: Partial<Wish> = {}): Wish {
  return {
    id: 7,
    source: "steam",
    source_id: "620",
    title: "Portal 2",
    image_url: "https://cdn.example.com/620/header.jpg",
    release_date: "2011-04-18",
    coming_soon: false,
    store_url: "https://store.example.com/app/620",
    categories: ["Single-player", "Online Co-op"],
    availability: "not_available",
    status: "queued",
    reason: "Planned for the next LAN",
    note: "Buy key",
    votes: 3,
    anonymous_votes: 1,
    voted: false,
    created_by_me: false,
    wished_by: [{ id: 2, username: "alice" }],
    gamevault_game_id: null,
    created_at: "2026-11-01T10:00:00Z",
    updated_at: "2026-11-01T10:00:00Z",
    available_at: null,
    ...patch,
  };
}

export function renderWithApp(ui: ReactNode, state: Partial<AppState> = {}) {
  const value: AppState = {
    info,
    me: anonymous,
    setMe: vi.fn(),
    toast: vi.fn(),
    reloadWishes: vi.fn(),
    wishesVersion: 0,
    ...state,
  };
  return { ...render(<AppContext.Provider value={value}>{ui}</AppContext.Provider>), state: value };
}

export function jsonResponse(body: unknown, status = 200, headers: Record<string, string> = {}): Response {
  return new Response(status === 204 ? null : JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json", ...headers },
  });
}
