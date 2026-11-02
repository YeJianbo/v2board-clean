export function findNodeForEdit(nodes, key) {
  return nodes.find(node => `${node.storageType}:${node.rawId}` === String(key)) || null
}
