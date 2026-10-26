// Preserve the last valid draft when a refund field receives punctuation or
// other non-digits; removing characters could silently increase the amount.
export function nextWholeYuanInputValue(previous, candidate) {
  return /^[0-9]*$/.test(candidate) ? candidate : previous
}
