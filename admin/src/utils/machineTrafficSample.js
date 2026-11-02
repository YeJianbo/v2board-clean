// Normal status reports arrive every 120 seconds; allow delivery jitter, not just heartbeats.
export function hasFreshMachineTraffic(status, now = Date.now() / 1000) {
  const sampledAt = Number(status?.traffic_reported_at);
  return sampledAt > 0 && sampledAt <= now + 5 && now - sampledAt <= 180;
}
