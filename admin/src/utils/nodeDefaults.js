export const DEFAULT_NODE_SNI = "genshin.hoyoverse.com";
export const DEFAULT_TUIC_CONGESTION_CONTROL = "bbr";
export const DEFAULT_HYSTERIA_VERSION = "v2";
export const DEFAULT_HYSTERIA_OBFS_TYPE = "salamander";
export const DEFAULT_ANYTLS_PADDING_SCHEME =
  "stop=8\n0=30-30\n1=100-400\n2=400-500,c,500-1000,c,500-1000,c,500-1000,c,500-1000\n3=9-9,500-1000\n4=500-1000\n5=500-1000\n6=500-1000\n7=500-1000";

const SECRET_ALPHABET =
  "ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789";

export function generateRandomHighPort() {
  return Math.floor(Math.random() * (60000 - 20000 + 1)) + 20000;
}

function randomIndex(max) {
  if (
    typeof globalThis !== "undefined" &&
    globalThis.crypto &&
    typeof globalThis.crypto.getRandomValues === "function"
  ) {
    const buffer = new Uint32Array(1);
    globalThis.crypto.getRandomValues(buffer);
    return buffer[0] % max;
  }

  return Math.floor(Math.random() * max);
}

export function generateRandomObfsPassword(length = 16) {
  const size = Number(length) > 0 ? Number(length) : 16;
  let result = "";

  for (let index = 0; index < size; index += 1) {
    result += SECRET_ALPHABET[randomIndex(SECRET_ALPHABET.length)];
  }

  return result;
}

export function ensureHysteriaObfsPassword(password) {
  const normalized = String(password || "").trim();
  return normalized || generateRandomObfsPassword();
}

export function resolveNodeProtocolLabel(protocol) {
  const normalized = String(protocol || "").trim().toLowerCase();

  switch (normalized) {
    case "vmess":
      return "VMess";
    case "trojan":
      return "Trojan";
    case "hysteria":
    case "hysteria2":
      return "Hysteria2";
    case "vless":
      return "VLESS";
    case "tuic":
      return "TUIC";
    case "mieru":
      return "Mieru";
    case "anytls":
      return "AnyTLS";
    case "shadowsocks":
      return "Shadowsocks";
    default:
      return normalized ? normalized.toUpperCase() : "Node";
  }
}

export function buildFlagEmoji(countryCode, fallback = "🌐") {
  const normalized = String(countryCode || "").trim().toUpperCase();

  if (!/^[A-Z]{2}$/.test(normalized)) {
    return fallback;
  }

  const codePoints = Array.from(normalized).map(function mapLetter(letter) {
    return 127397 + letter.charCodeAt(0);
  });

  return String.fromCodePoint(...codePoints);
}

export function extractLeadingFlagCode(value) {
  const characters = Array.from(String(value || "").trim());
  if (characters.length < 2) {
    return "";
  }

  const regionalStart = 0x1f1e6;
  const first = characters[0].codePointAt(0);
  const second = characters[1].codePointAt(0);
  if (
    first < regionalStart ||
    first > regionalStart + 25 ||
    second < regionalStart ||
    second > regionalStart + 25
  ) {
    return "";
  }

  return String.fromCharCode(
    first - regionalStart + 65,
    second - regionalStart + 65,
  );
}

export function stripLeadingFlagEmoji(value) {
  const raw = String(value || "").trim();
  if (!extractLeadingFlagCode(raw)) {
    return raw;
  }

  return Array.from(raw)
    .slice(2)
    .join("")
    .replace(/^[\s\uFE0E\uFE0F\u200D]+/, "")
    .trim();
}

const COUNTRY_NAME_CODE_MAP = {
  "中国": "CN",
  "香港": "HK",
  "澳门": "MO",
  "台湾": "TW",
  "日本": "JP",
  "韩国": "KR",
  "新加坡": "SG",
  "马来西亚": "MY",
  "泰国": "TH",
  "菲律宾": "PH",
  "越南": "VN",
  "印度尼西亚": "ID",
  "澳大利亚": "AU",
  "印度": "IN",
  "阿联酋": "AE",
  "美国": "US",
  "加拿大": "CA",
  "英国": "GB",
  "德国": "DE",
  "法国": "FR",
  "荷兰": "NL",
  China: "CN",
  HongKong: "HK",
  Hong_Kong: "HK",
  Macau: "MO",
  Taiwan: "TW",
  Japan: "JP",
  Korea: "KR",
  Singapore: "SG",
  Malaysia: "MY",
  Thailand: "TH",
  Philippines: "PH",
  Vietnam: "VN",
  Indonesia: "ID",
  UnitedStates: "US",
  USA: "US",
  America: "US",
  Canada: "CA",
  Britain: "GB",
  UK: "GB",
  Germany: "DE",
  France: "FR",
  Netherlands: "NL",
  Australia: "AU",
  India: "IN",
  UAE: "AE",
};

