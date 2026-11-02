export function seriesMaximum(series, field, minimum = 0) {
  let maximum = minimum
  for (const item of series) {
    for (const point of Array.isArray(item.points) ? item.points : []) {
      const value = Number(point?.[field])
      if (Number.isFinite(value) && value > maximum) maximum = value
    }
  }
  return maximum
}
