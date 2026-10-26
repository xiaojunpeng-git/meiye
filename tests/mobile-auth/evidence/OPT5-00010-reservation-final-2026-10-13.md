# OPT5-00010 商家端预约服务闭环固定版本

- 基线：`462ef52f`，本闭环既有文件的工作区补丁 SHA-256：`b5f93d7fd0b8010abcdcd84f9c181ff5ac9221d7dd3b5fe16e48f08e5c90c9d8`。
- 产品经理已对本轮未部署范围回复“我测试没有问题”，明确授权 commit、部署瑞昊、push、上传体验版。发布结果另记；本记录只固定开发与自测范围。
- 权威预约状态来自 V3 预约主表；服务列表跨日期仍限定当前授权门店。可信商家手机会话只可在实时拥有预约功能授权时执行预约生命周期命令，其他 V3 写命令与越权门店继续拒绝。服务开始和结束不改动原有事实结算口径。

## 源码与自测文件

| 分组 | 路径 | SHA-256 |
| --- | --- | --- |
| 前端源码 | `前端代码/mobile-vue3/src/merchant/pages/reservations/index.uvue` | `fdf4f17e950a1b225fec72feaecf790a00ba4c39f73d961b4e12f9e96447a4f6` |
| 后端源码 | `后端代码/app/services/cashier/v3/CashierV3DataScopeContext.php` | `400fa461fdd8e62d0f3acc4a0fff993d2d9bf06dc79ad564af597b5d6e9dd33c` |
| 后端源码 | `后端代码/app/services/cashier/v3/CashierV3DataScopeFactory.php` | `000b8c8bc23e13df2f092961f5d0b1cfd9363dee78bd964571f2d114d5ba9beb` |
| 后端源码 | `后端代码/app/services/cashier/v3/CashierV3PermissionSnapshotServices.php` | `55d34cb4ebf1ad8d0b1eea08c35cc3c06fb5cc9cbf1204c9bf300391bd99c2e1` |
| 后端源码 | `后端代码/app/services/cashier/v3/registry/CashierV3PermissionGuard.php` | `364658f4e200d87eaf97d034846fbe3905b5aba0ebf8992aba54e61bce668ba2` |
| 后端源码 | `后端代码/app/services/cashier/v3/reservation/CashierV3ReservationModule.php` | `f8e50e57b1eee8ea5a44e72abeb3bc64a82821f135cc9f42878d8e1aa297c3f5` |
| 后端源码 | `后端代码/app/services/cashier/v3/reservation/CashierV3ReservationPartitionProvider.php` | `08e71b3fcfa996154f8f3343ce71f423ba73bb1bae3ed6c6d9dfcfced4d5b99c` |
| 后端源码 | `后端代码/app/services/mobile/reservation/MobileReservationServices.php` | `6024fe86b935109bb72f10f2b5d047375fd6c5de810ea95d32d9be4210916b87` |
| 自动化测试 | `tests/mobile-vue3/js/reservation-page.test.mjs` | `e502152e2a16204a809f12351c32c7d3ab3f02ed3673c4bd503170d00d73a08c` |
| 自动化测试 | `tests/cashier-v3/php/reservation-list-filter-contract.php` | `86787103ae2eff68b80349d301bfcfc77c2fb9859e6f8c61acb1349e978a6395` |
| 自动化测试 | `tests/cashier-v3/php/reservation-cross-client-mysql-integration.php` | `4bd1f24c0bfca236e294fbe487b28bb21aad0f08a08a77313c292daf243029d0` |
| 自动化测试 | `tests/organization-store-v3/js/store-v3-login-entry-contract.mjs` | `0b5322c423555cad51c5643d7b94ad1eab2e9df8268876d7e8e1e3c3b5fd8309` |
| 自动化测试 | `tests/mobile-auth/php/mobile-reservation-delegated-permission.php` | `70f98f6635a64a5625d59927e7873c50ffd3e8d2195b0523afd47378767e79b8` |

数据库迁移、工程脚本：无。生成物：本地 H5 热更新，仅用于自测。验收证据：本文件及本地忽略目录 `tests/mobile-vue3/evidence/` 中的页面截图与先前自测记录；截图不是源码。

## 行为与验证

- 预约服务页移除重复计数和误导性同步文案，恢复新增，支持当前门店跨日期读取；未开始服务详情可编辑，服务端在行锁内拒绝过期手机草稿。列表以 `statusCode` 识别已结束状态，只有服务中显示计时，晚到的旧页签响应不覆盖当前页签。
- `bash tests/mobile-warehouse/run-contract.sh`、预约页面 11 项测试、预约筛选与生命周期合同、组织选店合同、手机预约权限动态回归、变更 PHP 语法检查和 `git diff --check` 均通过。
- 本地 H5 实测未开始预约编辑保存；预约 `YY2610130004` 的主表状态为 `COMPLETED`，已结束列表与详情均显示“已结束”，不再计时。
- 跨端 MySQL 回滚集成脚本在既有样本“确认预约”步骤返回 `PERMISSION_DENIED`，未执行到新增的过期草稿断言；不能将该脚本记为通过。真实线上预约写入仅在发布后按授权验证，不在本地自测阶段发生。
- 注释更新于预约页、数据范围上下文与工厂、权限快照和门禁、预约服务适配层及 V3 预约模块与列表，说明状态、版本、门店授权和事务内重建边界。
