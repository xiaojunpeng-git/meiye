/**
 * C1 Vue 调店／换账号调用清单（C1 只提接口；Codex 改 .vue）
 *
 * 生产唯一入口：
 *   import { onCashierStoreOrAccountChanged } from '@/services/cashierV3SessionLifecycle'
 *
 * 必须替换的页面调用点（禁止页面自行 beginCashierV3ContextSwitch / requestMeta 兜底）：
 * 1. 收银台顶栏「切换门店」确认后
 * 2. 账号切换／重新登录进入收银台工作台后
 * 3. 浏览器标签恢复导致 clientSessionId 变化时
 *
 * 调用约定：
 *   await onCashierStoreOrAccountChanged({
 *     reason: 'store' | 'account' | 'session',
 *     storeId,
 *     operatorId,
 *     silent: false,
 *     action: 'open-cashier-workbench' // 默认
 *   })
 *
 * 服务端：CashierV3Bootstrap 注册的 open-cashier-workbench 投影；
 * 响应必须带 contextSwitchServerBound + epoch/token + 新 stateContextId。
 */
export const CASHIER_V3_CONTEXT_SWITCH_CALL_SITES = Object.freeze([
  {
    end: 'cashier',
    fileHint: 'pages/**/收银顶栏或门店切换组件.vue（Codex 维护）',
    trigger: '切换门店确认',
    api: 'onCashierStoreOrAccountChanged({ reason: \"store\", storeId })'
  },
  {
    end: 'cashier',
    fileHint: '登录成功进入工作台路由守卫／壳层.vue（Codex 维护）',
    trigger: '换账号／重新登录',
    api: 'onCashierStoreOrAccountChanged({ reason: \"account\", operatorId })'
  },
  {
    end: 'cashier',
    fileHint: 'clientSession 恢复逻辑（Codex 维护）',
    trigger: '会话标识变化',
    api: 'onCashierStoreOrAccountChanged({ reason: \"session\" })'
  }
])
