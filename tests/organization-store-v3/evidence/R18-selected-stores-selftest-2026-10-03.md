# R18 人员数据权限指定门店：本地自测交接

状态：开发与自测完成，待产品经理确认验收；未 commit、未部署、未 push。

## 范围与边界

- `编辑人员 → 数据权限` 新增“指定门店”：跨组织勾选多个明确门店；组织树只用于查找，不隐式授权组织下级。
- 原“门店”仍按当前任职自动计算；原“组织”仍可多选组织及下级。门店来源不能设置跨店范围。
- 保存时服务端核验门店存在、未删除且属于操作者可授权集合；读取时最新总部“指定门店”覆盖其他旧授权来源，删除/隔离门店会扣除。手机端会话、工作台和分析入口复用同一有效门店解析。
- 权威数据源：`employee_data_scope` 的 `scope_mode=store_selected` 与 `store_ids`；未新增数据库字段或迁移。权限变更仅经既有人员完整保存事务与审计写入。

## 固定验收版本

- 基线 HEAD：`544d013d0457c971813ffbed032290b84c17225f`
- 跟踪文件工作区补丁 SHA-256：`f89b25ae5a995c2ab750bec771ced1368170a9a42ffce9e4e01c619f75b7cd0e`
- 前端源码（SHA-256）：
  - `前端代码/admin/src/components/organization/OrganizationStoreScopePicker.vue` `1d370b3643113abe0431df21e9682e94ee487971cbd75f2d831907d330ebc0b2`
  - `前端代码/admin/src/pages/setting/staff/add.vue` `4c0b3269de04d736b7430c47ad3bdf3cfadd2013829ff098539e94f7d9aba96b`
  - `前端代码/admin/src/pages/store/region/workspace/index.vue` `2a0cf5c2ec598bdf58669f9c94765a00d012fa4defb6c32ebb56a8fe7e4aebaf`
- 后端源码（SHA-256）：
  - `后端代码/app/services/employee/EmployeePersonCompleteWriteServices.php` `908487e7aaa1550dc94186f07bebdb52d7ca983ad9bb965dfaeb2afaba2d0cd4`
  - `后端代码/app/services/organization/EmployeeDataScopeServices.php` `01f70f8fc59f6df5382be6b2049c377023548142ae42f13b63b80f83da8440ca`
  - `后端代码/app/services/mobile/merchant/MobileMerchantSessionServices.php` `f82a07b652ae2b176fda6686d162d70343548e88433d6643a35b735adccea970`
  - `后端代码/app/services/mobile/merchant/MobileMerchantRequestContextResolver.php` `ae556d5e5cd370a6a6c82ff0a83062d2053cff2b416af5b15157051fadaf9ed7`
  - `后端代码/app/services/mobile/merchant/MobileMerchantAnalyticsEntryPolicy.php` `16d20fa068c378b94651cb596a0463ad98570b3a890dc974ded4472a0d630175`
  - `后端代码/app/services/mobile/merchant/MobileMerchantAnalyticsEntryScopeServices.php` `739f7eab668bf80e622c4ed42609f7f40a7f0c5bfb2577382d243be174e44fca`
  - `后端代码/app/services/mobile/dashboard/MobileMerchantDashboardServices.php` `aa2738652adbcf98ca78098df1552c8bf467fcdea69fdf888ccf1ac6447fac4d`
- 自动化测试（SHA-256）：
  - `tests/organization-store-v3/php/staff-selected-stores-transaction.php` `ce301fe9550f4c679e95db19e7d52692c9a1e91c1a2e9d90838d98162a2a2033`
  - `tests/mobile-auth/php/mobile-session-contract.php` `a5ac6c50a66ca302630ac3543697fb610b9f5c208ad5df5d0e7a89e3b9370b7a`
- 数据库迁移：无。工程脚本：无。生成物：本地 `admin/dist/`，不纳入源码提交。验收证据：本文件。
- 共享脏文件提醒：`MobileMerchantSessionServices.php` 与 `tests/mobile-auth/php/mobile-session-contract.php` 在本次开始前已含其他任务未提交改动；上述整文件哈希包含这些既有改动。后续提交只暂存本轮对应补丁，不能整文件混入。

## 自测结果

1. 平台端 `npm run build`：通过；仅原有体积/Browserslist 警告。18081 开发服务重启后编译成功，构建源核对为当前 `前端代码/admin/` 挂载。
2. 7 个后端业务文件与新增 PHP 测试 `php -l`：通过；`git diff --check`：通过。
3. `docker exec -i mohe-platform-app php < tests/organization-store-v3/php/staff-selected-stores-transaction.php`：8 项通过。覆盖精确保存/解析、其他来源不并入、已删门店扣除、无效门店拒绝、操作者越权拒绝、门店来源跨店拒绝、事务回滚无权限残留。测试仅连接本机 `mysql` 容器，本地事务最终回滚。
4. `php tests/mobile-auth/php/mobile-session-contract.php`：16 项通过；同步更新权威权限服务引用的契约断言。
5. 18081 独立浏览器标签 UI：可见“指定门店”；选集团下江苏江都店与扬州欧尚店同时回显两家，取消单家后只剩一家，清空后恢复未选。关闭测试窗口，未提交任何真实人员权限变更，也未刷新用户原有编辑标签。

## 产品经理验收建议

在 `http://127.0.0.1:18081/admin/store/region/list` 选择一名可测试人员，编辑数据权限并保存“指定门店”两家；刷新检查精确回显，再以该人员身份验证可见门店恰为所选集合、未选同组织门店不可见。切回“组织”或“门店”确认旧模式仍正常。原已打开的旧标签如有未保存内容，请先处理后再刷新获取新版资源。

本轮注释已同步补充在门店选择控件、人员保存、统一数据权限解析、手机端会话/工作台/分析和看板的新增关键分支；注释明确精确授权、不得扩权、旧模式边界与回滚测试约束。
