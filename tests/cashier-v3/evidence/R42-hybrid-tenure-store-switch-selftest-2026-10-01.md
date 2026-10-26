# R42 兼任门店员工的跨店切换：本地自测与待验收版本

## 固定版本

- 源码基线 HEAD：`d92a9edcd960d21c3bbd549d9e971a3cd0c5729d`（`release/store-cashier-c14-main`）。
- 已跟踪文件补丁 SHA-256：`84d293459cf6afc02e96628e69e135eac7df6ce03e78f86e1d27e9daaaca1f7c`；新测试文件独立指纹见下表。
- 本闭环尚未取得产品经理对下表固定文件版本的验收；未提交、未部署、未 push。工作区其他脏文件不在本闭环范围。

| 分组 | 文件 | SHA-256 |
|---|---|---|
| 后端源码 | `后端代码/app/services/cashier/v3/CashierV3StoreLoginServices.php` | `f701243b080166103ebb4cbbac780a4dc3d2f84a95bcd7bc0f4fbe998995e0e0` |
| 后端源码 | `后端代码/app/services/cashier/LoginServices.php` | `e18ae29b8208f8e78550c942a67c8542fbd602cdf9c7ec4321a55099820dd28a` |
| 自动化测试 | `tests/cashier-v3/js/store-switch-contract.mjs` | `a52a7cf0cf6ab5faee46c56ea3313095cd3f037cdbf9413a4dabaaec70c8c8b1` |
| 自动化测试 | `tests/organization-store-v3/js/single-store-tenure-contract.mjs` | `945e694bd4033b00c7e1c16a52ed56d4ff528dcc5a07779f886504fd8e0f9a67` |
| 自动化测试 | `tests/organization-store-v3/js/store-v3-login-entry-contract.mjs` | `43f1dc40765a091b19df99651159fd6681ed70d15d281cd363c9dac7af6d964c` |
| 自动化测试 | `tests/organization-store-v3/php/hybrid-tenure-store-switch-contract.php` | `c96a495a3aabc9a24bb178ed3389ef5e72dfcc61ba253f10046640b86ad4f802` |

前端源码、数据库迁移、工程脚本、生成物：无变更。本文件属于验收证据，不是业务源码；本轮更新的业务注释在上述两个后端服务文件中。

## 业务范围与验证

1. 首次登录保持原逻辑：有唯一有效门店任职就直入任职店，不显示登录选店弹窗。
2. 切店候选另行实时计算：原任职店保留，其他店必须同时通过组织直属岗位的门店端入口、人员数据权限和门店有效状态；只有两个及以上候选才显示现有切店按钮。异常或无入口任职不借组织权限绕过登录限制。
3. 切去其他门店签发 `store_organization` 会话，仅使用组织岗位功能权限；切回原店签发 `store_staff` 会话，恢复任职权限。当前店、目标店都由服务端验证，不采信客户端门店范围。
4. 本地事务性集成测试使用本地库员工 657、岗位 70、集团组织 110 临时补齐测试授权，得到 `direct_login_store=128 organization_targets=101 round_trip=1->128`；测试令牌撤销且事务回滚。复核回滚后候选、临时组织岗位与总部数据范围均为 0。
5. `store-switch-contract` 7/7、`single-store-tenure-contract` 16/16、`store-v3-login-entry-contract` 16/16；两个业务服务和新 PHP 测试语法检查、`git diff --check` 均通过。未使用线上账号创建或切换会话，线上页面仍为旧行为。

## 数据架构自检

- 权威源：`system_store_staff` 的唯一有效任职、组织直属岗位与 `job_position_channel_rule`、`employee_data_scope`、有效门店状态；服务端逐次重算。前端只展示候选、传目标 ID。
- 事实与时间：切店仅更换会话上下文，不产生订单、库存、资金或经营统计事实；无统计时点、聚合、历史快照或冲销变化。新旧会话及工作台上下文不能跨店沿用。
- 事务／并发／幂等：目标签发时重新校验候选和实际任职行；原客户端仅在新工作台成功装载后撤销旧会话，失败恢复原令牌。没有新增业务写命令或幂等键。
- 数据权限：其他店使用独立组织会话，不能借用原店任职权限；组织岗位、数据范围、门店有效状态任何一项撤销后不再作为切店候选。当前会话门店始终与目标门店分离，客户端不能扩大范围。
- 性能与兼容：复用既有 `eligibleStores` 授权查询和选店树，无新表、索引或迁移；无任职组织用户和仅单店任职用户保持原行为。本地集成及静态合同已覆盖核心分支，产品经理页面验收和线上实测尚未完成。
