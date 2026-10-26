/**
 * The member summary is a read-only display hint for a browser-owned checkout.
 * Never reuse another member's balance or treat this preview as debit authority;
 * final checkout re-reads and locks the account in its transaction.
 */
export function checkoutBalancePreview(member, checkoutMemberId) {
  const memberId = Number(member?.id ?? member?.memberId ?? 0)
  if (!Number.isSafeInteger(memberId) || memberId <= 0 || memberId !== Number(checkoutMemberId)) {
    return { balanceAvailable: false, availableBalance: 0 }
  }

  const amount = String(member?.accountBalance ?? '').trim()
  const match = /^(\d+)(?:\.(\d{1,2}))?$/.exec(amount)
  if (!match) return { balanceAvailable: false, availableBalance: 0 }
  const cents = Number(match[1]) * 100 + Number((match[2] || '').padEnd(2, '0'))
  if (!Number.isSafeInteger(cents) || cents <= 0) {
    return { balanceAvailable: false, availableBalance: 0 }
  }
  return { balanceAvailable: true, availableBalance: cents / 100 }
}
