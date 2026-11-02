export function dashboardOperationalStats(summary, t) {
  const stats = [
    {
      key: 'nodes',
      label: t('trafficOverview.onlineNodes'),
      value: String(summary?.onlineNodes ?? 0),
      note: summary?.totalNodes == null ? '' : `共 ${summary.totalNodes} 个节点`,
      metric: 'online-nodes',
    },
    {
      key: 'users',
      label: t('trafficOverview.onlineUsers'),
      value: String(summary?.onlineUsers ?? 0),
      note: `${t('trafficOverview.onlineDevices')} ${summary?.onlineDevices ?? 0}`,
      metric: 'online-users',
      noteMetric: 'online-devices',
    },
  ];
  if (summary?.todayIncomeAvailable) stats.push({
    key: 'today-income', label: '今日收入', value: summary.todayIncome,
    note: summary.todayIncomeGrowthText || '',
  });
  if (summary?.monthIncomeAvailable) stats.push({
    key: 'month-income', label: '本月收入', value: summary.currentMonthIncome,
    note: summary.monthIncomeGrowthText || '',
  });
  return stats;
}
