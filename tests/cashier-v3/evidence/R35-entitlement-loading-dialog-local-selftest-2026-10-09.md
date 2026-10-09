# R35 使用权益加载弹窗本地自测

- 基线 HEAD：`c0d9f7dc2253e41e21a95b56fe94f026eb9cb6ec`。
- 前端源码：`前端代码/cashier-v3/src/components/cashier/EntitlementSelectorOverlay.vue`（SHA-256 `30d0186d7f69264448cbe6e09bf8041efd7acca4de83059a0f94019f75c571ef`）、`前端代码/cashier-v3/src/styles/base.css`（SHA-256 `c307e644649d05116c7fb17a055afff841c23219afe7ff0419df579acbd92159`）。
- 自动化测试：`tests/cashier-v3/js/entitlement-selector-loading-dialog-contract.mjs`（SHA-256 `cedc4c7e55d3c1cd8f9d6d43bec00779d1d9dcad1a935f99a812369f492d8544`）。
- 后端源码、数据库迁移、工程脚本：无变更。生成物：本地 Vite `dist/`，不进入源码交付。

## 业务与数据架构自检

权益主表及后端只读选择器投影仍为权威来源；本轮只改变加载中的页面呈现，不创建或修改权益、收款、核销、库存及经营事实。事实粒度、生效事件、业务时间与操作时间、事务、并发、幂等、冲销、历史快照、门店权限、索引和报表接口均沿用现有路径。选择器请求继续受原会员和门店上下文校验，迟到响应仍由原请求 ID 与上下文范围保护；弹窗不缓存或重算权益数据。旧数据无需迁移或对账，回归检查关注请求成功、失败和上下文变化后提示是否解除。

## 验证

- `node tests/cashier-v3/js/entitlement-selector-loading-dialog-contract.mjs`：10 项通过，覆盖点击到加载状态、弹窗、返回按钮显隐、成功/失败状态。
- `node tests/cashier-v3/js/entitlement-selector-preserves-mixed-draft-contract.mjs`：通过。
- `npm run build`（`前端代码/cashier-v3/`）：通过，仅有既有大包提示。
- `git diff --check`（本轮前端文件）：通过。
- 本地 `18091` 已登录门店端：点击“使用权益”后观察到阻塞式加载弹窗，选择区“返回选购”消失；请求完成后弹窗消失、按钮恢复、权益列表正常显示。未执行权益添加或任何写命令。
- 旧综合测试 `node tests/cashier-v3/js/c2-entitlement-frontend-contract.mjs`：101 项通过、7 项失败。失败断言涉及卡类型、购物车工具条、充值赠送入口、项目替换及空购物车门禁，均非本轮修改范围；不得将此综合测试记为通过。

注释同步更新：`EntitlementSelectorOverlay.vue` 说明加载弹窗与只读请求状态绑定，`base.css` 更新选择区与加载弹窗的布局边界。此版本尚待产品经理确认验收；未 commit、部署或 push。
