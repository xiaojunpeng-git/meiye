# R9 员工薪资售前业绩固定版本（2026-10-11）

## 范围与版本

- 产品确认：销售人逐人选择“售前”后，仅其销售分配归入独立“售前业绩”；不重复进入商品分类。退款按退款时保存的销售人标记在退款业务日扣减；服务与售前销售混合时分开展示。
- 基线 HEAD：`0c870d08fe524425cd82bed78459330bd02fa48f`。
- 五个闭环文件的 SHA-256 清单串联指纹：`f9c2372fd6bb2e939b9f46b39c70ba86c0ea9a593aab9e9c64b723fdb196a995`。
- 后端源码 `后端代码/app/services/report/StoreUnifiedReportPhaseSixServices.php`：`4b13a18daa086d625036370e848e7cffe624d11824d0c4144f985245c5701bbe`。
- 新增测试 `tests/report-phase-six/php/r9-salary-presale-readonly.php`：`7ddcbe04abd1b1969b4ac0061777f7d21152616546c484f9fded2b2f62b49cf2`。
- 回归测试 `tests/report-phase-six/php/salary-later-reversal-integration.php`：`f656042a988c743ad493012fbfd980e293a6def4104865755bde49772f61a06b`。
- 合同测试 `tests/report-phase-six/php/service-contract.php`：`e23af1711926d1d53fceeb84e79d3ccab6141af65b0da80ab3e3821fe53adf0e`。
- 现行分类回滚测试 `tests/report-phase-six/php/d3-salary-current-category-rollback.php`：`6074e363ca37d7ce8d18724072c0f4ca87737cf8a5718eae24bf30a1aec73f7f`（仅更新新报表口径版本的合同断言）。
- 前端源码、数据库迁移、工程脚本、生成物：无。页面使用已有后端列契约显示新列。

## 数据架构自检

- 权威源是有效的逐人 `sales_performance_allocated` 与 `refund_sales_deduction` 业绩事实。只读取事实上的 `role_snapshot=salesperson:presale`；不按顾客身份、员工岗位或商品名推断。事实最小粒度、成功时点、发生时人员快照、原有门店权限和退款业务日均不改变。
- 售前与普通分类互斥。售前事实的金额不再参与商品分类分摊；同员工同销售行的服务事实保留商品分类、项目数、消耗及手工费。汇总、明细、合计、下钻和完整导出走同一个后端读取器。
- 原事实、业务主表、事务、幂等、冲销链和历史快照均不写入或回填。负向退款事实按本次退款自己的销售人角色归列，原销售额不改写；无售前角色的历史事实保留原分类。
- 报表继续由控制器提供许可门店，精确下钻要求服务端门店 ID 与员工 ID；没有扩大权限。分类配置仍按既有现行商品分类规则读取，商品索引和查询范围不改变。只增加两个小字段的读取，不增加跨表关联。
- 对账条件：每员工售前列加所有商品分类列等于该员工明细现金业绩（分精度）；作废或被冲销的事实不进入查询。汇总与导出同口径。口径版本为 `report-phase-six-v2:salary-presale-allocation-v1`。

## 自测

- 本地 PHP 7.4 与主机 PHP 8.5 语法检查通过；`git diff --check` 通过。
- `r9-salary-presale-readonly.php` 通过：真实本地样本验证售前 99 元只计一次，服务同业务行拆分后项目数与消耗仍在原分类；普通销售保留商品分类；售前下钻与完整导出相等；负向退款分桶及越权下钻边界通过。
- `salary-document-column-integration.php` 通过：2 条事实的汇总、明细和导出契约。
- `salary-metric-drilldown-integration.php` 通过：32 个服务指标下钻。
- `salary-later-reversal-integration.php` 通过：432 条跨日冲销事实排除，68 个金额下钻对账；测试求和同步包含售前列。
- `service-contract.php` 通过：第六阶段报表后端合同。浏览器刷新后，汇总表头已显示独立的“售前业绩”列，位于“商品分类业绩”左侧。
- 既有 `d3-salary-sale-category-readonly.php` 的固定本地样本现已漂移：其“恰有四条指定销售维度”的前提不成立，因此未将其运行结果计入通过；本轮新用例不依赖该前提。
- `d3-salary-current-category-rollback.php` 的现行分类事务测试通过了前九项并按 `finally` 回滚；后续“指定两张历史销售单各一行”断言因当前本地仅剩一行而中止，未计入通过。该固定样本与本轮售前变更无关，需单独刷新夹具。
- 退款目前没有可用的本地售前负向业务样本，已用签名金额的纯投影边界测试验证；未在线上执行真实退款。

## 注释与交付边界

- 更新了薪资口径版本注释、逐人售前分桶和混合服务拆分说明；新增的角色判断方法注释明确只接受已保存的人员角色。
- 当前为本地开发与自测完成，待产品经理对以上固定版本确认验收。未 commit、未部署、未 push；无生产数据操作。
