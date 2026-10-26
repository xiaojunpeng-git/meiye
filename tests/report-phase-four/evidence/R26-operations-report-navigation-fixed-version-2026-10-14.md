# R26 运营中心数据报表切换：本地固定版本（2026-10-14）

## 问题与修正范围

- 瑞昊线上 `/admin/report/operations-center/operations_pre_sale_bdegh` 中，点击“顾客状态表（BDEGH）”后，外层 URL 变为对应地址，但 iframe 仍指向 `/platform/reports/six_dimension_item_deal_analysis`，显示“品项成交分析表”。这解释了运营中心各入口看起来都没有切换。
- 原因是平台端共用框架 `six_dimension.vue` 的 `resolveReportCode` 只接受六维报表代码；任何第四阶段运营报表代码都回退到六维第一项。当前唯一源码工作区已有尚未提交的定向修正：将已注册的第四阶段代码并入框架允许集，标题也从同一注册表取值。本轮沿用这一项现有改动，没有覆盖同文件的其他内容。
- 本轮不改报表事实、指标、统计时间、权限、查询、合计、下钻或导出；仅修复平台导航选择目标报表。瑞昊线上尚未修改，其他实例也未部署。

## 固定版本

- 开始核对时工作区 HEAD 为 `f8f57b52c8375a5a588d0c0c375335357127ec01`，收尾时被并行任务推进至 `7638733e33f471fd077e70e99001863fac8623c0`；两个提交里的目标文件 blob 均为 `7242e957fb3960821d2026eca7a6ed4facf534ee`，不影响本闭环补丁。闭环前端源码仅 `前端代码/admin/src/pages/report/data/six_dimension.vue`，SHA-256 `e188baad8af62adc5bc563c166d24ab60fca3d21d349a4cafb980b6092ca5872`；相对当前目标文件基线的补丁 SHA-256 `af726141acafe10b57c7b9a8490fcc2e1af240fe9bd392dbc8fcaa5c248d6337`。
- 自动化测试仅 `tests/report-phase-four/js/platform-frame-route-contract.mjs`，SHA-256 `a9030adc0edbd5f4875244b6629684f3b3547bb527c7062a1220cfee713ce86a`。后端源码、数据库迁移、工程脚本：无。
- 生成物：本地平台构建输出至 `/private/tmp/r26-admin-report-frame-build-20261014/`，`system.html` SHA-256 `d8c25ece9adc27e2f91b58029d356d57918347b6480eb85fb7389e3cb68612eb`；该构建包含工作区其他未提交改动，**不是可直接上线的隔离发布包**。正式发布需基于已验收且仍在线的统一平台版本，只叠加本闭环文件差异重建并核对指纹。

## 自测

- 新定向合同测试：PASS，检查注册表确实合并，并覆盖 6 张原六维报表和 27 张第四阶段运营/跨端报表的路由代码不回退。对第25轮平台发布源码运行同一测试，因未合并第四阶段注册表而失败；补强断言前的解析函数对照还确认首项 `operations_pre_sale_bdegh` 实际返回 `six_dimension_item_deal_analysis`，与线上复现一致。
- 本地 18081 页面：从“售前报表（BDEGH）”切到“顾客状态表（BDEGH）”、品牌营销售前报表、产品变现六维业绩，外层 URL 与 iframe `/platform/reports/<code>` 均各自匹配。未写入业务数据。
- 平台 `npm run build -- --dest /private/tmp/r26-admin-report-frame-build-20261014`：PASS；仅有已有浏览器数据过期与包体大小告警。`git diff --check`、测试脚本 `node --check`：PASS。
- 原 `tests/report-six-dimension-frontend/js/contract.mjs`：有 5 项旧静态断言失败，均指向未纳入本闭环的门店报表组件；其余断言通过。本轮不将该整体测试标记为 PASS，不顺带修改门店报表代码。

## 数据架构自检与交付门禁

- 本轮是平台前端路由映射修正，未新增或修改业务事件与事实粒度、业务日期、事务/并发/幂等、冲销、维度快照、后端数据权限、聚合和索引。选中代码继续交给既有 Vue 3 统一报表运行时；其权威事实、权限及导出接口不变。对账方式是核对外层报表代码、iframe 代码和页面标题一致，不以零数据或 HTTP 200 冒充业务口径验收。
- 注释：沿用 `six_dimension.vue` 中解释共享框架和防止错报表回退的业务边界注释；新增测试注释说明注册代码不得静默退回六维第一项。
- 状态：产品经理已明确确认上述固定版本验收通过，并单独授权本轮提交、部署瑞昊与渼约008及验证后 push。提交前瑞昊线上仍可复现问题；发布结果另行逐实例记录。
