export function assignmentAmountCents(value) {
  const amount = Number(value)
  const cents = Math.round(amount * 100)
  if (!['number', 'string'].includes(typeof value) || String(value).trim() === '' || !Number.isFinite(amount) || amount < 0) {
    throw new Error('请输入非负金额；免费分配请明确填写 0')
  }
  if (!Number.isSafeInteger(cents) || cents > 2147483647) throw new Error('金额超出订单存储上限')
  return cents
}
