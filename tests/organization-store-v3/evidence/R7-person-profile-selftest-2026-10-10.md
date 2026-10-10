# R7 人员类型保存与当前任职清空

## 产品经理验收

2026-10-10 产品经理对上述固定版本确认“我测试没有问题”，授权本轮 commit、部署瑞昊、线上验证通过后 push。原“待验收”描述保留为自测交付时状态；实际发布结果另记 `R7-rh-deployment-2026-10-10.md`。

## 范围与交付状态

- 产品经理确认：清空当前任职门店即结束该门店当前任职，保留员工档案、组织关系和历史业务，关闭依赖原任职的入口；调店继续走既有流程。
- 人员类型保存修复与清空任职均已开发、本地自测完成，待产品经理对本固定版本验收。尚未 commit、部署或 push；本轮没有操作线上员工或生产数据库。
- 已完整阅读统一数据底层架构及独立实例发布治理。当前仅本地开发和 Docker 测试，生产目标、迁移状态、实例备份不适用；无数据库迁移。
- 唯一开发源：当前工作区 `前端代码/admin`、`后端代码`；18081 热更新服务使用该 admin 源码。没有另建 worktree 或业务源码副本，没有生成 8080 发布包。

## 实现口径

1. 人员类型保存的 employee 路由形参与 `:id` 一致，避免编辑误走新建手机号复用；未分类保持空值，不假装为内部员工。前序原因与线上只读调查见 `R7-person-type-selftest-2026-10-10.md`。
2. 已有任职的门店字段增加清空和撤销操作。清空保存使用原 staff/store 身份及授权版本，普通编辑仍不能借门店选择调店。岗位回显不误判为人工修改；真正的岗位修改须单独保存。
3. 完整保存同一事务内锁定原任职、关闭渠道与原门店岗位、撤销原门店来源数据权限、记录 clear 任职结束事实和审计，并统一使旧会话失效。旧 staff 主键、门店 ID、员工主档与历史业务不删除，不复制门店岗位为总部岗位。
4. 清空与全局离职不能混合提交，员工仍在职；历史授权版本 0 有效。原数据范围为“门店”时必须自行改选适用范围，系统不自动扩大权限。权限拒绝、旧版本冲突与后置失败全部回滚。
5. 清空后详情不回填旧门店，人员列表显示无店直属和员工真实在职状态；在职/离职筛选及 count 口径一致。重复请求只回放一次，普通保存幂等指纹结构不改变。

## 本地验证

- 真实框架控制器 + MySQL 清空任职事务测试：29 项通过，涵盖原身份/范围、权限拒绝、旧版本、版本零、岗位/离职混提拒绝、原子回滚、历史保留、渠道关闭、岗位结束、会话撤销、数据范围、列表/count、刷新、重复回放及冲突；全部事务夹具回滚。
- 清空前端真实方法测试：6 项通过。保存身份合同 5 项、学历提交回归 4 项、单店任职合同 16 项、人员类型后端合同 10 项通过。
- 真实类型完整保存事务 8 项及 ThinkPHP 参数绑定测试通过；员工离职本地事务 6 组通过；R51 调店真库事务回归全部 PASS，夹具全部回滚。
- 五个修改的后端业务文件 PHP 语法通过，`git diff --check` 通过。编辑文件后 Docker 挂载同步瞬间出现过一次 EOF；文件同步后语法及两套事务重跑通过，不以异常退出码零冒充成功。
- 浏览器隔离员工 1808 / staff 1563：清空可撤销；清空保存后列表显示无店直属、在职；刷新重开详情仍无任职门店、内部员工及在职。截图见下列证据。隔离员工、原测试任职、组织关系与测试岗位已软归档，审计历史保留，没有登录账号或真实业务。前序类型 UI 隔离员工 1804 也已软清理。
- 已知基线差异：`tests/employee-type-authority/js/frontend-contract.mjs` 6 PASS / 1 FAIL，失败为旧测试要求外包第三选项，基线页面仅内部员工/合作方。本轮未变更类型选项，不声称全套测试通过。

