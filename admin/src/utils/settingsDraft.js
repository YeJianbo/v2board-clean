export function mergeSettingsDraft(current, initial, incoming) {
  const next = { ...current }
  for (const [key, value] of Object.entries(incoming)) {
    // Refresh server values without replacing fields edited in this session.
    if (JSON.stringify(current[key]) === JSON.stringify(initial[key])) next[key] = value
  }
  return next
}
