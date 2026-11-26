import { createContext, useContext } from "react";
import type { Info, Me } from "./api/client";

export type ToastKind = "info" | "success" | "error";

/** Optional button in a toast (e.g. "Undo"). */
export interface ToastAction {
  label: string;
  onClick: () => void;
}

export interface AppState {
  info: Info;
  me: Me;
  setMe: (me: Me) => void;
  toast: (message: string, kind?: ToastKind, action?: ToastAction) => void;
  /** Ask the wish list to reload (after a new wish, login, logout …). */
  reloadWishes: () => void;
  wishesVersion: number;
}

export const AppContext = createContext<AppState | null>(null);

export function useApp(): AppState {
  const ctx = useContext(AppContext);
  if (!ctx) throw new Error("AppContext missing");
  return ctx;
}

export const isAccount = (me: Me) => me.kind === "user" && me.user !== null;
export const isAdmin = (me: Me) => isAccount(me) && me.user?.is_admin === true;

/** Used when /me fails: read-only guest. */
export function guestMe(info: Info | null): Me {
  return { kind: "anonymous", user: null, can_wish: info?.features.anonymous ?? false, unread_events: 0 };
}
