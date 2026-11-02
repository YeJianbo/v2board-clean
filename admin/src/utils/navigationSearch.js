export function filterNavigation(groups, keyword, translate) {
  const query = String(keyword || '').trim().toLocaleLowerCase()
  if (!query) return groups
  const label = item => item.label || (item.labelKey ? translate(item.labelKey) : '')
  return groups.map(group => {
    const title = group.title || (group.titleKey ? translate(group.titleKey) : '')
    const wholeGroup = title.toLocaleLowerCase().includes(query)
    const items = wholeGroup ? group.items : group.items.filter(item =>
      `${label(item)} ${item.routeName || ''}`.toLocaleLowerCase().includes(query))
    return {...group, items}
  }).filter(group => group.items.length)
}
