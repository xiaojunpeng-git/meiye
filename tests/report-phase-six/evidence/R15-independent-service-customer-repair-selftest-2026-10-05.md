# R15 瑞昊岗位独立核算服务人次修复自测

## 范围与原因

- 基线 HEAD：`0a38266da9cfd92c42d7fa7e49c4009c0886f580`。本闭环只修改统一服务人数读入器和对应自动化测试；不改前端、事实写入器、数据库结构或生产业务数据。
- 已结算服务快照保存 `performance_independent` 和 `allocation_group_key`，但不保存 `position_id`。旧读入器同时要求三个字段，因而把这类店长归入普通组，与其他员工平摊一人次。这是读入缺陷，并非凌晨发布未生效；线上代码与当时已发布版本一致。
- 读入时只信任结算快照中的独立核算标记和格式合法的冻结组键。若快照明确带岗位 ID，还要求它与组键一致；不按员工当前岗位设置重算历史。

## 瑞昊生产只读排查

- 目标实例：`rh.cc3798.com`，数据库 `ruihao`。全部检查均为只读，未写生产库。
- 已完成的权益服务事实共 6,294 条，覆盖 2026-08-09 至 2026-10-05、99 家门店。其中独立核算快照 321 条；296 条缺少岗位 ID，涉及 12 家门店、业务日期 2026-10-01 至 2026-10-05；其余 25 条元数据齐全，非法组键 0 条。
- 经作废过滤后，296 条中有 267 条进入当前指标读入范围。2026-10-05 04:00 后仍有 61 条缺岗位 ID，说明今天新单同样受影响。
- 只在内存中给这些历史行按冻结组键补上岗位 ID 并重新计算，不回写数据库：12 家受影响门店 10 月 1 日至 5 日的服务人次合计预计由 472.0 变为 610.0，服务人数由 436.0 变为 556.0。江苏江都店同期预计由 71.0/64.0 变为 126.0/112.0。以上是修复后的只读投影，不是已上线结果；部分缺字段行因不计人数或去重规则而不改变指标。

## 本地自测

- `independent-service-customer-allocation.php`：11 项通过，覆盖普通员工平摊、独立岗位各计 1、工资明细分配、真实 snake_case 且无岗位 ID 的已结算快照、冲突/无效岗位 ID、朋友和本人不计人数、多人同岗位分摊。
- `r51-service-customer-authority-contract.php`：8 项通过，确认工资与注册指标同源。
- `r51-market-friend-visits.php`：容器本地事务回滚测试通过，不留业务数据。宿主机 PHP 因缺少 Swoole 不可运行，改用已有 PHP 容器执行。
- `service-customer-snapshot-contract.php`：6 项通过。
- `report-phase-six/php/service-contract.php`：通过，确认薪资明细、汇总复用统一服务人数读入器。
- `salary-later-reversal-integration.php`：通过，90 条跨日期冲销事实正确排除，明细/汇总核对及 12 个分类下钻正常。
- 两个改动文件均通过 PHP 语法检查；`git diff --check` 通过。本次业务注释更新于 `ServiceCustomerMetricReadServices.php`，测试中的快照注释亦已补充。

## 固定版本与后续门禁

- 后端源码：`后端代码/app/services/query/metric/ServiceCustomerMetricReadServices.php`，SHA-256 `8aaad9bd611a1935beb1d5e59dc48679e2d7405e67289673e42319c5a659f2b6`。
- 自动化测试：`tests/cashier-v3/php/independent-service-customer-allocation.php`，SHA-256 `d10a9848b359a98776fa8ef17a90d0bdd11394efde2662a9123bdcba0ea11e8a`。
- 上述两个文件的 `git diff --binary` SHA-256：`af7644997fc87ee983bcd49e761fbbb922a9285dad86fe695460d2f13867ebb5`。
- 无前端源码、数据库迁移、工程脚本或生成物变更。本文件为验收证据，不是业务源码。
- 状态：开发与自测完成，待产品经理确认该固定版本验收。验收后再按已授权的顺序逐文件暂存、commit、备份并部署瑞昊、线上全范围只读对账、push。不得把上述投影当作线上验证。
