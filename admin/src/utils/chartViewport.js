import { smoothLinePath } from './smoothChartPath.js'

const chartPointX = point => point.x

// Point x coordinates are ordered by the chart layout. Keep a small overscan for bar edges.
export function chartPointsInViewport(points, left, width, overscan = 48, includeNeighbours = false) {
  const [first, last] = chartViewportRange(points, left, width, overscan, includeNeighbours)
  return points.slice(first, last)
}

// Lazy layouts can search raw points without allocating coordinates for the entire timeline.
export function chartViewportRange(points, left, width, overscan = 48, includeNeighbours = false, coordinate = chartPointX) {
  if (!points.length || !Number.isFinite(left) || !Number.isFinite(width) || width <= 0) return [0, 0]
  const start = left - overscan, end = left + width + overscan
  const boundary = (value, inclusive) => {
    let low = 0, high = points.length
    while (low < high) {
      const middle = Math.floor((low + high) / 2)
      const x = coordinate(points[middle], middle)
      if (x < value || (inclusive && x === value)) low = middle + 1
      else high = middle
    }
    return low
  }
  const first = boundary(start, false), last = boundary(end, true)
  // A line can cross the viewport even when neither endpoint is inside it.
  return [Math.max(0, first - Number(includeNeighbours)), Math.min(points.length, last + Number(includeNeighbours))]
}

export function trafficLinePath(points) {
  return smoothLinePath(points)
}

export function trafficBarPath(points) {
  return points.map(point => {
    const left = (point.x - point.barWidth / 2).toFixed(2)
    const top = point.y.toFixed(2), width = point.barWidth.toFixed(2), height = point.barHeight.toFixed(2)
    return `M${left},${top}h${width}v${height}h-${width}Z`
  }).join('')
}
