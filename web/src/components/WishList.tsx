import { useCallback, useEffect, useId, useRef, useState, type KeyboardEvent } from "react";
import { endpoints, type Wish, type WishFilter, type WishSort } from "../api/client";
import { isAccount, useApp } from "../context";
import { useT, type MessageKey } from "../i18n";
import { errorMessage } from "../lib/errors";
import { formatNumber } from "../lib/format";
import { WishCard } from "./WishCard";
import { Button, cx, Notice } from "./ui";

export const PAGE_SIZE = 24;

export function WishList() {
  const t = useT();
  const { me, wishesVersion } = useApp();
  const account = isAccount(me);
  const tabs: WishFilter[] = account ? ["open", "available", "voted", "rejected", "all"] : ["open", "available", "voted"];
  const [filter, setFilter] = useState<WishFilter>("open");
  const [sort, setSort] = useState<WishSort>("votes");
  const [items, setItems] = useState<Wish[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const seq = useRef(0);
  const tabRefs = useRef<(HTMLButtonElement | null)[]>([]);
  const id = useId();
  const current: WishFilter = tabs.includes(filter) ? filter : "open";

  const load = useCallback(
    async (offset: number) => {
      const mine = ++seq.current;
      if (offset === 0) setLoading(true);
      else setLoadingMore(true);
      try {
        const res = await endpoints.wishes(current, sort, PAGE_SIZE, offset);
        if (mine !== seq.current) return;
        setItems((prev) => (offset === 0 ? res.items : [...prev, ...res.items.filter((w) => !prev.some((p) => p.id === w.id))]));
        setTotal(res.total);
        setError(null);
      } catch (e) {
        if (mine !== seq.current) return;
        setError(errorMessage(e));
        if (offset === 0) setItems([]);
      } finally {
        if (mine === seq.current) {
          setLoading(false);
          setLoadingMore(false);
        }
      }
    },
    [current, sort],
  );

  useEffect(() => {
    void load(0);
  }, [load, wishesVersion, me.kind]);

  function onTabKey(e: KeyboardEvent, index: number) {
    let next = -1;
    if (e.key === "ArrowRight") next = (index + 1) % tabs.length;
    else if (e.key === "ArrowLeft") next = (index - 1 + tabs.length) % tabs.length;
    else if (e.key === "Home") next = 0;
    else if (e.key === "End") next = tabs.length - 1;
    if (next < 0) return;
    e.preventDefault();
    setFilter(tabs[next]!);
    tabRefs.current[next]?.focus();
  }

  const replace = (w: Wish) => setItems((prev) => prev.map((x) => (x.id === w.id ? w : x)));
  const remove = (wishId: number) => {
    setItems((prev) => prev.filter((x) => x.id !== wishId));
    setTotal((n) => Math.max(0, n - 1));
  };

  return (
    <section aria-labelledby={`${id}-title`} className="flex flex-col gap-4">
      {/* Title, sort and tabs stay visible while scrolling through many wishes */}
      <div className="wv-page-bg sticky top-[var(--wv-header-h,3.75rem)] z-20 -mx-4 flex flex-col gap-3 px-4 pt-3">
        <div className="flex flex-wrap items-end justify-between gap-3">
          <h2 id={`${id}-title`} className="font-heading text-2xl font-bold tracking-tight">
            {t("nav.wishlist")}
            {!loading && !error && <span className="ml-2 text-base font-medium text-muted">{formatNumber(total)}</span>}
          </h2>
          <label className="flex items-center gap-2 text-sm">
            <span className="text-muted">{t("list.sort")}</span>
            <select
              value={sort}
              onChange={(e) => setSort(e.target.value as WishSort)}
              className="h-9 rounded-wv-sm border border-border bg-surface px-2 text-sm text-text"
            >
              <option value="votes">{t("list.sortVotes")}</option>
              <option value="new">{t("list.sortNew")}</option>
            </select>
          </label>
        </div>

        <div role="tablist" aria-label={t("list.filter")} className="flex gap-1 overflow-x-auto border-b border-border">
          {tabs.map((f, i) => (
            <button
              key={f}
              ref={(el) => {
                tabRefs.current[i] = el;
              }}
              type="button"
              role="tab"
              id={`${id}-tab-${f}`}
              aria-selected={current === f}
              aria-controls={`${id}-panel`}
              tabIndex={current === f ? 0 : -1}
              onClick={() => setFilter(f)}
              onKeyDown={(e) => onTabKey(e, i)}
              className={cx(
                "-mb-px border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap transition-colors",
                current === f ? "border-accent text-text" : "border-transparent text-muted hover:border-border hover:bg-surface-2 hover:text-text",
              )}
            >
              {t(`list.tab.${f}` as MessageKey)}
            </button>
          ))}
        </div>
      </div>

      <div id={`${id}-panel`} role="tabpanel" aria-labelledby={`${id}-tab-${current}`} aria-busy={loading}>
        {error && !loading ? (
          <Notice tone="danger" className="flex flex-wrap items-center justify-between gap-2">
            <span>{error}</span>
            <Button size="sm" onClick={() => void load(0)}>
              {t("common.retry")}
            </Button>
          </Notice>
        ) : loading ? (
          <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" aria-hidden="true">
            {Array.from({ length: 6 }, (_, i) => (
              <li key={i} className="h-80 animate-pulse rounded-wv border border-border bg-surface" />
            ))}
          </ul>
        ) : items.length === 0 ? (
          <div className="rounded-wv border border-dashed border-border px-6 py-12 text-center">
            <p className="font-medium">{t(`list.empty.${current}` as MessageKey)}</p>
            <p className="mt-1 text-sm text-muted">{t("list.emptyHint")}</p>
          </div>
        ) : (
          <>
            <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
              {items.map((w) => (
                <li key={w.id} className="flex">
                  <div className="flex w-full flex-col [&>article]:flex-1">
                    <WishCard wish={w} onChange={replace} onRemove={remove} />
                  </div>
                </li>
              ))}
            </ul>
            {items.length < total && (
              <div className="mt-6 flex flex-col items-center gap-2">
                <Button busy={loadingMore} onClick={() => void load(items.length)}>
                  {t("list.loadMore")}
                </Button>
                <p className="text-xs text-muted">{t("list.shown", { shown: items.length, total })}</p>
              </div>
            )}
          </>
        )}
      </div>
    </section>
  );
}
