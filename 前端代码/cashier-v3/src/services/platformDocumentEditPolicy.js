import { cashierV3ActionDefinition } from './cashierV3ActionManifest.js'

// 仅影响平台单据；打印与导出虽建立任务回执，但不修改原单。后端仍逐次权威校验。
export const PLATFORM_DOCUMENT_READ_COMMANDS = new Set(['print-sales-order-receipt', 'create-unified-query-export'])
export const PLATFORM_DOCUMENT_READ_ONLY_MESSAGE = '平台端已关闭修改单据，仅可查询、查看详情、打印和导出。'
export function platformDocumentActionAllowed(action, editable) {
  if (editable) return true
  const definition = cashierV3ActionDefinition(action)
  return Boolean(definition && (definition.type === 'projection' || PLATFORM_DOCUMENT_READ_COMMANDS.has(definition.canonical)))
}
