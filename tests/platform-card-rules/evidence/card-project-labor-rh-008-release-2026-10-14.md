# 卡内项目手工费：瑞昊与渼约008发布记录

## 验收与版本

- 产品经理已对本轮本地固定版本明确表示“我测试没有问题”，并要求 commit、部署瑞昊和渼约008、push；随后明确授权两站各自执行新增手工费字段和现有卡项目赋初值迁移。
- 固定文件及业务自测见 [本地固定版本](./card-project-labor-fixed-version-2026-10-14.md)。精准业务提交为 `2a2d379b1af7b0971db39df44ae01b417fc976f0`，基于推送前远端 `ce13fecd4e3011378d79cfbd56a506b00799f74c`，仅变更本轮 15 个文件。
- 共享工作区提交 `f344e5ee` 因并行暂存混入 `StoreUnifiedReportPhaseFourServices.php`；该报表文件未进入本轮发布包。`2a2d379b` 的 15 个业务文件与 `f344e5ee` 完全一致，报表文件未进入精准提交。数据库迁移日志在执行时使用 `f344e5ee` 作为 `code_version`，以本记录的文件指纹与精准提交确定本轮实际版本。

## 发布内容与备份

- 前端源码：平台端 `前端代码/admin/src/pages/product/productAdd/index.vue`。从固定 Git 内容构建平台端；`system.html` SHA-256 `dfdb29339f7119c07cbc25dc327d8db8f913265729b2bddb679a0f371e76375a`。两站只发布平台端 `public/system.html` 和 `public/view_admin/`。
- 后端源码：`StoreProductServices.php`、`StoreCardRuleServices.php`、`StoreCardRelatedServices.php`、`CashierV3ProjectLaborDefault.php`、`CashierV3CashierWorkspaceServices.php`、`CashierV3QueryEntitySelectorServices.php` 六个本轮文件。两站逐文件校验旧基线、发布后逐文件 SHA-256 与固定版本一致，PHP 7.4 语法检查通过。
- 数据库迁移：`2026-10-14-卡内项目手工费/02-正式升级.sql`，SHA-256 `a465ab7fe6b0051975ac9175606e7a5621f3746656fa116fe74a9d3a86ea565a`；两站分别备份、前检、执行、后检，日志键 `20261014-001-card-project-labor-fee` 各 1 条。007 与 012 未执行。
- 瑞昊 `ruihao:3306`：备份 `/www/backups/rh.cc3798.com/card-labor-20261014-enP4nMWq/`；整库 `full.sql.gz` SHA-256 `20bf7f10af89a1dda1dbb280cad04e58c1ebcf2100d4ed98b49a4150c703776d`，站点 `site-before.tar.gz` SHA-256 `4585acfc7c8a17c5cfa4fd13072edfadc97293b837cf6352de13f048f120a993`，压缩校验通过。
- 渼约008 `mohe_008:3307`：备份 `/www/backups/008.cc3798.com/card-labor-20261014-HQSMSCYI/`；整库 `full.sql.gz` SHA-256 `703dbe4f4f93b573a432de6627ca4f8ccf32d2046ec247b72013dcd61dd72e79`，站点 `site-before.tar.gz` SHA-256 `b9fe526b9d73720c8a019d7c69985c69c6f57c40600604bac7cf4cab8f477d6b`，压缩校验通过。
- 发布包 SHA-256 `61dfa59919c552078fff0c06c525ef4f30dad393f24c62dd088b478931fdf115`。仅含上述后端及平台端产物；未发布或改动收银前端、其他客户站点。

## 上线后核验

- 瑞昊卡项目关系 16,837 条，非零手工费 5,386 条、空值 0；008 关系 2,529 条，非零 0、空值 0。008 的 0 来自其当前项目通用手工费配置。两站各抽样/汇总只读查询完成；瑞昊非零样本卡 80279、项目 79588 为 400 分。
- 两站公开 `/admin/login` HTML 均为上述构建指纹；入口引用的四个 JS/CSS 资源字节校验相符；`/cashierapi/config` 和 `/static_admin/ie.html` 均返回 HTTP 200。瑞昊与008服务重启后正常运行。
- 瑞昊正式 `/view_cashier_v3/` 与预览 `/preview-8080/view_cashier_v3/` 均可访问；两入口 JS `index-e0cb9659.js` 指纹 `266bc4c0c104def8cca1ae8d5e2382f4e400fb36ecee8d9a27cad28b5866dd7e`，CSS `index-84d39724.css` 指纹 `84d39724ce944a3db85dba386c5de0ad2eedf32802a21b881747e0e710fbb395`。正式页面 HTML 指纹 `d28ce1949b06847622568f31c0c3d8045e22fd1762d5fd4b1440218e9328a17e`，预览经路径改写后的公开 HTML 指纹 `f963e5ed3b484588f68c79e1169dc1f715e2e8c993ba62fad60b891c76b746aa`；本轮两入口收银静态文件均未变更。
- 自动化测试：PHP 合同测试通过、前端合同测试 30/30、平台端构建通过。本轮没有真实收款或核销写入；已售权益动态读取已在本地真实持卡数据的回滚事务中验证。

## 文件分类与回滚

- 自动化测试：`tests/platform-card-rules/php/card-rule-contract.php`、`tests/platform-card-rules/js/card-rule-frontend-contract.mjs`。工程脚本：无新增。生成物：平台构建包（服务器发布，不入 Git）。验收证据：本文件、本地固定版本与截图。
- 回滚点：两站各自的 `site-before.tar.gz` 与 `full.sql.gz`；按站分别恢复，不交叉使用。迁移字段已由在线代码读取，回滚须同时考虑代码与数据库状态，不直接删除历史配置。
