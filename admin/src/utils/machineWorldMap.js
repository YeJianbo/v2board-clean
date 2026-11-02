// Region markers are representative coordinates, never exact server locations.
const smallRegions = { HK: [114.17, 22.32], MO: [113.54, 22.2], SG: [103.82, 1.35] }
export function buildMapCountryPoints(countries) {
  const points = new Map(countries.filter(c => c.code && c.code !== '-99').map(c => [c.code, c.point]))
  for (const [code, [lon, lat]] of Object.entries(smallRegions)) points.set(code, [lon + 180, 85 - lat])
  return points
}
export function groupMapMachines(machines, countries, points = buildMapCountryPoints(countries)) {
  const groups = new Map()
  for (const machine of machines) {
    const code = String(machine.countryCode || '').trim().toUpperCase()
    const key = points.has(code) ? code : 'unknown'
    if (!groups.has(key)) groups.set(key, { code: key, name: machine.countryName || code || '未识别地区', point: points.get(key), machines: [], online: 0 })
    const group = groups.get(key)
    group.machines.push(machine)
    if (machine.online) group.online++
  }
  return [...groups.values()].map(g => ({ ...g, state: g.online === 0 ? 'offline' : g.online === g.machines.length ? 'online' : 'partial' }))
}
