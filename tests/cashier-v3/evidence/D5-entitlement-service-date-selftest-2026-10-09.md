# D5 权益服务业务日期更正

状态：开发与自测完成，待产品经理确认验收。未提交、未部署、未 push。

## 范围与原因

- 用户要求只更正权益日期，不处理预约。
- 本地马鞍山金鹰店 `FW100401232` 的权益组标识为 `reservation:2148`，业务日为 `2026-10-04`。
- 列表已允许更正，但旧接口只接受 `CKR-...`，并强制要求普通结账请求和完成凭证；这笔合法的直接权益核销没有这些记录，因此报“记录标识无效”。
- 修复仅接受已有权威权益组，按租户、当前门店、完成事件、会员、完成状态和一致的业务日期校验与加锁。不存在或不一致的记录不能更正。
- 同一事务更新该次服务、核销、业绩及到店事实的业务日，重建旧/新日期的集团日汇总，保留更正前后审计和业务事件。
- 不查询或更新预约、预约项目、占用、排班；不重新核销、不改权益余额/有效期、数量、金额、实际发生/结算/记录时间及原请求指纹。后续独立事件保留原日期。
- 普通纯权益结账继续同步其原有请求和凭证的业务日期。混合销售仍从销售单更正，不制造结账凭证。

## 文件分组

- 前端源码：无，未调整收银页面或交互。
- 后端源码：`后端代码/app/services/cashier/v3/order/CashierV3OrderAttributionCorrectionServices.php`。
- 数据库迁移：无；无历史数据批量修复。
- 自动化测试：`tests/cashier-v3/php/entitlement-service-date-correction-integration.php`。
- 工程脚本：无。
- 构建生成物：无，本地 PHP 热更新已启用，不涉及 8080 构建或远程实例。
- 验收证据：本说明、同目录 `D5-entitlement-service-date-integration-2026-10-09.json`、`D5-entitlement-service-date-fixed-version-2026-10-09.json`、`D5-entitlement-service-date-2026-10-09.patch`、`D5-entitlement-date-ready-2026-10-09.jpg`。
- 注释：上述后端源码补充了权益事实权威、稳定锁序、原事件边界、不依赖/修改预约及不重复核销的约束；测试注释说明本地环境限制、合成夹具和强制回滚。

## 自测

全部通过：

1. PHP 语法检查。
2. 本地 MySQL 集成测试 51 项：无预约/请求/凭证的合成权益组可更正；多服务行、核销、人员业绩及生命周期仅业务日变化；后续独立业绩日期不变；旧新日汇总与事实一致；审计留痕、幂等重放、旧值冲突、跨门店/租户、只读/无功能权限、错误标识、无效/未来日期及不一致状态拒绝；写入后事件失败可整体回滚；列表新日期读取正确；普通 CKR 全链回归。
3. 集成测试显式限制本地 Docker、`mysql` 主机及 `ruihao` 数据库；使用合成事实，所有写入强制回滚。回滚后核对合成事实、请求、审计无残留，原 FW、预约/占用/排班、权益余额和日汇总保持一致。更正审计事件按既有合同无异步消费者，不产生 Outbox 任务。
4. `node tests/cashier-v3/js/order-attribution-command-context-contract.mjs`：6 类更正命令桥接通过，没有数据库写入。
5. `node tests/cashier-v3/js/headerless-query-actions-contract.mjs`：通过。
6. `git diff --check`：通过。
7. 本地真实页面只读打开该 FW 详情，再恢复日期窗口及用户输入 `2026-10-01`，没有点击“确认更正”。截图只证明待验收窗口，不冒充真实提交成功；实际业务日仍为 `2026-10-04`。

执行集成测试：

```sh
docker exec -i -e D5_LOCAL_TEST=1 -w /var/www/html mohe-cashier-app php < tests/cashier-v3/php/entitlement-service-date-correction-integration.php
```

## 固定版本

- 任务开始基线：`9c8f5a4b7a164ae71d0f36cd794256b6059ae0b7`；期间其他任务推进 HEAD，未改写或撤销其成果。
- 固定时 HEAD：`67e25e56d5a3e6960c36b13f77aea41663956c23`。
- 后端文件 SHA-256：`9740de147f5c2251c37f2b8fe23dd2072563c05b532b2db2c87e5bc3af78392b`。
- 测试文件 SHA-256：`c0aa97827a2eec4279000d62863810b9250f6e68f159cc26b11d6877a1164f11`。
- 源码及测试补丁 SHA-256：`bfeab9f78a7c76197fc808096eec34a8e1c338d2485db82fc938186270bed867`。
- 保留其他任务原有脏文件及暂存区 9 个文件，本轮未暂存任何文件。

## 待验收

请产品经理在本地订单中心用该 FW 日期入口验收。仅本轮已列明范围和固定指纹属于此次交付；尚无产品经理验收结论，不创建 commit，不连接或改动瑞昊线上，不执行部署或 push。
