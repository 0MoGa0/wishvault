import { ApiError, type ClientErrorCode, type ErrorCode } from "../api/client";
import { t } from "../i18n";

// Every ErrorCode of the contract has a message `error.<code>` in the locales
// (checked by a test); the type below fails to compile when the contract
// gains a code that is missing here.
export const ERROR_CODES = [
  "unauthorized",
  "forbidden",
  "role_too_low",
  "anonymous_disabled",
  "csrf_failed",
  "not_found",
  "source_not_found",
  "validation_failed",
  "already_in_library",
  "wish_closed",
  "rate_limited",
  "login_failed",
  "login_locked",
  "gamevault_unavailable",
  "steam_unavailable",
  "provider_unavailable",
  "not_implemented",
  "internal_error",
  "network",
] as const satisfies readonly ClientErrorCode[];

type Missing = Exclude<ErrorCode, (typeof ERROR_CODES)[number]>;
const exhaustive: [Missing] extends [never] ? true : Missing = true;
void exhaustive;

export function formatDuration(seconds: number): string {
  if (seconds < 60) return t("time.seconds", { count: seconds });
  const minutes = Math.ceil(seconds / 60);
  if (minutes < 60) return t("time.minutes", { count: minutes });
  return t("time.hours", { count: Math.ceil(minutes / 60) });
}

/** Translated, user-facing text for any error thrown by the API client. */
export function errorMessage(err: unknown): string {
  if (!(err instanceof ApiError)) return t("error.internal_error");
  const code = (ERROR_CODES as readonly string[]).includes(err.code) ? err.code : "internal_error";
  const text = t(`error.${code}` as `error.${(typeof ERROR_CODES)[number]}`);
  if (err.retryAfter && (code === "rate_limited" || code === "login_locked")) {
    return `${text} ${t("error.retry_after", { time: formatDuration(err.retryAfter) })}`;
  }
  return text;
}
