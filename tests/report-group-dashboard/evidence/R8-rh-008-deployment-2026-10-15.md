# R8 集团看板排行与分类开关：瑞昊、渼约 008 发布记录

- 产品经理验收：第 8 轮商品名称排行和分类统计开关显隐均已在本地固定版本确认通过；随后明确授权本轮 commit、瑞昊部署、push、渼约 008 部署，并确认推送为新的 R8 分支。
- R8 源码提交：`b670ce2a`（卡内项目商品名称排行）、`11256d4395977aee74c22c0875e953778ec92255`（分类统计开关显隐）。部署以后一提交的树中两项后端服务为唯一来源，不包含共享工作区其它改动。
- 前端源码：本轮无。后端源码：`app/services/query/metric/RegisteredMetricReadServices.php`、`app/services/report/GroupManagementDashboardServices.php`。数据库迁移、自动化测试、工程脚本：本轮部署无新增。生成物：仅下述双文件发布包。验收证据：本文件与两份本地固定版本记录。
- 依赖：两站 `eb_store_product_category.statistics_enabled` 由单独验收、授权、备份、部署的第 57 轮建立。R8 在各站该字段就绪并核验后才切换；本轮没有代替第 57 轮执行数据库迁移。

## 固定包与回滚点

- 本地双文件包 `/private/tmp/r8-11256d43-group-dashboard.tar.gz`，SHA-256 `90c9be425d54a2ce72f1df2ce5393e196e0a0107eda2ed3fd66cab0dd78e37a3`。两站上传包同值，展开后 PHP 语法检查通过。
- 发布前两站上述文件旧指纹依次为 `f69155be8bc9689afa989e72fde60fcb83c31c1842986341419ed52c5ff7a4d8`、`61f2c7f09f2ee91d29e60e2deaf2f62839592d84f0126a126936adc10279f84e`；发布后依次为 `7b3b7900ce3909d1589a3adeb5b5e1cdc15ef01d92b764da926a31370b39fd3d`、`0249c6a92d9bcc1f41dc87d7abd1f3974f5a184410db6f70ae8390bb3c026787`。脚本在切换前后逐项校验，失败时恢复备份并重启目标应用。
- 瑞昊独立备份 `/www/backups/rh.cc3798.com/r8-11256d43-20261015/backend-before.tar.gz`，SHA-256 `65bec937762f530e19693216168adde4c1a6c28cc9a3cadae5a858b409d46168`；渼约 008 独立备份 `/www/backups/008.cc3798.com/r8-11256d43-20261015/backend-before.tar.gz`，SHA-256 `6ece445f1ae796418817a5af92748f2ab38267aec6c4e387ab6708573ca969fd`。两包均通过 gzip 完整性校验，目标站点的其它文件保留原状。

## 逐实例上线验证

1. 瑞昊 `ruihao`：第 57 轮线上核验完成后，前检确认统计字段存在；R8 发布脚本输出 `R8_DEPLOY_OK rh`，Swoole 重启并保持健康。只读业务查询以门店 68 验证：分类卡和筛选项均按开关返回，现金业绩卡与统一事实层一致；2026-10-01 至 2026-10-15 项目排行出现“仪器放松”“舒缓霜”“东方熏蒸”“指尖胶原”“桃花灸”等真实商品名称，不含泛称“卡项”。公网集团看板入口 HTTP 200。
2. 瑞昊门店端正式入口 `/view_cashier_v3/` 的 index、主 JS、CSS 指纹依次为 `4a241e1aaf71a85c2cfb0d4205b01a727fd6b0392c213e31f6b73a605b91f942`、`1d5c96bddd21f720a15c57fa10d3f4e4ab37ee81420b0a8fc8f728f94281cf00`、`a2148a5ab97d889721e90eeda6162793c39ddfbe559a2938747d647756166ceb`，均与发布前一致。预览入口 `/preview-8080/view_cashier_v3/` 的磁盘 index、主 JS、CSS 依次为 `fd8f0ddf0dea9c780ca9e766f7dc200a21f8b5ba5b0c117a7bba81b0d78f382c`、`648c2d6be2adf4f9ab43411f1177d3a751854b8ce26e5495a93202dda4b05722`、`978c81c4aa3c73d5d516c8302041ab687bd4751fe5e1c71c2f8b38c27bb220b6`，亦未改变。两入口和主 JS 公网均 HTTP 200；预览 index 公网指纹为 `a53497b05d0e976d2cac53a7c0a2be7968abbe49c752d3cdaba343998e5b02fc`，差异是网关将资源 URL 加 `/preview-8080` 前缀，主 JS 内容指纹与磁盘一致。
3. 瑞昊验证通过后，精确提交 `11256d4395977aee74c22c0875e953778ec92255` 推送为 Gitee 新分支 `release/r8-group-dashboard-20261015`，远端回查与该提交一致。原同名分支与其分叉历史未改动。
4. 渼约 008 `mohe_008`：第 57 轮线上核验完成后，前检确认统计字段存在；R8 发布脚本输出 `R8_DEPLOY_OK 008`，`mohe-008-swoole.service` 为 active。只读业务查询以门店 108 验证：6 个当前开启分类的卡和筛选项一致，现金业绩卡与统一事实层一致。当前采样日金额为 0，不能以该样本证明有金额的排行榜内容；真实名称分摊已由瑞昊线上及本地固定版本验证。008 公网集团看板和门店入口均 HTTP 200；门店端 index 指纹 `4a241e1aaf71a85c2cfb0d4205b01a727fd6b0392c213e31f6b73a605b91f942` 与发布前一致。

两站使用各自数据库、备份和应用重启；没有复制客户数据，也没有变更未点名的实例。发布验证不代替后续真实业务持续监测。
