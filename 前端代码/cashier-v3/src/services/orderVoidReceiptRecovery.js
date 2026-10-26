/**
 * 整单作废遇到断连时只用原命令追查一次。服务端幂等回执返回已提交结果；
 * 绝不生成新请求号或修改内容，二次仍异常则保留未知状态由原操作继续恢复。
 */
export async function requestOrderVoidReceipt(adapter, action, payload, isTrusted) {
  try {
    const result = await adapter.request(action, payload)
    if (isTrusted(result)) return result
  } catch (_) {
    // 首次传输失败不代表事务失败；同键重放不会重复退权益。
  }
  return adapter.request(action, payload)
}
