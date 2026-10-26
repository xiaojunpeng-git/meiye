# R21 集团其他销售人直属岗位独立核算修复（产品经理已验收）

## 问题与结论

- 瑞昊线上员工“李世妍”存在有效集团直属岗位：`staff_job_position.staff_id=0`、岗位“光电操作师”、`performance_independent=1`。
- 原收银“其他销售人”候选和结账锁内复核只确认组织员工关系，随后把岗位固定写成“在职员工 / 非独立核算”，因此不同浏览器草稿可能显示 100% 或 50%，新事实最终也会按普通组落账。
- 本轮统一改为读取有效集团直属岗位。多人多岗位时，独立核算岗位优先，同类岗位按最早关系 ID 稳定选择；候选、普通结账、欠款补交、充值补交、订单人员调整和退款共用事务内权威结果。
- 页面布局和文案未改。已有浏览器草稿在集团人员搜索返回最新岗位时刷新分组并重做默认比例，避免旧 50% 继续掩盖独立核算。

## 代码与测试文件

- 前端源码：`前端代码/cashier-v3/src/components/cashier/PersonnelPerformanceOverlay.vue`
- 后端源码：
  - `后端代码/app/services/cashier/v3/member/CashierV3QueryEntitySelectorServices.php`
  - `后端代码/app/services/cashier/v3/cashier/CashierV3CashierWorkspaceServices.php`
  - `后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutSettlementKernel.php`
  - `后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutDraftAuthorityRebuilder.php`
  - `后端代码/app/services/cashier/v3/order/CashierV3OrderLifecycleServices.php`
  - `后端代码/app/services/cashier/v3/order/CashierV3RefundSalespersonServices.php`
- 自动化测试：
  - `tests/cashier-v3/js/r33-other-salesperson-contract.mjs`
  - `tests/cashier-v3/php/r21-organization-salesperson-direct-position-mysql.php`
- 数据库迁移、工程脚本：无。
- 生成物：仅本地执行 Vite 构建验证，`dist/` 未作为源码交付。

## 自测结果

- PHP 语法检查：3 个受影响 PHP 文件全部通过。
- `r21-organization-salesperson-direct-position-mysql.php`：6 项通过；覆盖候选、结账事务重读、退款分组、停用岗位回退及夹具回滚。
- `r33-other-salesperson-contract.mjs`：通过。
- `r34-unified-refund-other-salesperson-contract.mjs`：通过。
- 销售补交与欠款补交独立分组合同：通过。
- 门店端 `npm run build`：通过；预构建快照 70/70 通过，Vite 2012 个模块构建成功。
- 全量旧合同另有与本轮无关的既有失败：手艺人固定手工费旧断言、预约/专属手艺人资格旧断言、员工统一查询旧字段断言。本轮没有修改这些范围，也未把它们计为通过。

## 线上只读影响审计

- 审计仅执行只读事务，没有修改瑞昊线上数据。
- 当前有效的集团直属独立核算员工共 9 人。
- 岗位生效后查到 5 条相关销售人业绩事实；其中一组原销售与冲销净额为 0。
- 仍需单独评估校正的有效事实：
  - `XS26101700437`：李世妍，现存 50%，金额 15,000 元，基数 30,000 元。
  - `XS26101700439`：胡金明，现存 50%，金额 50,000 元，基数 100,000 元。
  - `TK2610160009`：章帝退款销售人事实，现存 50%，金额 -6,000 元，基数 12,000 元。
- 历史事实保持可追溯，本轮未静默改写。历史校正需另行生成冲销/更正事实并验收。

## 基线与状态

- 开始排查时 HEAD：`494d6098b51ccc53842d037e659aa0ec92911e9b`。
- 自测期间共享源码 HEAD 前进到 `6f028f157ecb179066b9cbc4bd4cf0b83e78ef51`；新提交未触及本轮 5 个代码/测试文件，复跑本轮测试和构建通过。
- 产品经理已确认本固定版本测试无问题，并授权按“本轮 commit → 部署瑞昊 → push → 部署渼约 008”的顺序发布。
- 发布冻结时状态：尚未 commit、未部署、未 push、未修改生产数据库；发布只包含本轮文件与独立核算相关补丁，不纳入同一脏工作区的其他任务改动。
