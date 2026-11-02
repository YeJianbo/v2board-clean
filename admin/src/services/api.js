import { readStoredAuth, signalAuthExpired } from "./auth";
import { withReadDeadline } from '../utils/readRequestDeadline';
import i18n from "../i18n";
import { readDashboardResponse } from './dashboardResponse'

function getApiOrigin() {
  return import.meta.env.VITE_API_BASE_URL || "/";
}

function getDashboardSecurePath() {
  if (typeof window !== "undefined" && window.settings?.secure_path) {
    return window.settings.secure_path;
  }

  return import.meta.env.VITE_DASHBOARD_SECURE_PATH || "";
}

function getNormalizedApiOrigin() {
  return getApiOrigin().replace(/\/$/, "");
}

function getNormalizedSecurePath() {
  return getDashboardSecurePath().replace(/^\//, "");
}

function getWindowOrigin() {
  if (typeof window !== "undefined" && window.location?.origin) {
    return window.location.origin.replace(/\/$/, "");
  }

  return "http://127.0.0.1";
}

function buildApiUrl(apiPath, queryEntries = []) {
  const configuredOrigin = getNormalizedApiOrigin();
  const normalizedApiPath = String(apiPath || "").replace(/^\//, "");

  let url = null;

  if (!configuredOrigin || configuredOrigin === "/") {
    url = new URL(`/${normalizedApiPath}`, `${getWindowOrigin()}/`);
  } else if (/^https?:\/\//i.test(configuredOrigin)) {
    url = new URL(`/${normalizedApiPath}`, `${configuredOrigin}/`);
  } else {
    url = new URL(
      `${configuredOrigin.replace(/^\//, "/")}/${normalizedApiPath}`.replace(/\/{2,}/g, "/"),
      `${getWindowOrigin()}/`,
    );
  }

  queryEntries.forEach(function appendQueryEntry([key, value]) {
    if (value !== undefined && value !== null && value !== "") {
      url.searchParams.set(key, String(value));
    }
  });

  url.searchParams.set("t", String(Date.now()));

  return url.toString();
}

/**
 * 拼接管理后台 v2 接口 URL。endpointPath 可带或不带前导 `/`。
 * 之前同时存在 buildDashboardApiUrl 与 buildSecureV2ApiUrl —— 实际逻辑等价，
 * 这里统一实现，buildSecureV2ApiUrl 保留为别名以保持 import 兼容。
 */
export function buildDashboardApiUrl(endpointPath, queryEntries = []) {
  const securePath = getNormalizedSecurePath();
  const normalizedEndpoint = String(endpointPath || "").replace(/^\//, "");
  return buildApiUrl(`api/v2/${securePath}/${normalizedEndpoint}`, queryEntries);
}

// 历史 alias —— 与 buildDashboardApiUrl 等价。保留以兼容现有 import。
export const buildSecureV2ApiUrl = buildDashboardApiUrl;

export function buildCommonApiUrl(endpointPath, queryEntries = []) {
  const normalizedPath = String(endpointPath || "").replace(/^\//, "");
  return buildApiUrl(`api/v1/${normalizedPath}`, queryEntries);
}

export function getDashboardApiHeaders() {
  const storedSession = readStoredAuth();
  const headers = {
    Accept: "application/json, text/plain, */*",
  };
  headers['X-Admin-Request'] = '1';
  if (storedSession?.csrfToken) {
    headers['X-Admin-CSRF'] = storedSession.csrfToken;
  }
  return headers;
}

export async function requestDashboardApi(url, { signal, timeoutMs = 60000 } = {}) {
  const headers = getDashboardApiHeaders();
  const payload = await withReadDeadline(async (signal) => {
    const response = await fetch(url, {
      headers,
      signal,
    });
    return readDashboardResponse(response, () => signalAuthExpired(`get:${response.status}`, headers['X-Admin-CSRF']));
  }, timeoutMs, signal);

  if (payload?.status && payload.status !== "success") {
    throw new Error(
      payload.message || resolveMessage("defaults.dashboardStatusFailed"),
    );
  }

  if (payload?.code !== undefined && Number(payload.code) !== 0) {
    throw new Error(
      payload.message || resolveMessage("defaults.dashboardCodeFailed"),
    );
  }

  return payload;
}

// Only query endpoints use this transport; writes must not be cancelled or retried.
export async function requestDashboardReadPost(url, payload, { signal } = {}) {
  const headers = { ...getDashboardApiHeaders(), "Content-Type": "application/json" };
  const result = await withReadDeadline(async (signal) => {
    const response = await fetch(url, { method: "POST", headers, signal, body: JSON.stringify(payload ?? {}) });
    return readDashboardResponse(response, () => signalAuthExpired(`read-post:${response.status}`, headers['X-Admin-CSRF']));
  }, 60000, signal);
  if (result?.status && result.status !== "success") throw new Error(result.message || resolveMessage("defaults.dashboardStatusFailed"));
  if (result?.code !== undefined && Number(result.code) !== 0) throw new Error(result.message || resolveMessage("defaults.dashboardCodeFailed"));
  return result;
}

export async function requestDashboardMutation(url, payload, method = "POST") {
  const headers = {
    ...getDashboardApiHeaders(),
    "Content-Type": "application/json",
  };
  const response = await fetch(url, {
    method,
    headers,
    body: payload ? JSON.stringify(payload) : undefined,
  });

  let responsePayload;
  try {
    responsePayload = await readDashboardResponse(response, () => signalAuthExpired(`mutation:${response.status}`, headers['X-Admin-CSRF']));
  } catch (error) {
    error.httpStatus = response.status;
    throw error;
  }

  if (responsePayload?.status && responsePayload.status !== "success") {
    throw new Error(
      responsePayload.message ||
        resolveMessage("defaults.dashboardStatusFailed"),
    );
  }

  if (
    responsePayload?.code !== undefined &&
    Number(responsePayload.code) !== 0
  ) {
    throw new Error(
      responsePayload.message || resolveMessage("defaults.dashboardCodeFailed"),
    );
  }

  return responsePayload;
}

export async function requestDashboardUpload(url, formData, method = "POST") {
  const headers = {
    ...getDashboardApiHeaders(),
  };
  const response = await fetch(url, {
    method,
    headers,
    body: formData,
  });

  const responsePayload = await readDashboardResponse(response, () => signalAuthExpired(`upload:${response.status}`, headers['X-Admin-CSRF']));

  if (responsePayload?.status && responsePayload.status !== "success") {
    throw new Error(
      responsePayload.message ||
        resolveMessage("defaults.dashboardStatusFailed"),
    );
  }

  if (
    responsePayload?.code !== undefined &&
    Number(responsePayload.code) !== 0
  ) {
    throw new Error(
      responsePayload.message || resolveMessage("defaults.dashboardCodeFailed"),
    );
  }

  return responsePayload;
}

function resolveMessage(key, values) {
  if (i18n?.global?.te?.(key)) {
    return i18n.global.t(key, values);
  }
  // i18n 缺 key 时直接返回 key 本身：让开发期立刻发现缺失，而不是被 fallback
  // 字典默默兜底。之前的中文 fallback 重复了 zh-CN.js 里的字面量，永远不会触发。
  return key;
}
