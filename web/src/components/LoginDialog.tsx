import { useEffect, useId, useState, type FormEvent } from "react";
import { endpoints } from "../api/client";
import { useApp } from "../context";
import { useT } from "../i18n";
import { errorMessage } from "../lib/errors";
import { Button, Dialog, Notice } from "./ui";

export function LoginDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
  const t = useT();
  const { setMe, toast, reloadWishes } = useApp();
  const [username, setUsername] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const id = useId();

  useEffect(() => {
    if (open) {
      setError(null);
      setPassword("");
    }
  }, [open]);

  async function submit(e: FormEvent) {
    e.preventDefault();
    if (!username.trim() || !password) return;
    setBusy(true);
    setError(null);
    try {
      const res = await endpoints.login(username.trim(), password);
      setPassword("");
      setMe(res.me);
      const { votes, wishes } = res.moved;
      toast(
        votes + wishes > 0 ? t("auth.moved", { votes, wishes }) : t("auth.loggedIn", { name: res.me.user?.username ?? "" }),
        "success",
      );
      reloadWishes();
      onClose();
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Dialog open={open} onClose={onClose} title={t("auth.title")}>
      <form onSubmit={(e) => void submit(e)} className="flex flex-col gap-4" noValidate>
        <p className="text-sm text-muted">{t("auth.hint")}</p>
        <div className="flex flex-col gap-1.5">
          <label htmlFor={`${id}-user`} className="text-sm font-medium">
            {t("auth.username")}
          </label>
          <input
            id={`${id}-user`}
            autoComplete="username"
            required
            maxLength={255}
            value={username}
            onChange={(e) => setUsername(e.target.value)}
            className="h-10 rounded-wv-sm border border-border bg-bg px-3 text-text"
          />
        </div>
        <div className="flex flex-col gap-1.5">
          <label htmlFor={`${id}-pass`} className="text-sm font-medium">
            {t("auth.password")}
          </label>
          <input
            id={`${id}-pass`}
            type="password"
            autoComplete="current-password"
            required
            maxLength={1024}
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            className="h-10 rounded-wv-sm border border-border bg-bg px-3 text-text"
          />
        </div>
        {error && (
          <Notice tone="danger">
            <span role="alert">{error}</span>
          </Notice>
        )}
        <div className="flex justify-end gap-2">
          <Button onClick={onClose}>{t("common.cancel")}</Button>
          <Button type="submit" variant="primary" busy={busy} disabled={!username.trim() || !password}>
            {t("auth.submit")}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}