const COUNTRY_CODE_TOKENS = new Set([
  "CN",
  "HK",
  "MO",
  "TW",
  "JP",
  "KR",
  "SG",
  "MY",
  "TH",
  "PH",
  "US",
  "CA",
  "GB",
  "UK",
  "DE",
  "FR",
  "NL",
  "AU",
  "VN",
  "ID",
  "IN",
  "AE",
]);

export const NODE_COUNTRY_OPTIONS = [
  { code: "CN", name: "中国大陆" },
  { code: "HK", name: "香港" },
  { code: "MO", name: "澳门" },
  { code: "TW", name: "台湾" },
  { code: "JP", name: "日本" },
  { code: "KR", name: "韩国" },
  { code: "SG", name: "新加坡" },
  { code: "MY", name: "马来西亚" },
  { code: "TH", name: "泰国" },
  { code: "PH", name: "菲律宾" },
  { code: "VN", name: "越南" },
  { code: "ID", name: "印度尼西亚" },
  { code: "US", name: "美国" },
  { code: "CA", name: "加拿大" },
  { code: "GB", name: "英国" },
  { code: "DE", name: "德国" },
  { code: "FR", name: "法国" },
  { code: "NL", name: "荷兰" },
  { code: "AU", name: "澳大利亚" },
  { code: "IN", name: "印度" },
  { code: "AE", name: "阿联酋" },
];

export function resolveCountryName(countryCode, fallback = "") {
  const normalized = String(countryCode || "").trim().toUpperCase();
  const matched = NODE_COUNTRY_OPTIONS.find(function findCountry(country) {
    return country.code === normalized;
  });

  return matched?.name || String(fallback || "").trim() || normalized;
}

export function inferCountryCode(...sources) {
  for (const source of sources) {
    const raw = String(source || "").trim();
    if (!raw) {
      continue;
    }

    const leadingFlagCode = extractLeadingFlagCode(raw);
    if (leadingFlagCode) {
      return leadingFlagCode;
    }

    const normalized = raw.toUpperCase();
    if (/^[A-Z]{2}$/.test(normalized)) {
      return normalized === "UK" ? "GB" : normalized;
    }

    const compact = raw.replace(/\s+/g, "");
    if (COUNTRY_NAME_CODE_MAP[compact]) {
      return COUNTRY_NAME_CODE_MAP[compact];
    }

    const tokenMatch = raw.match(/\b([A-Za-z]{2})\b/);
    if (tokenMatch) {
      const token = tokenMatch[1].toUpperCase();
      if (COUNTRY_CODE_TOKENS.has(token)) {
        return token === "UK" ? "GB" : token;
      }
    }
  }

  return "";
}

function padDatePart(value) {
  return String(value).padStart(2, "0");
}

export function buildUniqueNodeNameSuffix() {
  const now = new Date();
  const datePart = [
    padDatePart(now.getMonth() + 1),
    padDatePart(now.getDate()),
  ].join("");
  const timePart = [
    padDatePart(now.getHours()),
    padDatePart(now.getMinutes()),
    padDatePart(now.getSeconds()),
  ].join("");
  const randomPart = Math.floor(Math.random() * 900 + 100);

  return `${datePart}-${timePart}-${randomPart}`;
}

export function buildDefaultNodeName(options = {}) {
  const resolvedCountryCode = inferCountryCode(
    options.countryCode,
    options.countryName,
    options.baseName,
  );
  const flag = buildFlagEmoji(resolvedCountryCode, "🌐");
  const baseName = String(options.baseName || "").trim();
  const protocolLabel = resolveNodeProtocolLabel(options.protocol);
  const suffix = String(options.suffix || "").trim() || buildUniqueNodeNameSuffix();

  return [flag, baseName, protocolLabel, suffix].filter(Boolean).join(" ");
}
