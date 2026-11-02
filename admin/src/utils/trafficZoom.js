export function timelineWidth(viewport, count, zoom, bars) {
  return zoom === null ? Math.max(viewport, bars ? 82 + count * 14 : 0) : Math.max(viewport, viewport * 2 ** (zoom / 20))
}
export function zoomScrollLeft(left, oldWidth, newWidth, viewport) {
  if (oldWidth - viewport - left < 2) return Math.max(0, newWidth - viewport)
  const center = (left + viewport / 2) / oldWidth
  return Math.max(0, Math.min(newWidth - viewport, center * newWidth - viewport / 2))
}
