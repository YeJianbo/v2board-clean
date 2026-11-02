export function parseNodeRate(value) {
  if (value === null || value === undefined || value === '') return 1;
  if (!['number', 'string'].includes(typeof value)) return NaN;
  const text = String(value).trim().replace(/x$/i, '');
  return text === '' ? NaN : Number(text);
}

export function validateAccountingRate(value) {
  const rate = parseNodeRate(value);
  if (value === null || value === undefined || String(value).trim() === '' || !Number.isFinite(rate) || rate < 0 || Math.round(rate * 100) / 100 > 99999999.99) {
    throw new Error('节点倍率须为非负有限数值，保留两位小数后不能超过 99999999.99');
  }
  return rate;
}

export function validateNodeRate(value) {
  const rate = validateAccountingRate(value);
  if (String(rate).length > 11) {
    throw new Error('节点倍率的保存值不能超过11个字符');
  }
  return rate;
}

export function validateRateSchedule(enabled, rules, baseRate) {
  if (!enabled) return [];
  if (!Array.isArray(rules) || rules.length < 1 || rules.length > 32) {
    throw new Error('动态倍率须设置1至32条时间段规则');
  }
  const intervals = [];
  const normalized = Array.from(rules).map((rule, index) => {
    const rawStart = rule?.startTime ?? rule?.start;
    const rawEnd = rule?.endTime ?? rule?.end;
    const start = typeof rawStart === 'string' ? rawStart.trim() : '';
    const end = typeof rawEnd === 'string' ? rawEnd.trim() : '';
    const clock = /^(?:[01]\d|2[0-3]):[0-5]\d$/;
    if (!clock.test(start) || !(clock.test(end) || end === '24:00')) {
      throw new Error(`规则${index + 1}的时间须为HH:mm，结束时间可为24:00`);
    }
    const minute = value => Number(value.slice(0, 2)) * 60 + Number(value.slice(3, 5));
    const a = minute(start), b = minute(end);
    if (a === b) throw new Error(`规则${index + 1}的起止时间不能相同，全天请设00:00至24:00`);
    const rate = validateAccountingRate(rule?.rate);
    validateAccountingRate(validateAccountingRate(baseRate) * rate);
    if (a < b) intervals.push([a, b]);
    else { intervals.push([a, 1440]); if (b > 0) intervals.push([0, b]); }
    return { start, end, rate };
  });
  intervals.sort((a, b) => a[0] - b[0]);
  let end = -1;
  for (const [start, stop] of intervals) {
    if (start < end) throw new Error('动态倍率时间段不能重叠');
    end = stop;
  }
  return normalized;
}
