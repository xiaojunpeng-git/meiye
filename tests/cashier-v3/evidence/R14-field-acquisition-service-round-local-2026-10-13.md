# R14 地推护理来源与轮次：本地自测

- 固定本地基线 HEAD：`3c293d3e1b97`。本次未提交、未部署、未改数据库；报表服务文件同时含其他任务的既有未提交改动，后续只暂存本闭环补丁。
- 变更范围：地推拓客明细表增加位于“第一次”左侧的“无轮次”护理组；护理仅取本门店会员当次来源为 E 的已完成、未作废、非体验服务，并按该次结账有效导购轮次归栏。无轮次不占第一至三轮；同轮多笔以业务日期顺序汇列。销售现金仍按原销售明细导购轮次归栏。
- 权威数据与时间：销售行取 `cashier_v3_sale_fact`；护理取 `cashier_v3_entitlement_service_fact`，来源优先读护理冻结快照，旧空快照才回退当次结账来源选择；轮次取同次结账的 `cashier_v3_customer_guide_round_fact`。护理列显示业务日期，不以写入时间或销售日期代替。成功终态、作废排除、当前门店权限和同会员隔离沿用原查询；不生成事实、修改事务、金额、索引或迁移。
- 瑞昊线上只读对账：张瑞在江苏金湖稻裕店的 `FW100500999` 为背部spa，业务日期 `2026-10-05`、实际写入 `2026-10-06 13:48:35`，当次来源选择为 **A老客首护**，不是 E；核销来源为“随心挑”，无成功作废。它不应进入 E 地推护理列。
- 本地瑞昊副本真实查询：张瑞会员 `1136096` 的两条 E 销售行均显示 `visit_none_date=2026-10-01`，`visit_1/2/3_date` 为空，A 来源 `2026-10-05` 不再出现；无轮次现金业绩原有 `1000/0` 保持。会员 `1136813` 的 E 来源第一轮护理 `2026-10-05` 保持在“第一次”；汇总首护日期为最早 E 来源护理日期。
- 验证：`php -l` 服务及两份测试文件通过；`php tests/cashier-v3/php/phase-two-report-projection-contract.php` 通过；本地 Docker 只读测试 `FIELD_ACQUISITION_LOCAL_TEST=1` 两组通过（来源、轮次、汇总、导出）；会员进店分析、异业收客明细、地推汇总只读冒烟通过；`git diff --check` 通过。无业务写入。
- 页面验证边界：本地新标签跳转登录，未操作用户正在使用的登录态；新增表头顺序与列说明由真实接口结果验证，仍待产品经理对固定版本做页面验收。

文件分组：后端源码 `后端代码/app/services/report/StoreUnifiedReportPhaseTwoServices.php`；自动化测试 `tests/cashier-v3/php/phase-two-report-projection-contract.php`、`tests/cashier-v3/php/field-acquisition-service-round-mysql-integration.php`；验收证据为本文件。前端源码、数据库迁移、工程脚本及生成物均无本次改动。

固定文件 SHA-256：

- `StoreUnifiedReportPhaseTwoServices.php`：`3b75b617318167a0f14e4c8ee556099c6ef9afb89ec63baafbe1850d76c351f9`
- `phase-two-report-projection-contract.php`：`59b4edff380bc7bcbff61840f2e1e4cc7f832efd0b30cc65f0f36f7d08f3e21e`
- `field-acquisition-service-round-mysql-integration.php`：`090196244106d75bb754822db01e4bb2303e1e181fe54a6ded16a57076f86bd5`
