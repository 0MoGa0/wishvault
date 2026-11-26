import { useEffect, useId, useRef, useState } from "react";
import { endpoints, type SearchResponse, type SearchResult, type Source } from "../api/client";
import { isAccount, useApp } from "../context";
import { useT } from "../i18n";
import { errorMessage } from "../lib/errors";
import { formatReleaseDate } from "../lib/format";
import { GameArt } from "./GameArt";
import { SourceBadge, SourceSwitch } from "./SourceMark";
import { Badge, Button, Icon, Notice, Spinner } from "./ui";

export const SEARCH_DEBOUNCE_MS = 350;
export const SEARCH_MIN_CHARS = 2;

export function SearchPanel() {
  const t = useT();
  const { info, me, toast, reloadWishes } = useApp();
  const [query, setQuery] = useState("");
  const [source, setSource] = useState<Source>("steam");
  const [response, setResponse] = useState<SearchResponse | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [providerName, setProviderName] = useState<string | null>(null);
  const [pending, setPending] = useState<string | null>(null);
  // After an automatic fallback, "Steam" shows the (empty) Steam result without a new request.
  const [forceSteam, setForceSteam] = useState(false);
  const seq = useRef(0);
  const input = useRef<HTMLInputElement>(null);
  const id = useId();
  const q = query.trim();
  const active = q.length >= SEARCH_MIN_CHARS;

  useEffect(() => {
    // Every change invalidates responses still in flight.
    const mine = ++seq.current;
    if (q.length < SEARCH_MIN_CHARS) {
      setResponse(null);
      setLoading(false);
      setError(null);
      return;
    }
    setLoading(true);
    setForceSteam(false);
    const timer = setTimeout(() => {
      endpoints.search(q, source).then(
        (res) => {
          if (mine !== seq.current) return;
          setResponse(res);
          setError(null);
          setLoading(false);
          if (res.provider?.name) setProviderName(res.provider.name);
        },
        (e: unknown) => {
          if (mine !== seq.current) return;
          setResponse(null);
          setError(errorMessage(e));
          setLoading(false);
        },
      );
    }, SEARCH_DEBOUNCE_MS);
    return () => clearTimeout(timer);
  }, [q, source]);

  function updateResult(r: SearchResult, patch: Partial<SearchResult>) {
    setResponse((prev) =>
      prev && {
        ...prev,
        results: prev.results.map((x) => (x.source === r.source && x.source_id === r.source_id ? { ...x, ...patch } : x)),
      },
    );
  }

  async function wish(r: SearchResult) {
    const key = `${r.source}:${r.source_id}`;
    setPending(key);
    try {
      const res = await endpoints.createWish(r.source, r.source_id);
      updateResult(r, { wish_id: res.wish.id, voted: true });
      toast(t(res.created ? "search.wished" : "search.votedToast", { title: res.wish.title }), "success");
      reloadWishes();
      clear();
    } catch (e) {
      toast(errorMessage(e), "error");
    } finally {
      setPending(null);
    }
  }

  function clear() {
    setQuery("");
    setSource("steam");
    input.current?.focus();
  }

  function switchSource(next: Source) {
    if (next === "steam") {
      if (response?.fallback) setForceSteam(true);
      else setSource("steam");
    } else if (response?.fallback) {
      setForceSteam(false);
    } else {
      setSource("provider");
    }
  }

  // The service knows the active provider from its last library sync
  const knownProvider = response?.provider?.name ?? providerName ?? info.provider?.name ?? null;
  const provider = knownProvider ?? t("search.providerGeneric");
  const providerShort = knownProvider ?? t("search.providerShort");
  const shown: Source = forceSteam ? "steam" : loading || !response ? source : response.source;
  const results = forceSteam ? [] : (response?.results ?? []);

  return (
    <section aria-labelledby={`${id}-title`} className="rounded-wv border border-border bg-surface p-4 shadow-wv-sm sm:p-6">
      <h2 id={`${id}-title`} className="font-heading text-xl font-bold tracking-tight">
        {t("search.title")}
      </h2>
      <p className="mt-1 text-sm text-muted">{t("search.subtitle")}</p>

      {!me.can_wish && (
        <Notice tone="warning" className="mt-3">
          {isAccount(me) ? t("search.roleTooLow") : info.features.web_login ? t("search.loginToWish") : t("search.wishingDisabled")}
        </Notice>
      )}

      <div className="relative mt-4">
        <label htmlFor={`${id}-q`} className="sr-only">
          {t("search.label")}
        </label>
        <Icon name="search" className="pointer-events-none absolute top-1/2 left-3.5 size-5 -translate-y-1/2 text-muted" />
        <input
          ref={input}
          id={`${id}-q`}
          type="search"
          value={query}
          maxLength={100}
          autoComplete="off"
          spellCheck={false}
          placeholder={t("search.placeholder")}
          onChange={(e) => {
            setQuery(e.target.value);
            setSource("steam");
          }}
          onKeyDown={(e) => {
            if (e.key === "Escape") clear();
          }}
          aria-describedby={`${id}-status`}
          className="h-12 w-full rounded-wv border border-border bg-bg pr-24 pl-11 text-base text-text placeholder:text-muted/80"
        />
        <div className="absolute top-1/2 right-2 flex -translate-y-1/2 items-center gap-2">
          {loading && (
            <span className="text-muted">
              <Spinner small />
            </span>
          )}
          {query !== "" && (
            <button
              type="button"
              onClick={clear}
              aria-label={t("search.clear")}
              title={t("search.clear")}
              className="grid size-8 place-items-center rounded-full bg-surface-2 text-text transition-colors hover:bg-danger hover:text-accent-text"
            >
              <Icon name="close" className="size-4" />
            </button>
          )}
        </div>
      </div>

      <p id={`${id}-status`} aria-live="polite" className="mt-2 min-h-5 text-xs text-muted">
        {!active && query.trim().length > 0
          ? t("search.minChars", { count: SEARCH_MIN_CHARS })
          : response && !loading
            ? t("search.resultCount", { count: results.length })
            : loading
              ? t("search.searching")
            : ""}
      </p>

      {active && error && !loading && <Notice tone="danger">{error}</Notice>}

      {active && (loading || response || error) && (
        <div className="flex flex-col gap-3">
          {!loading && response && !forceSteam && response.fallback && (
            <Notice tone="accent">{t("search.fallback", { provider })}</Notice>
          )}
          {!loading && response && !forceSteam && !response.fallback && response.source === "provider" && (
            <Notice tone="accent">{t("search.providerResults", { provider })}</Notice>
          )}

          {loading ? (
            <ul aria-hidden="true" className="max-h-[max(10rem,calc(100dvh-22rem))] divide-y divide-border overflow-hidden rounded-wv border border-border">
              {Array.from({ length: 4 }, (_, i) => (
                <li key={i} className="flex animate-pulse items-center gap-3 bg-surface p-2.5 sm:gap-4">
                  <div className="aspect-[460/215] w-24 shrink-0 rounded-wv-sm bg-surface-2 sm:w-32" />
                  <div className="flex flex-1 flex-col gap-2">
                    <div className="h-4 w-2/3 rounded bg-surface-2" />
                    <div className="h-3 w-1/3 rounded bg-surface-2" />
                  </div>
                  <div className="h-8 w-20 rounded-wv-sm bg-surface-2" />
                </li>
              ))}
            </ul>
          ) : !response ? null : results.length === 0 ? (
            <p className="py-4 text-center text-sm text-muted">
              {shown === "steam" ? t("search.noResultsSteam") : t("search.noResults")}
            </p>
          ) : (
            <ul
              aria-label={t("search.results")}
              className="max-h-[max(10rem,calc(100dvh-22rem))] divide-y divide-border overflow-y-auto overscroll-contain rounded-wv border border-border [scrollbar-gutter:stable]"
            >
              {results.map((r) => {
                const key = `${r.source}:${r.source_id}`;
                const release = formatReleaseDate(r.release_date);
                return (
                  <li key={key} className="flex items-center gap-3 bg-surface py-2.5 pr-3 pl-2.5 transition-colors hover:bg-surface-2 sm:gap-4">
                    <div className="relative shrink-0">
                      <GameArt src={r.image_url} title={r.title} className="aspect-[460/215] w-24 rounded-wv-sm sm:w-32" />
                      <SourceBadge source={r.source} providerName={providerShort} className="absolute right-1 bottom-1" />
                    </div>
                    <div className="min-w-0 flex-1">
                      <p className="truncate font-medium">
                        {r.store_url ? (
                          <a
                            href={r.store_url}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="underline-offset-2 hover:text-accent hover:underline"
                          >
                            {r.title}
                          </a>
                        ) : (
                          r.title
                        )}
                      </p>
                      <p className="flex flex-wrap items-center gap-1.5 text-xs text-muted">
                        {release && <span>{r.coming_soon ? t("wish.comingSoon", { date: release }) : release}</span>}
                        {!release && r.coming_soon && <span>{t("wish.comingSoonNoDate")}</span>}
                        {r.gamevault_game_id !== null && <Badge tone="success">{t("search.inGameVault")}</Badge>}
                        {r.gamevault_game_id === null && r.wish_id !== null && (
                          <Badge tone="accent">{r.voted ? t("search.wishedVoted") : t("search.alreadyWished")}</Badge>
                        )}
                      </p>
                    </div>
                    {r.gamevault_game_id === null &&
                      (r.wish_id !== null && r.voted ? (
                        <span className="inline-flex items-center gap-1 px-2 text-sm text-success">
                          <Icon name="check" />
                          <span className="hidden sm:inline">{t("wish.voted")}</span>
                        </span>
                      ) : (
                        <Button
                          size="sm"
                          variant="primary"
                          disabled={!me.can_wish}
                          busy={pending === key}
                          onClick={() => void wish(r)}
                          aria-label={`${t(r.wish_id !== null ? "wish.voteAction" : "search.wish")}: ${r.title}`}
                        >
                          <Icon name={r.wish_id !== null ? "up" : "plus"} />
                          {t(r.wish_id !== null ? "wish.voteAction" : "search.wish")}
                        </Button>
                      ))}
                  </li>
                );
              })}
            </ul>
          )}

          {info.features.provider_search && (
            <div className="flex flex-wrap items-center justify-end gap-2">
              <span className="text-xs text-muted">{t("search.sourceLabel")}</span>
              <SourceSwitch
                value={shown}
                providerName={providerShort}
                label={t("search.sourceLabel")}
                disabled={loading}
                onChange={switchSource}
              />
            </div>
          )}
        </div>
      )}
    </section>
  );
}
