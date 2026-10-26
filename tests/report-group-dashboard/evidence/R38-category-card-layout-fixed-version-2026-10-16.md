# R38 集团看板分类卡片布局固定版本

- 基线 HEAD：`c5c28d167995480b2caddd41ce304c29bdfd19f2`
- 补丁指纹：`698da91c9c7ceb3f5b7c94d889644093ab178dbc39693d8656a97ab2ea18fe93`
- 范围：集团管理看板“分类业绩”复用统一分类卡片方法。分类按配置 `sort` 降序、同值按 `id` 降序；筛选项与卡片顺序一致；零值且无项目/产品排行的分类不显示；剩余卡片保持相对顺序并根据数量自适应铺满，每行最多六张，窄屏单列。

## 固定文件

| 类型 | 文件 | SHA-256 |
| --- | --- | --- |
| 前端源码 | `前端代码/cashier-v3/src/views/GroupManagementDashboardView.vue` | `d0eee0a566d05781aec8216eb7913d60ec66a12c370b17b1fd71b1580cb1bab6` |
| 后端源码 | `后端代码/app/services/report/GroupManagementDashboardServices.php` | `c93b6203f94d8dbf646a54cb22641750fd58cc33afa14d52a020103542e07423` |
| 自动化测试 | `tests/report-group-dashboard/php/category-statistics-switch-readonly.php` | `e8201c83322ccf260770f343563db4dd466f43a887108ad4fa18e41ac1d38ca2` |
| 自动化测试 | `tests/report-phase-seven/js/group-dashboard-refresh-contract.mjs` | `830007f28b8cdb471e6a8cda42fce551c243f55c11238aae60c17adf1203cd95` |

数据库迁移和工程脚本：本闭环无新增。生成物：本地构建产物，仅用于自测，不纳入源码清单。

## 自测结果

- `node tests/report-phase-seven/js/group-dashboard-refresh-contract.mjs`：通过。
- PHP 语法检查：服务与只读回归脚本均通过。
- 本地 Docker 只读数据回归：启用分类按 `sort DESC, id DESC` 返回，分类筛选与卡片顺序一致；关闭统计分类不出现；总现金业绩继续与统一事实层一致。
- `npm run build`：通过；Vite 完成 2008 个模块构建，仅保留既有大包体积提示。
- 本地 18081 页面：当前有效分类仅“六维”，卡片自动铺满整行；零值且无排行的分类不占空位；页面无新增横向滚动。
- 直达页手机宽度：分类卡片单列，页面无横向溢出。

## 注释更新

- `GroupManagementDashboardServices.php`：补充分类排序为后端权威顺序，以及筛选与卡片共用顺序的约束。
- `GroupManagementDashboardView.vue`：补充前端仅做显隐和布局、不按金额或排行二次排序的约束。

产品经理于 2026-10-16 明确确认本轮测试无问题并授权本轮 commit、瑞昊部署、线上验证后 push，再部署渼约 008。commit、部署和 push 结果另行登记。