## 文件分组与注释

- 前端源码：`前端代码/admin/src/pages/setting/staff/add.vue`、`index.vue`。
- 后端源码：下表五个 PHP 业务文件。
- 自动化测试：下表五个 JS/PHP 测试文件，其中保存身份合同为修改，其余四个新增。
- 数据库迁移、工程脚本、构建生成物：无。
- 验收证据：本记录、前序类型记录、`R7-person-type-persisted-2026-10-10.png`、`R7-clear-store-list-2026-10-10.png`、`R7-clear-store-persisted-2026-10-10.png`。
- 七个修改业务文件均补充或更新职责/身份/空值/并发/事务/生命周期/权限边界注释；复用原任职生命周期辅助方法，无新增平行业务入口需要保留。未改变其他任务暂存或工作区内容。

## 固定验收版本

- 初始 HEAD：`6d46470305799efc7198c777deb04df25ec5052a`。期间其他任务正常提交，固定时 HEAD：`858a18fac7f465815cedbb296ccd0f33610b45cf`。
- 本闭环十二个源码及测试文件补丁 SHA-256：`a650294fd7f5440d47240989a29cf38ee521b1704f646548d1fc2871cfbc0bb6`。
- 计算方式：按下表前八个已跟踪文件顺序执行 `git diff HEAD --binary -- <files>`，再按后四个新增文件顺序拼接各自 `git diff --no-index --binary -- /dev/null <file>` 输出后计算 SHA-256。证据文件不参与业务补丁指纹。

| 文件 | SHA-256 |
| --- | --- |
| 前端代码/admin/src/pages/setting/staff/add.vue | `29f6fdef7c159cc6f107ed97a3d3b8cbb62983e4de2925adf7e4c427e1f68d8d` |
| 前端代码/admin/src/pages/setting/staff/index.vue | `28db63ab72fb4c90ec6b1e7801f52fb9b555b81a4e4cc86290c8e1391b0f835a` |
| 后端代码/app/controller/admin/v1/merchant/SystemStoreStaff.php | `5de56495dd35360ab2e41cc2209be731f843a5abcea665984d202b4add810600` |
| 后端代码/app/dao/store/SystemStoreStaffDao.php | `eacdc597b30ce345cdfc1e584c84416c20b2ae05f70b0a426ca5c0109e3678c7` |
| 后端代码/app/services/employee/EmployeePersonCompleteWriteServices.php | `ddea1907545df7ef0a9bfce3eda5a2fa8402a6b1d07b95caa31e846b28578c4a` |
| 后端代码/app/services/organization/StaffTenureServices.php | `c0375a87c98e5412468921d88cc31bd29bc992c60244937ed40bbbcb4704f072` |
| 后端代码/app/services/store/SystemStoreStaffServices.php | `8adee0c7c244d765449a8d1a8d42acc36c2f31d9c44ae8a2c3a5aeebc4089c1e` |
| tests/organization-store-v3/js/person-complete-save-identity-contract.mjs | `519783e28558a8c4686820929fc008731e7e7346073451826af5dec07a3da652` |
| tests/organization-store-v3/js/staff-clear-current-store.mjs | `14f24059c4a39ca9fef45235e66faea8ef5b127f28ddd1932483e6255d3f1d15` |
| tests/organization-store-v3/php/person-complete-route-identity.php | `96897ac205e3fc0393407582ea0c94c7f187d7aec6f0817c0820c78d4761a1e3` |
| tests/organization-store-v3/php/person-complete-type-local-transaction.php | `297fcf86f23c9f6d823bb248d4bfdac74a6fdfeaf57873bbb9ea38ed867c7920` |
| tests/organization-store-v3/php/person-complete-clear-store-local-transaction.php | `3f8526eb798ed925ecde5661157e1336f0f85de9fef2b1fcb8694cd80ba7b3e4` |
