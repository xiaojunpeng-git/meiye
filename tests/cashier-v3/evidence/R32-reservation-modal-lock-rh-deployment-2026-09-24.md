# R32 预约编辑弹窗解锁瑞昊部署记录

## 固定版本与范围

- 产品经理已验收本地固定版本，并明确授权 commit、部署瑞昊和 push。
- 本轮业务提交：`dbc93e3f29943bd6a3a9fd2b677b8651ac587ca2`（R32 预约编辑弹窗解锁闭环）。
- 目标仅为瑞昊 `rh.cc3798.com`；`007`、`008`、`012` 未连接、未部署。
- 本次只替换门店端 `view_cashier_v3` 静态包，未修改后端、数据库、配置、Swoole 或历史业务数据。

## 上线内容

- 从预约详情进入编辑时，先完整卸载详情弹窗，再挂载编辑弹窗，防止编辑弹窗继承详情弹窗的临时 `inert` 状态。
- 通用弹窗背景锁改为全局共享持有者计数，最后一个弹窗关闭后才恢复原始 `inert` 和 `aria-hidden`，兼容 Vite 热更新的多模块实例。
- 预约编辑准备失败时重新打开原预约详情，不丢失用户当前上下文。

## 自测与构建

- `reservation-lifecycle-frontend-contract.mjs`：PASS。
- 门店端 Vite 生产构建：PASS，处理 1972 个模块；仅有既有 chunk 大小提示。
- 本地真实页面：预约详情 → 编辑 → 关闭后，`#app` 的 `inert` 和 `aria-hidden` 均已恢复，同一预约可无刷新再次打开。
- 发布包集合 SHA-256：`7aadf2675381777216f44748c3ee9a7747523a8788a476dfe0e1b59855dd4530`。
- `index.html` SHA-256：`ebfd11afff8e56f529736f07369714f737fe8d5ea69133e58e55e6579fe43275`；主 JS 为 `index-4d02dbb8.js`。

## 备份与回滚

- 独立备份目录：`/www/backups/rh.cc3798.com/20260924-123245-r32-dbc93e3f-reservation-modal-lock`。
- 主入口切换前压缩备份：`4592f30aa3a9e417c5168f54b4f421561828c873f60eeb624cb67b66626ad0d8`。
- 8080 预览入口切换前压缩备份：`f15fc1042ce3ad0757c62be682fffc2dfd7c0cc213c2524e3ec48d31f2416bac`。
- 备份目录同时保留 `main-live-before` 和 `preview-live-before` 两个完整运行目录；回滚时只恢复对应静态目录，无需改库或重启后端。

## 线上验证

- 主入口 `/view_cashier_v3/?release=dbc93e3f#/reservation`：HTTP 200。
- 8080 预览入口 `/preview-8080/view_cashier_v3/?release=dbc93e3f#/reservation`：HTTP 200。
- 两个入口的文件集合 SHA-256 均为 `7aadf2675381777216f44748c3ee9a7747523a8788a476dfe0e1b59855dd4530`，与本地完全一致。
- 两个入口的 `index-4d02dbb8.js` 均 HTTP 200，并包含新的 `mohe.cashierV3.modalBackgroundLocks` 修复标识。
- 瑞昊已登录真实页面中，打开并关闭预约详情后，`#app` 的 `inert` 已清除、`aria-hidden` 已恢复。
- 线上当日可见预约均已结束，为避免修改生产预约，未执行线上编辑保存；完整“详情 → 编辑 → 关闭 → 再次点击”由产品经理本地验收和 Codex 本地真实页面回归覆盖。
