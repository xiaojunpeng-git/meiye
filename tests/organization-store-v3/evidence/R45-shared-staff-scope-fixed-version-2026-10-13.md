# R45 员工数据范围两端一致：产品经理验收通过

- 产品经理于 2026-10-13 确认测试无问题，授权本轮 commit、部署瑞昊与渼约 008，并在两站线上验证通过后 push；本记录中的开发阶段状态以该确认为准。

- 开工时 HEAD：`c4de5addec14e4e6ef3a49be6b1715826606772d`；当前固定版本 HEAD：`29b25bbe6ec68af5f5fc2d437ae46a8516544213`
- 业务规则：同一员工的数据范围由同一条总部来源记录控制；门店有权编辑该员工时可覆盖为“个人”或“门店”。“门店”覆盖其全部当前有效任职门店。总部的“组织/指定门店”在门店页如实回显，未明确点选不改动。
- 兼容：只有旧门店来源、尚无总部记录的员工，两端按现有授权门店合成一致回显；明确修改后写统一记录。旧门店来源保留为历史数据，有总部记录时不再叠加授权。
- 并发：门店明确修改范围时须携带详情版本，过期保存拒绝；只保存其他资料不会写权限。
- 本轮未连接远程实例，未执行正式数据迁移、部署或 push。`18091` 为本地 Vite 源码预览，不是 `8080` 集成包。

## 文件指纹

| 分组 | 文件 | SHA-256 |
|---|---|---|
| 前端源码 | `前端代码/cashier-v3/src/views/StaffListView.vue` | `a8291a094f0d24deda10fb0fcc5e33e8affe961f565b915e8eed872c16e021e4` |
| 前端源码 | `前端代码/cashier-v3/src/services/staffManagementApi.js` | `b2e81dc6b3ea1a09f353dcee93369a3d4db77c7fc72e0b989be8ae58c752906b` |
| 后端源码 | `后端代码/app/controller/store/staff/StoreStaff.php` | `c2bed389ee7d8a52d2cc5e1e0c0339b044f4811feb5c99e63aba8a7980ce14f4` |
| 后端源码 | `后端代码/app/services/employee/EmployeePersonCompleteWriteServices.php` | `7dab6f9fcf50361d051684367bf248f73f86edb76dca7bb7de479b21f94f103e` |
| 后端源码 | `后端代码/app/services/organization/EmployeeDataScopeServices.php` | `1f4af49902e01f34c88a137f69ffb3d8e6603bb5b61cbe9f38795bbbf75f4176` |
| 自动化测试 | `tests/organization-store-v3/php/round45-shared-staff-scope.php` | `417d56e98dc0c396082439e0ddfccf5c0c3dc86fcd0c54843889c78ea5d8517d` |

当前 HEAD 之后已跟踪文件补丁 SHA-256：`c036f9f42fce383d675f6a0491a2eb7e212e5060de2dfa8832efc2526e69b24c`。`EmployeeDataScopeServices.php` 开工前已有另一任务的未提交修改；本轮在其基础上追加实现。并行任务于 05:10 的 `29b25bbe` 提交将该文件的本轮改动一起纳入了其提交，虽非本轮发起，已成为当前 HEAD 的事实。其余本轮源码及测试仍未提交；后续不得把该文件再次当作未提交改动暂存，也不得回退并行任务提交。

## 自测

- `php -l`：3 个修改的 PHP 源码文件及新增测试文件均无语法错误。
- `docker exec -i mohe-platform-app php < tests/organization-store-v3/php/round45-shared-staff-scope.php`：10 项通过；仅连接本机 `mysql`，事务回滚。
- `docker exec -i mohe-platform-app php < tests/organization-store-v3/php/staff-selected-stores-transaction.php`：19 项通过；事务回滚。
- `npm run build`（`前端代码/cashier-v3`）：通过；仅产出本地忽略的 `dist/`，未做 `8080` 集成打包。大 chunk 为现有构建警告。
- `git diff --check`：通过。
- 本地 `18091` 编辑页只读检查：员工“门店”回显正常；未在页面保存真实员工权限。

## 注释与边界

本轮在 `EmployeeDataScopeServices.php`、`EmployeePersonCompleteWriteServices.php`、`StoreStaff.php`、`StaffListView.vue`、`staffManagementApi.js` 补充或更新了共用权限、旧来源兼容、覆盖条件和版本保护注释。没有数据库迁移、工程脚本或需提交的生成物；验收证据为本文及上述回滚测试输出。
