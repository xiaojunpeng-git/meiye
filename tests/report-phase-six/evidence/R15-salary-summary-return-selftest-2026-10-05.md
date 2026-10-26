# R15 员工薪资汇总下钻返回本地自测

- 基线 HEAD：`e42ed6ddcffda6e7ca4b1a6696da02117857498b`。
- 范围：汇总指标下钻到薪资明细时，保存原汇总筛选；明细页增加“返回”按钮；点按钮或汇总页签均按原条件返回并重新查询一次。明细保留筛选能力，直接打开明细时不显示返回按钮。
- 前端源码：`前端代码/cashier-v3/src/views/StoreBusinessReportView.vue`，SHA-256 `95fd63b0daa77f534482aed6ace57f9d4361f215d06dc26c4b5ba5b4f03bdb8b`。
- 自动化测试：`tests/report-phase-six/js/contract.mjs`，SHA-256 `cb902f2278297687134c1c4ae8289ee4609d71e0347b31b53132e79e9ee44c57`。
- 本闭环跟踪补丁指纹：`e36be8347d2a9f9641585dc038e89e923bb8609bd7e4cfecb86b6f9e658f28a8`。
- 后端源码、数据库迁移、工程脚本：本闭环无。生成物：`cashier-v3/dist/` 构建产物，不作为源码提交。验收证据：本文件。

## 验证

- `node tests/report-phase-six/js/contract.mjs`：通过，含下钻返回上下文保留和返回元数据不进入报表请求的契约检查。
- `node tests/cashier-v3/js/store-business-report-contract.mjs`：44 通过，0 失败。
- `node tests/cashier-v3/js/store-report-period-picker-contract.mjs`：通过。
- 在 `cashier-v3` 执行 `npm run build`：通过；现有包体积提示不影响构建。
- Vue Router 内存路由回合测试：汇总条件 `2026-10-05` 和员工筛选经明细 URL 返回后完整恢复。
- `git diff --check`：通过。
- 已登录本地浏览器标签的自动化接管连续超时；新独立标签没有登录态，故未完成真人页面点击和网络请求次数验证。该项待产品经理在固定版本上验收，不能以静态契约代替业务验收。

## 数据架构自检

- 权威源、事实粒度、业务事件、四类时间、事务、并发、幂等、冲销、历史快照、数据权限、索引与对账：本闭环仅改变前端报表导航，均不变；报表查询继续由同一后端接口按后端权限和口径执行。
- 返回地址只接受当前运行端的员工薪资汇总路由，不能借 URL 元数据跳往其他页面。返回元数据不作为报表筛选发给服务端。
- 新增/更新业务注释文件：`前端代码/cashier-v3/src/views/StoreBusinessReportView.vue`，说明返回目标边界、明细再筛选时保留上下文以及路由触发单次重新查询。

状态：本地实现与自动化自测通过；真人页面路径及产品经理固定版本验收未完成；未 commit、未部署、未 push。
