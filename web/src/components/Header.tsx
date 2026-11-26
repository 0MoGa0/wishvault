import { useState } from "react";
import { endpoints } from "../api/client";
import { guestMe, isAccount, useApp } from "../context";
import { localeName, locales, setLocale, useLocale, useT, type MessageKey } from "../i18n";
import { errorMessage } from "../lib/errors";
import { LoginDialog } from "./LoginDialog";
import { Badge, Button, Icon } from "./ui";

export function Logo({ className }: { className?: string }) {
  return (
    <svg viewBox="0 0 32 32" aria-hidden="true" className={className}>
      <rect x="2" y="2" width="28" height="28" rx="8" className="fill-accent" />
      <path
        d="M16 7.5l2.5 5.3 5.8.7-4.3 4 1.1 5.7L16 20.4l-5.1 2.8 1.1-5.7-4.3-4 5.8-.7z"
        className="fill-accent-text"
      />
    </svg>
  );
}

export function LanguageSwitcher() {
  const t = useT();
  const locale = useLocale();
  if (locales.length < 2) return null;
  return (
    <label className="relative flex items-center text-sm">
      <span className="sr-only">{t("header.language")}</span>
      <Icon name="globe" className="pointer-events-none absolute left-2.5 text-muted" />
      <select
        value={locale}
        onChange={(e) => void setLocale(e.target.value)}
        className="h-9 appearance-none rounded-wv-sm border border-border bg-surface pr-3 pl-8 text-sm text-text hover:bg-surface-2"
      >
        {locales.map((code) => (
          <option key={code} value={code}>
            {localeName(code)}
          </option>
        ))}
      </select>
    </label>
  );
}

export function Header() {
  const t = useT();
  const { info, me, setMe, toast, reloadWishes } = useApp();
  const [loginOpen, setLoginOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [logoFailed, setLogoFailed] = useState(false);
  const user = isAccount(me) ? me.user : null;

  async function logout() {
    setBusy(true);
    try {
      await endpoints.logout();
      setMe(await endpoints.me().catch(() => guestMe(info)));
      toast(t("auth.loggedOut"), "success");
      reloadWishes();
    } catch (e) {
      toast(errorMessage(e), "error");
    } finally {
      setBusy(false);
    }
  }

  return (
    <header className="sticky top-0 z-30 border-b border-border bg-surface/85 backdrop-blur">
      <div className="mx-auto flex max-w-6xl flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
        <a href="/" className="flex min-w-0 items-center gap-2.5 rounded-wv-sm">
          {info.logo_url && !logoFailed ? (
            <img
              src={info.logo_url}
              alt=""
              onError={() => setLogoFailed(true)}
              className="size-8 rounded-wv-sm object-contain"
            />
          ) : (
            <Logo className="size-8" />
          )}
          <span className="truncate font-heading text-lg font-bold tracking-tight">{info.name}</span>
        </a>
        <div className="ml-auto flex flex-wrap items-center gap-2">
          <LanguageSwitcher />
          {user ? (
            <>
              <span className="flex items-center gap-2 rounded-wv-sm px-2 text-sm">
                <Icon name="user" className="text-muted" />
                <span className="max-w-[10rem] truncate font-medium">{user.username}</span>
                <Badge tone={user.is_admin ? "accent" : "neutral"}>
                  {user.is_admin ? t("role.admin_badge") : t(`role.${Math.min(Math.max(user.role, 0), 3)}` as MessageKey)}
                </Badge>
              </span>
              <Button size="sm" variant="ghost" busy={busy} onClick={() => void logout()}>
                {t("auth.logout")}
              </Button>
            </>
          ) : (
            info.features.web_login && (
              <Button size="sm" variant="primary" onClick={() => setLoginOpen(true)}>
                {t("auth.login")}
              </Button>
            )
          )}
        </div>
      </div>
      <LoginDialog open={loginOpen} onClose={() => setLoginOpen(false)} />
    </header>
  );
}
