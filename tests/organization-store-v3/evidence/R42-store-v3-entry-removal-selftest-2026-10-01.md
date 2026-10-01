# R42 门店端个人入口移除：本地自测与待验收版本

- 基线 HEAD：`f7aff5f157058a629c41c38cb5b2401e53166d3e`。
- 范围：门店端员工任职的个人 `store_v3` 入口开关不再参与登录和功能权限，也不再允许写入；仍须在岗、唯一有效任职、有效岗位开启门店端并有功能规则。组织身份选店和商家端个人开关不变。
- 旧 `staff_channel_entry` 中的 `store_v3` 行只保留历史记录，不迁移或删除。它们从此不再拦截岗位已授权的员工；上线后这类员工会获得门店端访问权，这是产品经理本轮要求的权限变化。
- 本次只改统一源码；目标实例、线上迁移状态、备份和回滚点不适用。本地恢复可按本文件清单及基线 Git 版本进行，未连接或修改瑞昊实例。

## 文件清单与 SHA-256

| 分组 | 文件 | SHA-256 |
|---|---|---|
| 前端源码 | `前端代码/admin/src/pages/store/region/workspace/index.vue` | `e729447c8dcdb002dfa4c9a05d00fb4bfc597174cbec9b56e163a634a89616d6` |
| 后端源码 | `后端代码/app/services/employee/EmployeeInternalLoginServices.php` | `cadaaaf32f23413c35720459441c261cd721c13d71d91fab62465fa01211fa80` |
| 后端源码 | `后端代码/app/services/cashier/v3/permission/CashierV3FeatureResolver.php` | `d0b7a113d2b04e90f874ac1d3f7e33eb08ee857057537c3ca8fc9ca6eb0ff548` |
| 后端源码 | `后端代码/app/services/organization/StaffJobPositionServices.php` | `888daaa73508f7ca3337b3876c90893dc23b30f607a7a7160f268fc124b8933b` |
| 后端源码 | `后端代码/app/services/cashier/v3/CashierV3StoreLoginServices.php` | `c2b7ab991e67a40247c7dabe06356ad20253a94f1626d460e60115d4bd488ac7` |
| 后端源码 | `后端代码/app/services/cashier/LoginServices.php` | `e99a008b9f7594790afeebba4c03dbad49df61f21e5f19a1be5ee894fbb49ebe` |
| 自动化测试 | `tests/organization-store-v3/js/store-v3-login-entry-contract.mjs` | `fe5cdb4603820582ea58fdf6bb1ce3778e89f4f62ea89b58e27bbf3566836a8c` |
| 自动化测试 | `tests/organization-store-v3/php/store-v3-tenure-with-legacy-entry-contract.php` | `e8139bdd8670e4a676671f75e287dcc2e9190cb10f03dd89f3fc877f49ec6176` |

上述已跟踪文件补丁 SHA-256：`83fef236ff249d21e5a0cc4dec6a197ec8ebb5058893b3635e3ef2000ab96af0`；新增 PHP 测试以独立文件 SHA-256 固定。没有数据库迁移、工程脚本或构建生成物；本文件仅为验收证据。

## 自测

1. `node tests/organization-store-v3/js/store-v3-login-entry-contract.mjs`：18 项通过、0 项失败；同目录 `single-store-tenure-contract.mjs`、`job-position-persistence-contract.mjs`、`person-complete-save-identity-contract.mjs` 均通过。
2. 本地 `mohe-app` 容器执行 `docker exec -i mohe-app php /dev/stdin < tests/organization-store-v3/php/store-v3-tenure-with-legacy-entry-contract.php`：`PASS store_v3_legacy_entry_ignored_and_off_duty_denied staff_id=500`。测试只在本地库事务内关闭历史个人入口、再改为离岗，覆盖直入任职、功能解析和离岗拒绝；`finally` 回滚全部写入，不产生业务数据。
3. 5 个修改的 PHP 文件及新增 PHP 测试 `php -l` 无语法错误；Vue 单文件组件模板和 Babel 脚本编译通过。单文件 ESLint 仅报原有两个未使用导入（`getRoleTemplateDetail`、`saveRoleTemplate`），与本次变更无关；未借本轮修改其他任务代码。
4. `git diff --check` 通过。未执行真实线上账号登录，未构建或部署 `8080` 集成包。

## 数据架构自检

- 权威来源：`system_store_staff` 有效任职、`staff_job_position` 有效岗位、`position.use_store` 与 `job_position_channel_rule`；`staff_channel_entry` 的旧 `store_v3` 记录不再是授权源。组织身份切店仍按服务端数据权限限定。
- 本轮不创建业务事件或经营事实，不涉及金额、统计时点、幂等写命令、冲销或报表；功能授权每次按当前服务端事实计算，无客户端扩权。
- 历史兼容：不删除个人入口历史记录，旧接口提交 `store_v3` 明确报错，避免看似保存成功却无效；商家端 `mobile` 仍可独立控制。
- 安全边界：离岗或任职失效、岗位关闭、岗位规则为空仍拒绝。回归测试覆盖旧入口关闭后的在岗放行与离岗拒绝；上线前还需在固定版本上由产品经理确认并按发布授权做目标实例验证。

## 注释更新

本轮为新增/修改的权限边界补充了职责和历史兼容说明：`EmployeeInternalLoginServices.php`、`CashierV3FeatureResolver.php`、`StaffJobPositionServices.php`、`CashierV3StoreLoginServices.php`；新增事务性测试说明回滚与边界。前端模板和登录错误文案同步业务口径。

状态：开发与本地自测完成，待产品经理对上述固定版本确认验收；未 commit、未部署、未 push。
