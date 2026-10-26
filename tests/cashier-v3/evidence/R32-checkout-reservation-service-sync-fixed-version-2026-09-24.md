# R32 结账项目与手艺人同步预约固定验收版本

## 验收范围

- 已结算正向销售订单完成后，沿用原有“是否结束预约”提示。
- 仅在用户选择“是”时，将本次结账产生的服务项目事实及手艺人快照关联到该会员、该门店、该业务日的全部未结束预约，再将这些预约置为已完成。
- 每条命中的预约显示同一批本次结账实际项目与手艺人；原预约计划项目、计划人员不覆盖，继续保留审计。
- 不重复生成服务、核销或业绩事实；选择“否”、作废订单及没有服务项目的结账继续沿用原流程。
- 已结束、已取消、已拒绝预约不处理；重复提交不重复关联。

## 固定版本

- 基线 HEAD：`3ecdbe47aff4060f2dce59d0e32dd62351cbc6cc`
- 业务源码、迁移与自动化测试补丁指纹（SHA-256）：`2ff447c10a6e9f88f1d45d1ee2010dc17d53d5a32b8797b1e70e3ea353181d55`
- 状态：开发与 Codex 自测完成，产品经理已确认验收，待本地提交归档。
- 本轮未部署、未 push。

## 源码与迁移文件 SHA-256

- `dee855716061fc2df2f202b44eb5ac810494e9e84494753425c3f9d0d3330d2a` `后端代码/app/services/cashier/v3/reservation/CashierV3CheckoutReservationClosureServices.php`
- `ff1eba9776dcf45e18c11a8d1affe7968350059b759596601fc9edba237cb149` `后端代码/app/services/cashier/v3/reservation/CashierV3ReservationDetailQueryServices.php`
- `a203d8d75cd25829447fc21fcd72f1ea7f42fee5c55daf9d819062cdebcf7ea9` `后端代码/app/services/cashier/v3/reservation/CashierV3ReservationPartitionProvider.php`
- `17368e9c902fd99b3057eca7e3609fb32b0e5a9e7ed8bdeeb770b0171a894dd6` `后端代码/database/upgrades/2026-09-24-结账项目同步预约实际服务/00-升级清单.md`
- `13499e5b2d90a669189b948efe55fdf099f705c32f7fbf2246bf9a316916de1d` `后端代码/database/upgrades/2026-09-24-结账项目同步预约实际服务/01-升级前检查.sql`
- `fccd4e8b37e3121886890ec30f2b48dc8aa896546d5f06daddaf41432dbb6e94` `后端代码/database/upgrades/2026-09-24-结账项目同步预约实际服务/02-正式升级.sql`
- `faa0db7634d859557a607b89d1a7faeb509c60df3e4e61e5a0963884ce37f1e8` `后端代码/database/upgrades/2026-09-24-结账项目同步预约实际服务/03-升级后验证.sql`
- `68ac4b662d932052b2c87c0cd382a74cc2a2b71d3246a0bd242f167aea19c179` `tests/cashier-v3/php/checkout-reservation-completion-contract.php`
- `7d07eece927435fb61e01236ebce60dcd1a06e90050ad67901738ce1fb0e1cd6` `tests/cashier-v3/php/checkout-reservation-completion-mysql-integration.php`

## 自测结果

- PHP 语法检查：3 个业务服务与数据库集成测试脚本全部通过。
- 静态合同测试：结账预约收口 9 项全部通过。
- MySQL 事务集成：通过；同一会员同日 2 条未结束预约均关联完整结账项目及手艺人快照并完成。
- 幂等：重复执行不产生重复关联。
- 事实隔离：服务事实、核销事实、业绩事实及预约权益占用数量均未增加或被改写。
- 作废隔离：作废订单不结束预约、不写关联。
- 展示投影：预约列表、日历和详情优先显示本次结账实际项目与实际手艺人，计划数据仍保留。
- 浏览器实测：预约 `YY2609240004` 详情成功加载；第一条结账服务稳定映射为主项目，其余为明细项目，并显示两位实际服务人员及关联销售订单。
- 回滚验证：集成测试事务回滚后关联表测试数据为 0，无测试残留。
- 回归测试：预约详情、预约权益项目、预约生命周期、列表筛选、时间窗口、房间预约投影、项目结账服务事实共 7 组全部通过。
- 差异检查：任务文件 `git diff --check` 通过。

## 本地数据库说明

- 仅在本地 `ruihao` 测试库执行迁移并验证幂等；未连接或修改瑞昊线上数据库。
- 升级键：`20260924-001-cashier-v3-checkout-reservation-service-link-v1`。
- 新表：`eb_cashier_v3_reservation_checkout_service_link`，用于保存预约与结账服务事实之间的不可变快照关联。

## 注释更新

- 三个业务服务中的关联写入、事实边界、计划/实际投影和兼容回退均补充或更新了业务注释。
- 迁移清单明确说明不回填历史、不重复生成业务事实和回滚边界。
