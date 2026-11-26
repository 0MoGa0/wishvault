import { useEffect, useState } from "react";
import { api, type Info } from "./api/client";
import { t } from "./i18n";

// Skeleton only – track W builds the real UI.
export function App() {
  const [info, setInfo] = useState<Info | null>(null);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    api<Info>("/info").then(setInfo, () => setFailed(true));
  }, []);

  return (
    <main className="mx-auto max-w-4xl p-6">
      <h1 className="text-2xl font-semibold">{info?.name ?? "WishVault"}</h1>
      <p className="mt-2 text-muted">
        {failed ? t("app.unavailable") : info ? `v${info.version}` : t("app.loading")}
      </p>
    </main>
  );
}
