function segmentPath(points) {
  const coordinate = point => `${point.x.toFixed(2)} ${point.y.toFixed(2)}`
  let path = `M ${coordinate(points[0])}`
  if (points.length < 2) return path
  if (points.length === 2) return `${path} L ${coordinate(points[1])}`

  const slopes = points.slice(1).map((point, index) =>
    (point.y - points[index].y) / (point.x - points[index].x))
  const tangents = points.map((_, index) => {
    if (index === 0) return slopes[0]
    if (index === points.length - 1) return slopes[index - 1]
    const before = slopes[index - 1], after = slopes[index]
    if (before * after <= 0) return 0
    // Bound both control points to their sample interval; extrema stay at real samples.
    return Math.sign(before) * Math.min(Math.abs((before + after) / 2), Math.abs(before), Math.abs(after))
  })
  for (let index = 0; index < points.length - 1; index++) {
    const start = points[index], end = points[index + 1]
    const third = (end.x - start.x) / 3
    path += ` C ${(start.x + third).toFixed(2)} ${(start.y + tangents[index] * third).toFixed(2)}, ${(end.x - third).toFixed(2)} ${(end.y - tangents[index + 1] * third).toFixed(2)}, ${coordinate(end)}`
  }
  return path
}

function buildPath(points, baseline = null) {
  const paths = []
  let segment = []
  function flush() {
    if (!segment.length) return
    let path = segmentPath(segment)
    if (Number.isFinite(baseline)) {
      path += ` L ${segment.at(-1).x.toFixed(2)} ${baseline.toFixed(2)} L ${segment[0].x.toFixed(2)} ${baseline.toFixed(2)} Z`
    }
    paths.push(path)
    segment = []
  }
  for (const point of points) {
    if (!Number.isFinite(point?.x) || !Number.isFinite(point?.y)) {
      flush()
      continue
    }
    if (segment.length && point.x <= segment.at(-1).x) flush()
    segment.push(point)
  }
  flush()
  return paths.join(' ')
}

export function smoothLinePath(points) {
  return buildPath(points)
}

export function smoothAreaPath(points, baseline) {
  return buildPath(points, baseline)
}
