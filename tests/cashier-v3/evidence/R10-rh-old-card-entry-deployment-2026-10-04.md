# R10 旧卡录入：瑞昊发布记录

- 授权与验收：产品经理确认本地测试通过，要求 commit、部署瑞昊、push；另单独授权仅启用瑞昊生产库的旧卡录入记账方式。目标仅 `rh.cc3798.com` / `ruihao`，未操作 007、008、012。
- 固定业务提交：`e3ec84434e24c5c1fcac210c12f616418586ae73`（`R10-00001 旧卡录入权限与非现金结账闭环`），父提交 `60b5776be9f46d73335f9d38bd3d00201f88497b`。17 个精准文件，工作区其他未提交改动未纳入。
- 构建：从该 commit 归档门店端、库存共享模块及统一查询源码独立构建，Vite 1989 modules 通过；未从脏工作区构建。正式入口 HTML SHA-256 `98b21e729fcd351748389fb3d3533dac619536a1624d636be09aa8773825e5a5`，主 JS `ed82d5b38891cd2f2c7249dc48575b73baf077bced45c000fecf47afe3da3291`，CSS `7e29ed893bab8e7bde96e68498a1415382010b7f77c7125114d0247e3261bc56`。生成物不入 Git。
- 发布前核对：瑞昊正式与 8080 预览入口均为前版 HTML 指纹 `24644dfb7fb37e0f3cc75ad4bfd6629dff823ad760e23980cc09d483afdc9bfe`；10 个待替换 PHP 文件逐一与父提交一致。PHP 7.4.33；Swoole 单 manager。最新登记迁移为 `20261004-001-order-attribution-correction`，本轮无数据库结构迁移。
- 独立备份：`/www/backups/rh.cc3798.com/20261004-r10-e3ec8443/`。后端 `backend-before.tar.gz` SHA-256 `453d1abd50cc37586007272bea58b6527d24ed40d893b5a1214f35d8b38f7c87`；正式前端 `cashier-formal-before.tar.gz` 为 `6e6a3cbeda686be604de8a266f2134e1ee8ac95af0f0dac7eda5ee8c6a006514`；预览前端 `cashier-preview-before.tar.gz` 为 `7d46f8bc53e10295470d9afe8825c45e68ef966cbe6bd2cb4d6871cda1687d00`；全库 `ruihao-before.sql.gz` 为 `ee62b1fbf8ddd3c4e166e4c5f83c6db8486cc1b492900e3f79595bd1d47db3c9`。各包 `gzip -t` 通过。数据库恢复前必须保护备份之后的经营数据，不能直接覆盖。
- 部署：新后端包 `b404e43212b1675b0a63a1c02cee64f3564726f0e92a5130243932ac36da3020`、门店前端包 `41309ebf7c7890707f57d3a8bc234bc154d5831870669c80d292413c6ef138cb` 上传后校验一致；10 个 PHP 文件在瑞昊 PHP 7.4 下逐一语法通过，只替换对应文件。正式及预览页面只追加哈希资源、原子替换入口 HTML；重启瑞昊 Swoole。预览 Nginx 自动增加 `/preview-8080` 资源前缀，因此预览磁盘入口使用原始构建 HTML，由 Nginx 单次改写。
- 配置：瑞昊 `eb_cashier_v3_payment_method_config` 中仅 `old_card_entry` 由 `status=0,version=1` 更新到 `status=1,version=2`，其他方式及其他实例不变。同一事务写 `eb_cashier_v3_business_config_audit`，幂等键 `R10-e3ec8443-rh-old-card-enable`，审计前后状态分别为 0 和 1；配置启用仍受岗位显式权限约束。
- 上线只读验证：10 个运行 PHP 文件与暂存包 `cmp` 一致；正式及预览磁盘 HTML 指纹均匹配构建，公网两入口和主 JS、预览 CSS 均返回 200。预览公网 HTML 经 Nginx 单次改写后指向可访问资源。Swoole 只有 1 个 manager；匿名 checkout-catalog 返回既有结构化“请登录”，非 5xx。重启后时间窗口未见本轮新增错误；未在生产创建真实旧卡订单。
- 数据架构自检：销售主单、卡项凭证与欠款单仍是权威源；成功结账同事务生成销售事实，旧卡金额仅是历史价值结算，不生成 `payment_collected` 或现金业绩。幂等、事务和作废沿用收银 V3 链路，现金退款明确禁止；历史快照、门店与岗位权限沿既有契约。未新增报表或数据迁移。可用销售单、付款草稿、收款批次、欠款单及卡项凭证做精确对账。
- 已知限制：本地成功单 `XS26100400011` 已完成数据库对账；生产只做只读验证，未用客户真实数据结账。结果页旧卡条目“待确认”与已结算状态不符，属待单独确认的页面显示问题，不在本次发布中擅自改动。
- 回滚：先核对发布后的订单与配置使用情况，再从独立备份恢复应用文件并重启 Swoole；若需停用旧卡方式，按配置版本受控更新并保留审计。不得直接以全库备份覆盖发布后的新增经营数据。
