# R31 门店端员工离职保存修复自测记录

## 固定版本

- 基线 HEAD：`26f4809b5686cfdc8c675651287a7249c50a8258`。
- 六个实现/测试文件补丁指纹：`d7e6d4cfcf7ea4ec2d6c543d494488dd9e588ecb7b27924a57cb8aa47d717494`。
- 本闭环未提交、未部署、未 push，待产品经理对该固定版本确认验收。

## 修复口径

- 门店端员工完整详情同时读取员工全局在职状态及 `status_version`。
- 保存时状态与版本成对提交；旧版本被后端拒绝，防止两个编辑窗口覆盖。
- 门店端与平台端共用同一离职事务编排：先保存仍需校验有效任职的资料、岗位和授权，最后统一关闭员工、任职、岗位、入口并生成离职事实。
- 不提前关闭任职，因此不会再在保存手机端授权时误报“当前门店任职无效”。

## 自测结果

- 门店员工前端/接口/后端静态合同：通过。
- 两个后端 PHP 文件和真实事务测试脚本语法：通过。
- 本地 `ruihao` 测试库真实事务回归：状态版本、版本冲突、离职事实、重复保存、恢复在职、审计、看板计数全部通过。
- 平台端完整编辑保存离职及事务回滚：通过。
- 门店端完整编辑保存离职、任职关闭、状态版本递增、离职记录及事务回滚：通过；测试结束后员工和任职恢复原状态，无测试数据残留。
- 门店端生产构建：Vite 1972 个模块构建成功，仅有既有 chunk 大小提示。
- 本地页面刷新后，员工编辑资料、岗位、人员类型与在职状态正常回显；浏览器控制台无 error/warn。

## 文件 SHA-256

| 文件 | SHA-256 |
| --- | --- |
| `前端代码/cashier-v3/src/views/StaffListView.vue` | `05a3e6dd80283f04424e7bf74f8704192b1259982814feafe823fa14509ea052` |
| `前端代码/cashier-v3/src/services/staffManagementApi.js` | `234a54b8b7871d83a1857472a321cf0b058dad91a9128271858aa69d3743ef40` |
| `后端代码/app/controller/store/staff/StoreStaff.php` | `3f7c5e7b353dc38537bcb350d7fc10cf921fe03504dfbf6cdae0632721e3bdfe` |
| `后端代码/app/services/employee/EmployeePersonCompleteWriteServices.php` | `76c69a2a695f6db327f0531a8faaddd9d9e5f7dc99a8197fc62c11f3f9a583e8` |
| `tests/unified-query/js/staff-frontend-contract.mjs` | `a7af4c86ee95c7c32eac9aa67ea3d9d78114eec0e419e007561a40bb52c0ef6b` |
| `tests/employee-departure/php/local-transaction.php` | `966218b8df70457ddb2d2eb38f6894e06f1fb84038485e037c51eb3de41d1cbd` |

## 注释更新

- `StaffListView.vue` 与 `staffManagementApi.js`：说明状态和版本必须来自同一次读取并成对提交。
- `EmployeePersonCompleteWriteServices.php`：说明平台端和门店端的完整编辑统一使用员工全局状态，并在事务末尾处理离职。
- `local-transaction.php`：说明门店端测试只验证离职编排，不伪造人员类型管理权限。
