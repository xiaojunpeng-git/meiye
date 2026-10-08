# D3-00003 瑞昊薪资当前商品分类发布记录

## 授权与提交

- 产品经理于 2026-10-09 明确确认本轮测试无问题，授权 commit、部署瑞昊、push；业务口径为所有历史薪资记录通过实际商品读取当前分类，无有效分类则留空，不按旧分类快照猜填。
- 功能提交：`c48f47b49d9e5910aaab67c1dd010a4f977438e4`，信息 `D3-00003 薪资统一读取当前商品分类`，父提交 `1fc28c4e5a4718b8e199306cffa18c26249607f3`。
- 固定版本、自测、注释和架构检查见 `D3-current-product-category-selftest-2026-10-09.md`。提交前八份源码/测试 SHA-256 和补丁指纹与产品验收版本全部一致；重新通过 PHP 7.4 语法、30 项统一分类回滚、23 项目录退款分类回滚及服务合同测试。
- 精确文件提交共 10 份；使用路径限定提交，其他任务原有九份暂存文件保持原状。旧服务补读 patch 仅作为本地历史证据保留，未纳入功能提交或部署。

## 实际文件边界

- 前端源码：无。
- 后端源码：`后端代码/app/services/report/StoreUnifiedReportPhaseSixServices.php`。
- 数据库迁移、生产历史回填：无。
- 自动化测试：`tests/report-phase-six/php/` 下 `d3-salary-current-category-rollback.php`、`d3-salary-refund-catalog-category-rollback.php`、`d3-salary-service-product-category-rollback.php`、`d3-salary-sale-category-readonly.php`、`salary-document-column-integration.php`、`salary-later-reversal-integration.php`、`service-contract.php`。
- 工程脚本、前端构建生成物：无。无需重打门店端或平台端包；不覆盖工作区未提交前端或其他后端文件。
- 验收证据：上述当前固定版本记录、已明确标为被替代的 `D3-service-product-category-selftest-2026-10-09.md`，以及本发布记录。截图为用户可见产物，不作为源码。

## 实例预检与备份

- 唯一目标为瑞昊 `rh.cc3798.com`，服务器 `47.96.103.107`，运行目录 `/www/wwwroot/rh.cc3798.com`，数据库 `ruihao`；PHP 7.4.33、MySQL 5.7.44。其他客户未操作。
- 线上报表旧指纹 `f40073acd377b5c65925dcf0cfec34c6585c076310e66b05155c81e111b7273f` 与功能父提交中的文件逐字一致，不依赖服务器遗留 Git 状态。
- 最近迁移已登记 `20261009-002-r32-unlinked-catalog-refund`、`20261009-001-r32-refund-salesperson`，代码版本 `f7de3759`。本轮无新增迁移，不修改迁移日志或业务事实。
- 独立备份目录 `/www/backups/rh.cc3798.com/20261009-d3-c48f47b4/`：旧文件包 `report-before.tar.gz`，只读查询基线 `report-before.json`，发布后对账 `report-after.json`、`verification.json`，固定提交生成的候选 `candidate.php`、`release-manifest.txt`、`SHA256SUMS`。压缩包 `gzip -t` 和六份文件 `sha256sum -c` 全通过。
- 发布前后 `.env` 和瑞昊 Swoole 启动脚本指纹分别保持 `b89eb945fa029d397a057428e9a7c69ff6610c6471e72b635af334452a6eee34`、`66f8ec29962987b72e11c6a32261e5cf1b47cea02480b17af7f9ac32c6ad5643`。配置、共享依赖、数据库、Redis、队列和计划任务未变。

## 部署与线上验证

- 2026-10-09 01:24:39 +08:00 从功能 commit 提取一份 PHP 候选；服务器 PHP 7.4 lint、候选及旧文件验签通过，在实例发布锁保护下原子替换，然后只重启瑞昊 Swoole。未更改正式或预览静态目录；二者共用本次后端服务。
- 部署后线上 SHA-256 为 `f938db5d5c37ac197980cea450be04db413ecfaa4feaf7c951f836ed3703e10a`，与验收及 commit 完全一致。20800 监听恢复，单 manager `23822`。
- 所有生产数据库验证均为只读事务；没有创建退款、订单或服务，没有改商品配置及历史事实。只读验证的首轮汇总基线取键与首轮通用导出调度不适用于薪资契约，分别修正验证调用后重跑通过；没有因此修改业务代码。
- 霍山文峰店（126）10 月 1 日至 31 日：189 行，现金业绩 `25596300` 分（255963 元）、项目数 181、消耗业绩 20965 元、手工费 127 元。发布前后除分类外的完整行内容哈希、员工汇总哈希、行标识及合计逐项相同，180 行分类投影更新。
- 10 月 8 日：9 行，现金业绩 169500 分；8 行分类投影更新，金额和次数不变。10 月 9 日生产样本为零行；本地 `TK2610090001` 的负向退款测试不能冒充生产存在该单据。
- 线上登录态现有标签页刷新，彭菲 1596 元从旧未分类桶归入“花园/花园产品”；点击该分类金额，下钻包含 `salary_category_cash_26321`、员工 663、门店 126 和原日期，恰好两笔 `XS26100800169`（1490 元）、`XS26100800196`（106 元），合计 1596 元；未分类下钻不再包含这两笔。
- 登录态取值说明显示当前商品分类及历史随配置调整的业务规则。关闭说明并返回后恢复原汇总日期与“彭菲”筛选；未关闭或改动其他用户标签页。
- 整月 189 行完整导出投影的列、记录、合计和版本与同口径完整查询一致，不被 100 行参数截断。浏览器点击导出未捕获下载事件，未取得实际 CSV 文件，不能宣称实际下载内容核验完成；页面无捕获到的控制台错误。
- 匿名报表 HTTPS API 返回 HTTP 200、业务码 `410000`（请登录），权限边界未绕过。
- 页面截图：`/Users/xiaojunpeng/.codex/visualizations/2026/10/08/01a11ad5-07da-78a0-b692-b49a248dc728/D3-rh-current-product-category.png`、同目录 `D3-rh-current-category-guide.png`。

## 回滚与推送

- 本轮未改库，应用回滚不恢复整库：先取得适用回滚授权及发布锁，核对当前报表文件仍为本轮指纹且没有后续发布，解压旧文件包到备份目录下独立暂存，核验旧指纹，再按 `www:www`、0644 原子恢复该单文件并重启瑞昊 Swoole；重新执行同口径只读对账。部署命令已有启动失败时的单文件自动回滚分支，本次未触发。
- 线上验证通过后，功能提交已快进 push 到 Gitee `origin/release/store-cashier-c14-main`，远端 HEAD 复核为 `c48f47b49d9e5910aaab67c1dd010a4f977438e4`。本记录随后以证据归档提交保存并推送，不增加部署源码范围。
- 其他任务的暂存、未提交代码及环境文件保持原状；不以工作区干净作为本轮完成条件，不把其他任务变更打包、提交或上线。
