export const activeAgentStatus = status => ['queued', 'running'].includes(status)
export function agentApprovalSummary(items) {
  return [
    [items.filter(item => item.done && !item.undone).length, '项已批准'],
    [items.filter(item => item.undone).length, '项已撤销'],
    [items.filter(item => item.cancelled).length, '项已拒绝'],
    [items.filter(item => !item.done && !item.undone && !item.cancelled && item.expired).length, '项已过期'],
  ].filter(([count]) => count).map(([count, label]) => `${count} ${label}`).join(' · ')
}
export function agentMessages(turn) {
  return [
    { role: 'user', id: `${turn.id}:user`, runId: turn.id, text: turn.prompt },
    { role: 'assistant', id: `${turn.id}:assistant`, runId: turn.id, text: turn.reply || '', prompt: turn.prompt,
      model: turn.model, steps: turn.steps || [], running: activeAgentStatus(turn.status), failed: turn.status === 'failed',
      cancelled: turn.status === 'cancelled', error: turn.error, createdAt: turn.created_at, updatedAt: turn.updated_at,
      actions: (turn.actions || []).map(a => ({ ...a, approvals: a.approvals || [] })) },
  ]
}
export function mergeAgentRun(history, run) {
  const messages = agentMessages(run)
  for (const item of messages) {
    const index = history.findIndex(old => old.id === item.id)
    if (index < 0) history.push(item)
    else history[index] = { ...history[index], ...item }
  }
  return history
}
