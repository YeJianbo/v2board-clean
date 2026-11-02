export function userEditPatch(payload, draft, original) {
  return Object.fromEntries(Object.entries(payload).filter(([key]) => key === 'id' || JSON.stringify(draft[key]) !== JSON.stringify(original[key])))
}

export function userTrafficBytes(value, label = '流量') {
  const gb = Number(value)
  if (value === '' || value === null || !Number.isFinite(gb) || gb < 0) {
    throw new Error(`${label}请输入非负数，单位为 GB`)
  }
  const rounded = Math.round(gb * 1073741824)
  if (!Number.isFinite(rounded) || BigInt(rounded) > 9223372036854775807n) {
    throw new Error(`${label}超出存储上限，请检查 GB 单位（最大约 85.9 亿 GB）`)
  }
  return Number.isSafeInteger(rounded) ? rounded : BigInt(rounded).toString()
}
