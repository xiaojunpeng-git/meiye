/**
 * Inventory quantities are whole units. Accept zero-only decimal padding from
 * database serialization, but never round a fractional value into a different
 * stock fact. Returning the fallback makes a contract violation visible.
 */
export function formatIntegerQuantity(value, fallback = '-') {
  if (value === null || value === undefined || value === '') return fallback
  const quantity = String(value).trim()
  const matched = quantity.match(/^(-?\d+)(?:\.0+)?$/)
  if (!matched) return fallback
  return matched[1] === '-0' ? '0' : matched[1]
}
