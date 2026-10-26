# R13 任职人员数据权限切店本地自测

## 固定版本与边界

- 基线 HEAD：`a402ed568572adcd6b9e821dd861db803976a390`
- 本轮补丁 SHA-256：`0f63438c42195909e438fb99cd894482b4fbf3015f938504d865ccc7eaa2e8b6`
- 仅本地源码与事务性本地测试；未提交、未部署、未推送、未修改线上库。
- 前端不改动：现有门店切换按钮按服务端返回至少两家门店自动显示。首次登录直入唯一任职门店的分流、位置规则均未修改。

## 文件清单与 SHA-256

| 分类 | 文件 | SHA-256 |
| --- | --- | --- |
| 后端源码 | `后端代码/app/services/cashier/v3/CashierV3StoreLoginServices.php` | `18f5e46e050398ee31b383b9e3633557238015b3cb0a4f15f38896fd9e008272` |
| 后端源码 | `后端代码/app/services/cashier/v3/permission/CashierV3FeatureResolver.php` | `c1fbd05fe9812501a5aee8c240da52eacac46a699926a67e74840d85f3127de3` |
| 后端源码 | `后端代码/app/services/cashier/LoginServices.php` | `e8c5964abbe9618a2475d54bff9e5848a5302c012855629da68543e7390d4152` |
| 后端源码 | `后端代码/app/services/cashier/v3/CashierV3PermissionSnapshotServices.php` | `a0ff91a02052aeb7166d19a690568ace4ea21de77d32ef20dc38b96e4d002542` |
| 后端源码 | `后端代码/app/services/cashier/v3/CashierV3DataScopeFactory.php` | `88432b86de5c226eb83300ddd3b81c4f337f11107f4b2d98c1ea957c46641a6e` |
| 自动化测试 | `tests/organization-store-v3/php/hybrid-tenure-store-switch-contract.php` | `7b6586c3a765950ff36c02c4247bfa01a954d6bd04751a8b21938e778a230034` |
| 自动化测试 | `tests/cashier-v3/js/store-switch-contract.mjs` | `1f86f9ada0640ccd4b3f78fcd365f907f11005c56f6b10b336df5ead3db46603` |

前端源码、数据库迁移、工程脚本、生成物：无。证据仅为本文件。以上源码均补充或更新了切店身份、实时岗位授权及事务内判权的职责/边界注释。

## 权威口径与自测

- 权威门店集合由有效任职门店与服务端实时数据权限共同产生。首次登录仍取唯一任职店；切店目标必须在当前和目标均获授权的候选集合内，服务端签发绑定目标店的新会话。
- 无组织直属岗位的跨店会话使用独立 `staff_scope` 来源；功能权限从唯一有效原任职岗位实时计算，不能伪造目标店任职或扩张数据权限。岗位撤销或目标店数据范围收回后旧会话失效。组织直属岗位会话维持原规则。
- 无新业务事实、金额、迁移或统计口径；不跨客户共享会话。切店不复制购物车或库存临时令牌。
- PHP 7.4 容器中 6 个 PHP 文件语法检查全部通过。
- 本地事务性集成：`PASS direct_login_store=175 staff_scope_target=1 organization_targets=101 round_trip=1->175`。覆盖单店不显示候选、额外范围可切、原岗位功能、事务内写权限快照、撤销岗位后令牌失效、原组织直属切店路径；测试写入全部回滚，测试令牌撤销。
- `node tests/cashier-v3/js/store-switch-contract.mjs`：9 项通过。
- `node tests/organization-store-v3/js/store-v3-login-entry-contract.mjs`：18 项通过。
- `git diff --check`：通过。

## 验收状态

产品经理已于 2026-10-05 对以上固定版本明确确认“我测试没有问题”，并要求 `commit`、部署瑞昊、线上验证通过后 `push`。发布前核对：本地 18081 原标签页缺少“指定门店”是旧页面缓存；同一热更新服务新加载页面实际显示该选项，平台端源码和发布范围均不因此变更。生产瑞昊在部署验证前仍按旧版本记录，不把本地通过视为线上已生效。
