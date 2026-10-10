# R7 门店列表所属组织列自测记录

## 范围与状态

- 用户要求：门店列表“门店区域”列改为“所属组织”，与编辑页的所属组织一致。
- 已实现并完成本地自测，待产品经理确认当前固定版本；本次没有 commit、部署或 push。
- 只调整列表列名及取值，不修改顶部旧区域筛选、组织绑定、地址、收银页面或其他业务行为。
- 平台端使用现有 `前端代码/admin/`，本地入口为 `http://127.0.0.1:18081/`；未切换源码、创建 worktree 或连接远程实例。

## 数据与实现边界

- 权威来源：`organization_store.org_id` 关联 `organization.id` 的当前组织名称；不使用历史 `recommend_region_name` 反推归属。
- 列表与编辑详情共用私有组织投影方法，已替代原编辑详情中的独立查询分支。该方法只接受原入口授权读取的门店，不新增结果行、不改变顺序、分页或总数。
- 非空结果只执行一次批量组织查询，空列表不查询；未绑定时组织 ID 为 0、名称为空，不猜测、不写库。
- 读取当前基础资料，不新增业务事件或统计指标；金额、事实时点、冲销、幂等和迁移均不涉及。现有入口权限保持不变；CLI 探针替换 HTTP 边界，不作为完整鉴权验收证据。
- 本地 EXPLAIN：组织绑定使用 `uk_store_id` 范围索引，组织名称关联使用主键 `eq_ref`。
- 本次注释更新文件：前端列表组件、后端控制器，说明正式组织来源、已授权门店输入及批量只读边界。

## 文件分组

- 前端源码：`前端代码/admin/src/pages/store/storeList/index.vue`。
- 后端源码：`后端代码/app/controller/admin/v1/store/SystemStore.php`。
- 自动化测试：`tests/organization-store-v3/js/store-list-organization-column.mjs`、`tests/organization-store-v3/php/store-list-organization-local.php`。
- 验收证据：本记录及同目录 `R7-store-organization-column-2026-10-10.png`。
- 数据库迁移、工程脚本：无。生成物：仅本地热更新编译结果，未执行 8080 集成打包。
- 共享工作区存在其他任务的暂存及未提交改动，本次未暂存或修改它们。

## 验证结果

- `node tests/organization-store-v3/js/store-list-organization-column.mjs`：3 项通过，检查列名/字段、旧区域筛选不变、地址及操作列保留。
- `docker exec -i mohe-platform-app php < tests/organization-store-v3/php/store-list-organization-local.php`：10 项通过。使用本地只读事务执行真实列表、详情和组织查询；102 家门店逐店组织一致，输入边界、空列表、未绑定及查询次数通过。
- `node tests/organization-store-v3/js/store-address-hydration.mjs`：7 项通过。
- 修改的 PHP 控制器语法检查及源码 `git diff --check`：通过。
- `mohe-admin-src` 热更新编译成功。
- 实际浏览器：列表表头为“所属组织”；门店 179 扬州大润发店显示“周晓玲经理”；第一页 15 行、总数 102，点击第二页正常显示舒城万达店“万惠经理”、鹤壁黄河路店“袁凤超经理”等组织名称。页面截图无新增遮挡，原区域筛选仍保持原义。
- 浏览器操作仅查询和分页，未保存任何资料；仅关闭本次代理自测标签页，用户原标签页保留。

## 固定验收版本

- 基线 HEAD：`082c670cd497262cb5a13b3f50625b5d681d693e`。
- 补丁 SHA-256：`02a001617e8872b215b5e69035ee904b4bfc72affe54510ed9d9598d052f2cdb`。按下列顺序拼接两份源码相对 HEAD 的 `git diff`，再拼接两份新增测试相对 `/dev/null` 的 `git diff --no-index`，计算 SHA-256。

| 文件 | SHA-256 |
| --- | --- |
| 前端代码/admin/src/pages/store/storeList/index.vue | b171bc749a32a50748f9d87684b0a777e1d4cf627fd1bf5b1cf6d4d2ec926f74 |
| 后端代码/app/controller/admin/v1/store/SystemStore.php | 232f40445bdf220926ff8229cf248d00ff42e568c529c179f8105c55614bf849 |
| tests/organization-store-v3/js/store-list-organization-column.mjs | acca359d9b7a24046ce9fa98bd491608564354c55b4f7bff9ae1c418764b76e8 |
| tests/organization-store-v3/php/store-list-organization-local.php | cdcddf80f99b14a9fc20b2e1037d71177c721ae9c2f6d86d828b339855289920 |

线上未变更；前一地址修复的发布授权与完成状态不代表本次所属组织列已发布。
