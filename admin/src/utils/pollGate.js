export function createPollGate() {
  let active = null
  return {
    begin() {
      if (active) return null
      active = {}
      return active
    },
    isCurrent(token) { return token !== null && active === token },
    finish(token) { if (active === token) active = null },
    invalidate() { active = null },
  }
}
