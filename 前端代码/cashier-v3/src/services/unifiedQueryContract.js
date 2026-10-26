export * from '@mohe/unified-query-vue3/contract'

import { extractUnifiedQueryProjection } from '@mohe/unified-query-vue3/contract'

export function extractUnifiedQueryMemberProjection(raw) {
  return extractUnifiedQueryProjection(raw, ['memberCenter', 'member_center'])
}
