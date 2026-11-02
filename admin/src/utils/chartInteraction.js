const chartPointX = point => point.x

// Points must be sorted by the selected coordinate, including empty time buckets.
export function nearestChartPoint(points, x, coordinate = chartPointX) {
  const index = nearestChartPointIndex(points, x, coordinate)
  return index < 0 ? null : points[index]
}

export function nearestChartPointIndex(points, x, coordinate = chartPointX) {
  if (!points.length || !Number.isFinite(x)) return -1
  let low = 0, high = points.length - 1
  while (low < high) {
    const middle = Math.floor((low + high) / 2)
    if (coordinate(points[middle], middle) < x) low = middle + 1
    else high = middle
  }
  if (low === 0) return 0
  const left = points[low - 1], right = points[low]
  return Math.abs(coordinate(left, low - 1) - x) <= Math.abs(coordinate(right, low) - x) ? low - 1 : low
}
