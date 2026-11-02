export function constrainMapCamera(camera, width, height) {
  const scale = Math.max(1, Math.min(6, camera.scale))
  return { scale, x: Math.max(width * (1 - scale), Math.min(0, camera.x)), y: Math.max(height * (1 - scale), Math.min(0, camera.y)) }
}

export function zoomMapCamera(camera, width, height, scale, anchor) {
  const next = Math.max(1, Math.min(6, scale))
  const x = anchor?.x ?? width / 2, y = anchor?.y ?? height / 2
  return constrainMapCamera({ scale: next, x: x - (x - camera.x) * next / camera.scale, y: y - (y - camera.y) * next / camera.scale }, width, height)
}

export function moveMapGesture(gesture, width, height, center, distance) {
  const scale = Math.max(1, Math.min(6, gesture.distance > 0
    ? gesture.camera.scale * distance / gesture.distance : gesture.camera.scale))
  return constrainMapCamera({
    scale,
    x: center.x - (gesture.center.x - gesture.camera.x) * scale / gesture.camera.scale,
    y: center.y - (gesture.center.y - gesture.camera.y) * scale / gesture.camera.scale,
  }, width, height)
}

export function mapProjection(width, height) {
  const scale = Math.min(width / 360, height / 145) * 0.94
  return { scale, x: (width - 360 * scale) / 2, y: (height - 145 * scale) / 2 }
}

export function projectMapPoint(point, projection, camera) {
  return { x: (projection.x + point[0] * projection.scale) * camera.scale + camera.x, y: (projection.y + point[1] * projection.scale) * camera.scale + camera.y }
}

export function unprojectMapPoint(point, projection, camera) {
  return [((point.x - camera.x) / camera.scale - projection.x) / projection.scale, ((point.y - camera.y) / camera.scale - projection.y) / projection.scale]
}

// Cluster in screen space: zooming reveals regions without moving their locations.
export function clusterMapMarkers(groups, projection, camera, distance = 38) {
  const markers = groups.filter(group => group.point).map(group => ({ group, ...projectMapPoint(group.point, projection, camera) }))
    .sort((a, b) => a.group.code.localeCompare(b.group.code))
  const clusters = []
  for (const marker of markers) {
    // Bound every pair, so a chain of adjacent markers cannot swallow a continent.
    const cluster = clusters.find(items => items.every(item => Math.hypot(item.x - marker.x, item.y - marker.y) < distance))
    if (cluster) cluster.push(marker)
    else clusters.push([marker])
  }
  return clusters.map(items => {
    const groups = items.map(item => item.group)
    const online = groups.reduce((sum, group) => sum + group.online, 0)
    const count = groups.reduce((sum, group) => sum + group.machines.length, 0)
    return {
      key: groups.map(group => group.code).join(':'), groups, count, online,
      x: items.reduce((sum, item) => sum + item.x, 0) / items.length,
      y: items.reduce((sum, item) => sum + item.y, 0) / items.length,
      state: online === 0 ? 'offline' : online === count ? 'online' : 'partial',
    }
  })
}
