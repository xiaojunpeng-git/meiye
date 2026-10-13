# R19 欠款卡退款实际剩余金额与上限本地自测

## 固定版本

- 基线 HEAD：`b2a32e2fc67dc4d0f952ce47808b268fd4fe8b2d`
- 三份已跟踪源码/契约文件的 `git diff --binary` 补丁 SHA-256：`9fb42399dbaab747fc838d181bc97d50168e8d199120b3c50ac2ce216353ed2e`；新增回滚测试按下方文件哈希单独核对。
- 范围：仅本地统一源码与本地回滚测试；未提交、未部署、未推送、未修改线上数据。
- 前端源码：`前端代码/cashier-v3/src/views/OrderCenterView.vue`，SHA-256 `d13dacbbfd31872efd9d628a67f74f929f5e3f7b9429f83ea9806acbfcdd6903`
- 后端源码：`后端代码/app/services/cashier/v3/order/CashierV3UnifiedRefundServices.php`，SHA-256 `6fd5f0dbfaededb07a2503418594cdd40167a0176d4b7e89598dbd674ef394f2`
- 自动化测试：`tests/cashier-v3/js/r26-refund-page-gateway-contract.mjs`，SHA-256 `6678b95aba65cfe051720d35825c3b12d76436a3d795d6b383bfedbc0cae9131`；`tests/cashier-v3/php/r19-refund-debt-usable-rollback.php`，SHA-256 `ceff68567e3a439b94f7e8148e63c8f6fc8d7b3eb34223c87d1fa03c2e639254`
- 数据库迁移、工程脚本：无。生成物：Vite 本地 `dist/`（不纳入源码清单）。验收证据：本文件。

## 业务口径与数据边界

- 权威来源仍为已售卡权益行、来源订单和欠款/取消欠款事实；不改历史金额、退款事实或卡项记录。
- 卡项原始未使用金额保留用于历史和退款冲销；退款候选展示及提交上限另用与核销选择器一致的欠款调整后金额。
- 未结清欠款仍禁止退款；取消欠款不释放被取消的权益金额；真实补交后按重新读取的权益金额计算。提交在原事务内重新读取并校验，不能只依赖前端金额。
- 无跨客户数据或配置改动；本地回滚测试的临时状态在同一事务回滚。金额以分计算，页面按元展示。
- 本次补充/更新业务注释的文件：上述 PHP 服务和 Vue 页面。测试中的注释说明本地回滚夹具及不可持久化边界。

## 验证

- 本地浏览器真人查看会员张瑞莲的 `z007WZH`：成交 ¥3,724、实际剩余 ¥1,000、仍显示欠款禁退提示，卡项不可选择；未提交退款。
- `r19-refund-debt-usable-rollback.php`：未结清 ¥2,724 时禁退；模拟取消后实际剩余/上限仍为 ¥1,000，拒绝 ¥1,001；模拟全部补交后上限为 ¥3,724；事务回滚，通过。
- `r26-refund-page-gateway-contract.mjs`、`r26-card-refund-quota.php`、两份 PHP 语法检查、定向 `git diff --check`：通过。
- 门店端 Vue 源码容器 `npm run build`：通过，Vite 仅报告既有的大 chunk 提示；构建源为本地 `前端代码/cashier-v3/`，对应 18091 热更新源码，不是旧 8080 产物。
- R26 完整退款回滚脚本在现有员工测试夹具权限检查处停止，未执行到退款逻辑，不记为通过；本轮未执行真实退款或线上验证。

待产品经理对上述固定版本确认验收后，按项目规则提交本闭环；部署和 push 需要另行明确授权。
