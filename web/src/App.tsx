import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { endpoints, type Info, type Me } from "./api/client";
import { AdminStatusPanel } from "./components/AdminStatusPanel";
import { ClientHint } from "./components/ClientHint";
import { Header, LanguageSwitcher, Logo } from "./components/Header";
import { ScrollTop } from "./components/ScrollTop";
import { SearchPanel } from "./components/SearchPanel";
import { Toasts, type ToastItem } from "./components/Toasts";
import { WishList } from "./components/WishList";
import { Button, Spinner } from "./components/ui";
import { AppContext, guestMe, isAdmin, type AppState, type ToastAction, type ToastKind } from "./context";
import { useT } from "./i18n";
import { formatDateTime, formatRelative } from "./lib/format";

const SOURCE_URL = "https://github.com/0MoGa0/wishvault";
const TOAST_MS = 6000;
/** Toasts with an action (Undo) stay longer. */
const TOAST_ACTION_MS = 10000;

function Unavailable() {
  const t = useT();
  return (
    <main className="grid min-h-screen place-items-center p-6">
      <div className="flex max-w-sm flex-col items-center gap-4 text-center">
        <Logo className="size-12" />
        <h1 className="font-heading text-xl font-bold">{t("app.unavailable")}</h1>
        <p className="text-sm text-muted">{t("app.unavailableHint")}</p>
        <div className="flex gap-2">
          <Button variant="primary" onClick={() => window.location.reload()}>
            {t("common.retry")}
          </Button>
          <LanguageSwitcher />
        </div>
      </div>
    </main>
  );
}

function Footer({ info }: { info: Info }) {
  const t = useT();
  return (
    <footer className="mt-16 border-t border-border">
      <div className="mx-auto flex max-w-6xl flex-col gap-1 px-4 py-6 text-xs text-muted sm:flex-row sm:justify-between">
        <p>
          {info.last_sync_at ? (
            <>
              {t("footer.synced")}{" "}
              <time dateTime={info.last_sync_at} title={formatDateTime(info.last_sync_at)}>
                {formatRelative(info.last_sync_at)}
              </time>
            </>
          ) : (
            t("footer.notSynced")
          )}
        </p>
        <p>
          {t("footer.poweredBy", { version: info.version })} ·{" "}
          <a href={SOURCE_URL} target="_blank" rel="noopener noreferrer" className="underline-offset-2 hover:text-text hover:underline">
            {t("footer.source")}
          </a>
        </p>
      </div>
    </footer>
  );
}

export function App({ info }: { info: Info | null }) {
  const t = useT();
  const [me, setMe] = useState<Me | null>(null);
  const [toasts, setToasts] = useState<ToastItem[]>([]);
  const [wishesVersion, setWishesVersion] = useState(0);
  const nextToast = useRef(1);

  useEffect(() => {
    if (!info) return;
    document.title = info.name;
    endpoints.me().then(setMe, () => setMe(guestMe(info)));
  }, [info]);

  const dismiss = useCallback((id: number) => setToasts((list) => list.filter((x) => x.id !== id)), []);

  const toast = useCallback(
    (message: string, kind: ToastKind = "info", action?: ToastAction) => {
      const id = nextToast.current++;
      setToasts((list) => [...list.slice(-3), { id, message, kind, action }]);
      setTimeout(() => dismiss(id), action ? TOAST_ACTION_MS : TOAST_MS);
    },
    [dismiss],
  );

  const reloadWishes = useCallback(() => setWishesVersion((v) => v + 1), []);

  const state = useMemo<AppState | null>(
    () => (info && me ? { info, me, setMe, toast, reloadWishes, wishesVersion } : null),
    [info, me, toast, reloadWishes, wishesVersion],
  );

  if (!info) return <Unavailable />;
  if (!state) {
    return (
      <div className="grid min-h-screen place-items-center text-muted">
        <Spinner label={t("app.loading")} />
      </div>
    );
  }

  return (
    <AppContext.Provider value={state}>
      <a
        href="#main"
        className="sr-only z-50 rounded-wv-sm bg-accent px-3 py-2 text-accent-text focus:not-sr-only focus:fixed focus:top-2 focus:left-2"
      >
        {t("app.skip")}
      </a>
      <Header />
      <main id="main" className="mx-auto flex max-w-6xl flex-col gap-8 px-4 py-6 sm:py-10">
        <ClientHint />
        {isAdmin(state.me) && <AdminStatusPanel />}
        <SearchPanel />
        <WishList />
      </main>
      <Footer info={info} />
      <ScrollTop />
      <Toasts items={toasts} onDismiss={dismiss} />
    </AppContext.Provider>
  );
}
