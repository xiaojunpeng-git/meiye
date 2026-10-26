# R7 门店所属组织筛选固定版本

## 范围与状态

- 用户追加要求：门店列表顶部“门店区域”筛选改为“所属组织”。本版本包含上一阶段尚未验收提交的所属组织列改动。
- 已实现并自测，待产品经理确认；未 commit、未部署、未 push。其他任务工作区及暂存改动未处理。
- 后续验收：产品经理明确“我测试没有问题，本轮 commit，部署瑞昊，push”；提交前五份源码/测试指纹全部与下方固定版本一致，5 项 JS、17 项本地真库测试重跑通过。本条记录验收授权，部署与推送实际结果另见发布记录。
- 原列改动记录 `R7-store-organization-column-selftest-2026-10-10.md` 保留当时事实，其中“区域筛选保持不变”仅描述上一阶段；本记录及下面指纹为当前交付版本。
- 源码沿用本工作区 `前端代码/admin/` 与 `后端代码/`，18081 热更新成功；未创建 worktree，未连接或变更远程实例，未打包 8080。

## 实现及数据架构自检

- 权威来源为当前 `organization`、`organization_store`。列表与编辑详情共用正式组织名称投影；筛选使用统一 `OrganizationScopeService::getOrgStoreIds` 解析组织及下级门店。
- 复用已有 `OrganizationResourceSelector` 的 `resource=organization`，支持按名称搜索、分页、单选和清除，选项继续执行已有后端权限。未复制组织树算法，也未改变公共选择器。
- 实测发现本地 `org_store_tree` 因旧模式返回旧区域节点，因此没有把其 ID 用作正式组织筛选。最终选项直接查询正式组织资源，避免区域 ID 与组织 ID 混用；不修改全局组织切源配置。
- 粒度为门店基础资料，不是历史经营报表；展示当前归属，不改历史快照。没有新业务事件、四类事实时间、金额或统计生效时点。
- 查询不写业务库，不涉及事务写入、并发扣减、幂等写回、Outbox、冲销或迁移。PHP 集成测试在只读事务内执行并回滚。
- 后端新增独立 `organization_store_ids` AND 条件，与既有门店 ID、权限及筛选取交集。没有绑定门店时使用空结果哨兵，不能退回全量；列表及 count 共用 DAO 条件，不在前端筛完分页。
- 组织名称投影只处理原查询返回的门店，保持顺序、分页及总数；一次批量读取，空列表不读。沿用原权限入口，不声明 CLI 探针等同完整 HTTP 鉴权测试。
- 旧区域 API 仍为其他调用者保留兼容；本页面旧区域 import、状态、选项加载方法已移除，引用扫描无遗留，不保留并行筛选路径。
- 原组织绑定查询 EXPLAIN 使用 `uk_store_id` 范围索引与组织主键 `eq_ref`；新门店条件使用 ID 范围集合，无逐行查询或数据库结构变更。

## 文件分组与注释

- 前端源码：`前端代码/admin/src/pages/store/storeList/index.vue`，说明正式组织选项来源、列取值及重置约束。
- 后端源码：`后端代码/app/controller/admin/v1/store/SystemStore.php`、`后端代码/app/dao/store/SystemStoreDao.php`，说明只读批量投影、独立 AND 条件及空组织边界。
- 自动化测试：`tests/organization-store-v3/js/store-list-organization-column.mjs`、`tests/organization-store-v3/php/store-list-organization-local.php`。
- 数据库迁移、工程脚本：无。生成物：本地开发编译结果，不纳入源码。
- 验收证据：本记录、前一阶段记录与截图、本次 `R7-store-organization-filter-2026-10-10.png`。

## 自测结果

- JS 组件契约：5 项通过，覆盖正式组织资源、选择/清除回第一页、重置 ID/回显及原地址/固定操作列。
- PHP 本地真库：17 项通过，覆盖组织投影与列表/详情 102 家逐店一致、批量次数、无绑定、正式组织选项 ID、组织及上级查询、空组织、关键词组合、权限 id/ids 交集、清空恢复。
- 地址回归：7 项通过。修改的控制器与 DAO PHP 语法检查、限定源码 `git diff --check` 通过。
- 实际浏览器：搜索“周晓玲”，选择“周晓玲经理”并确认，返回 2 条门店（179 扬州大润发店、18 江苏金湖桓裕店），与列表所属组织一致。清除及重置均恢复“请选择所属组织”和共 102 条；测试后保留用户原标签页及未筛选状态。
- 截图按浏览器当时默认窄视口取得，组织筛选文字、选择及清除按钮无重叠；未更改视口覆盖设置。
- 浏览器全程只读查询，未保存门店或组织资料。未把初步旧区域树方案的空结果视为验收通过；最终正式组织方案重新实际点击通过。

## 固定版本

基线 HEAD：`082c670cd497262cb5a13b3f50625b5d681d693e`。

补丁 SHA-256：`bdca11266999435e4f7e8151ba3fa3ad5e8bf427db3daea591ba12229b41eee7`。按下表顺序拼接前三份源码相对 HEAD 的 `git diff`，再拼接两份新增测试相对 `/dev/null` 的 `git diff --no-index` 后计算。

| 文件 | SHA-256 |
| --- | --- |
| 前端代码/admin/src/pages/store/storeList/index.vue | 85a56e7b472678c8bc91486ca290e00c108f82dd702514a465e62fc75ab60022 |
| 后端代码/app/controller/admin/v1/store/SystemStore.php | 4606d9b72169f85ab27fc3f3cfe02f40a0fbb917553e50f64d68b994a6955e5b |
| 后端代码/app/dao/store/SystemStoreDao.php | 16d1aeae507471776da59e1cf3884b618d3a1c55c0baa83f4bf7de8a4834e9ea |
| tests/organization-store-v3/js/store-list-organization-column.mjs | 65a9db2f2c240511a2b83a49d20a7debff9c634b54efccdc438050b91ac499a8 |
| tests/organization-store-v3/php/store-list-organization-local.php | 3dec2b03e16ec8df41607ba31cc330f1ce3af74575a2fd87f7ccb4012c0f314d |
