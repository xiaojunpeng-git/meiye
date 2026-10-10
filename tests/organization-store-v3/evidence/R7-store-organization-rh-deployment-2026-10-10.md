# R7 门店所属组织瑞昊发布记录

## 验收及版本

- 产品经理明确“我测试没有问题，本轮 commit，部署瑞昊，push”。范围为门店列表“所属组织”列、正式组织名称展示与顶部正式组织筛选，不包括其他任务。
- 本地业务提交 `9cebcf267f2b97b3c6655f39c6174ebd1e213aa8`，信息 `R7-00005 门店列表所属组织展示与筛选闭环`，9 文件：前端 1、后端 2、测试 2、自测记录 2、截图 2。
- 提交前五项源码/测试 SHA-256 与固定验收版本一致；JS 5 项、PHP 本地真库 17 项重跑通过，地址回归 7 项通过，PHP 语法与限定 diff 检查通过。业务注释和具体文件见本轮筛选自测记录。
- 共享本地分支含其他任务历史，未直接推送本地 HEAD。以远端 `aa4af68ac1f91357e8e6403f8ebe37530298f189` 为父，仅集成本轮 9 文件得到 `687286040a37ab4816673d2467998e154fa9a629`；集成版本 admin/shared 树与验收提交完全一致。
- 线上验证通过后功能集成提交已推送 `origin/release/store-cashier-c14-main`。发布证据单独归档提交并按同一边界推送；未推送 GitHub 镜像、未强推、未移动共享本地分支或更改其他任务暂存内容。

## 构建与预检

- 沿用 18081 对应唯一源码 `前端代码/admin`，从验收 commit 导出只读构建输入，复用原依赖，用既有 `scripts/build-frontend.sh admin` 和本机原生 arm64 Node 16 串行构建。没有新 worktree 或平行可编辑源码；只读输入在归档后清理。
- 构建 2026-10-10 21:12:50 成功，244 项既有 lint/资源/Browserslist 警告保留，不借发布扩大整改。admin 输入树 `3414b6e5fd516c54dbf80e9b6cc1ec1531b704ea`，shared 输入树 `30a91137bf7a16f7da01fcdc324bfd64b067602d`。
- 目标仅瑞昊 `47.96.103.107`、站点 `/www/wwwroot/rh.cc3798.com`、数据库 `ruihao`；PHP 7.4.33、可用磁盘约 200G。两个待替换 PHP 的原指纹与远端发布基线一致。
- 组织依赖服务与验收版本一致：`OrganizationScopeService.php` 为 `f024f9de07684be3feae835d6381b7a2506ddfe335071cd326723322fbbdd7bc`，`OrganizationResourceSelectorServices.php` 为 `5d17da792ebda5fc48cb6834b48774195375960ed2086e09d990666d6caa58eb`。
- 正式组织表 112 条、门店组织绑定与门店表各 102 条；`uk_store_id`、`idx_org_id` 和组织主键索引存在。最新升级记录前后均为 id 216、`20261010-r52-outbound-header`、2026-10-10 16:51:44。
- 本轮无数据库迁移或生产业务写库。没有改配置、依赖、上传文件、队列、计划任务、8080 预览包、门店端、会员端、商家手机端或其他客户实例。

## 独立备份与回滚

- 独立批次 `/www/backups/rh.cc3798.com/20261010-r7-organization-9cebcf26`，目录 700，代码/数据库归档、旧模板与校验记录 600。
- `pre-release.tgz` 包含旧平台 HTML、完整 `view_admin`、两个原 PHP 和精确编译模板；SHA-256 `d7a5710ba62d5986cd2b00d08ca39cf575b089d4a75a6539396987ed455f64eb`。
- `database.sql.gz` 为 ruihao 单事务全库备份，含 routines/events/triggers；SHA-256 `2ad8b782bb0b14365a52d6b843f6a6ceaaf0e359000b7c69c5d2dd00e8580958`。口令仅由实例配置读入子进程环境，未放入命令参数、日志或证据。
- 两份 gzip 完整性和代码归档条目通过；激活前再次核对备份 SHA 和生产原 HTML/PHP/精确模板指纹，排除发布期间被其他任务替换。
- 如需回滚，先排除后续发布，在隔离目录解开 `pre-release.tgz`，原子恢复旧 HTML、两个旧 PHP、原平台资源及精确模板，再仅重启瑞昊 Swoole 并核对原指纹。不默认恢复数据库或覆盖发布后业务资料，也不删除其他发布的资源。

## 发布及线上验证

1. 发布包 `release.tgz` SHA-256 `6ae3ed169ea4b024ef95f68d989ff3b3335d1c1f9d68801dc83d26630c99c675`。仅发布 `public/system.html`、`public/view_admin` 及两个 PHP，共 1168 文件；构建附带的其他根目录静态文件不发布。
2. 上传完整结束后验证归档、stage 全部文件指纹、PHP 语法和同名资源碰撞。追加静态资源、原子替换 PHP，仅重启本站 Swoole，再原子切换 HTML。
3. 只移动 `runtime/temp/636c51bc9e3516bb9cc9838f85176980.php` 到批次 `old-admin-template.php`，正常页面请求重新编译；没有清空其他缓存或删除旧哈希资源。21:17:54 +0800 运行目录 1168 项 SHA 全部通过，manager_count=1、PID=5627，20800 正常监听。
4. 公网 HTML、vendor/app 的两份 CSS 与两份 JS、门店列表业务分块共 6 项字节指纹与发布包一致。首次资源检查因本机 Node 16 无全局 fetch 失败，改用标准 https 后重新核对通过；最初误用列表 `/index` 路径的匿名探测未作为通过证据，按源码实际路由 `/adminapi/store/store` 重验返回 `410000 请登录`。
5. 使用既有登录会话新建临时线上页，实际点击正式组织选择器，搜索“周晓玲”，选“周晓玲经理”并确定，返回 179 扬州大润发店和 18 江苏金湖桓裕店，共 2 条，列表所属组织一致。清除与重新选组织后的重置均恢复“请选择所属组织”和共 102 条。
6. 全程只读查询，未点击保存或操作业务状态。前后完整组织绑定 SHA 均为 `0951dde21f4f0add3ce00a3d75717cdb465ec4cf77c353cb4b7f27ad9855c5ef`；门店 179 整行 SHA 均为 `44a102349f745847222d3f3ccc199f78cae4cf92e52ff2d87ba199e3ce67ff08`。未把 CLI 探针冒充完整 HTTP 权限覆盖。
7. 截图使用浏览器当时默认窄视口，不改用户视口设置；查询结果另由真实 DOM 核对。验证后关闭临时线上页，保留用户原本地页。平台窄屏旧布局、图片等非本轮内容未顺带整改。

## 归档清单

- 本文、`R7-store-organization-rh-manifest-2026-10-10.json`、`R7-store-organization-rh-online-check-2026-10-10.json`、`R7-store-organization-rh-2026-10-10.png` 为发布证据，独立归档。
- 生成物为只读构建输出及发布包，不混入业务源码；本轮无新增迁移或正式工程脚本。其他任务已有 9 个暂存文件及未提交改动保持原状。
