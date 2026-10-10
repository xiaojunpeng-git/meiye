# R7 人员类型保存修复自测

## 范围与状态

- 基线 HEAD：`6d46470305799efc7198c777deb04df25ec5052a`。
- 人员类型保存与空值展示已修复并完成本地自测，待产品经理验收；未 commit、未部署、未 push。
- 本记录为人员类型修复的前序快照。产品经理随后确认“清空门店只结束当前任职，保留员工档案及历史”；清空实现及本轮合并固定版本见 `R7-person-profile-selftest-2026-10-10.md`，本记录旧指纹不再作为本轮最终验收版本。
- 唯一源码为当前工作区 `前端代码/admin` 与 `后端代码`；本地调试入口为 `http://127.0.0.1:18081/admin/setting/staff/index`。
- 瑞昊只读核查：员工 1803 类型 NULL/version 0，没有类型变更审计；完整保存审计曾返回空类型/version 0。三个相关后端文件线上指纹与修改前本地一致。没有写生产数据或操作生产员工表单。

## 原因与修复

1. `staff/person_complete/save/:id` 的控制器参数原为 `$employeeId`，ThinkPHP 按名称绑定后得到默认零。编辑误走新建手机号复用路径，该路径出于保护既有主档不覆盖人员类型，因而保存成功但分类未改。形参改为与路由一致的 `$id`，保持 employee/staff 两种身份入口独立。
2. 列表曾把主档空类型投影为 internal，并再次以“内部员工”作前端兜底。现在列表与详情保持一致，未分类如实展示，不自动归类或修改员工主档。
3. 保留现有权限、人员类型版本、事务、幂等和审计，未增加迁移。

## 验证

- 真实 ThinkPHP 参数绑定：修改前复现 id=0；修改后既有 employee id、新建零值及兼容 staff id 全部通过。
- 本地真实控制器 + MySQL 完整保存事务测试 8 项通过：首次分类、详情读回、重复请求回放、再次编辑、旧版本拒绝、变更审计、请求体身份冲突、整体回滚。测试库和显式环境开关双重保护。
- 本地浏览器隔离员工 1804：初始列表显示未分类；点击合作方保存，库中 partner/version 1；刷新并重新打开仍选中合作方，警告消失；再次保存内部员工，库中 internal/version 2。一次性测试员工、组织关系与岗位已软清理，审计保留，无登录账号或真实业务。
- 页面截图：`R7-person-type-persisted-2026-10-10.png`。
- PHP 相关两业务文件语法通过；人员类型后端合同 10 项、保存身份前端合同 5 项、学历提交回归 4 项通过；`git diff --check` 通过。当前 18081 热更新已编译并在浏览器展示，没有生成平台 8080 生产包。
- 已知旧测试不一致：`tests/employee-type-authority/js/frontend-contract.mjs` 中“必须显示三个类型”失败，另外 6 项通过。基线 `staff/add.vue` 已没有外包选项；前序类型修复没有修改该文件，后续清空门店改动也不涉及类型选项，未擅自恢复外包按钮。不报告为全套测试通过。

## 文件与固定版本

- 前端源码：`前端代码/admin/src/pages/setting/staff/index.vue`。
- 后端源码：`后端代码/app/controller/admin/v1/merchant/SystemStoreStaff.php`、`后端代码/app/services/store/SystemStoreStaffServices.php`。
- 自动化测试：`tests/organization-store-v3/js/person-complete-save-identity-contract.mjs`、`tests/organization-store-v3/php/person-complete-route-identity.php`、`tests/organization-store-v3/php/person-complete-type-local-transaction.php`。
- 数据库迁移、工程脚本、构建生成物：无。验收证据为本记录与截图，和源码分开。
- 业务注释补充于上述三个业务文件，说明参数绑定身份边界与类型空值口径。
- 固定六文件补丁 SHA-256：`8c1814d17734bd5c38e475d700a8cae228dffbd990dff7c9d481e7977497c5e8`（按上列四个已跟踪文件 git diff HEAD --binary，再依次拼接两个新增 PHP 测试的 git diff --no-index /dev/null 输出计算）。

| 文件 | SHA-256 |
| --- | --- |
| staff/index.vue | `28db63ab72fb4c90ec6b1e7801f52fb9b555b81a4e4cc86290c8e1391b0f835a` |
| SystemStoreStaff.php | `289900220462400f9617a20605b0d0912dc780f96d00f5a6181ef2795cbed5ce` |
| SystemStoreStaffServices.php | `0be3470cee2c308b0e106966993a7becff38e0115f0169221d737fa5f1713fa3` |
| person-complete-save-identity-contract.mjs | `519783e28558a8c4686820929fc008731e7e7346073451826af5dec07a3da652` |
| person-complete-route-identity.php | `96897ac205e3fc0393407582ea0c94c7f187d7aec6f0817c0820c78d4761a1e3` |
| person-complete-type-local-transaction.php | `297fcf86f23c9f6d823bb248d4bfdac74a6fdfeaf57873bbb9ea38ed867c7920` |

其他任务的暂存与工作区改动保持原状，未夹带到本闭环。
