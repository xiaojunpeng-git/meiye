# 市场体验服务不计人次：瑞昊发布与验证记录

- 授权：产品经理验收本地固定版本后明确要求“部署瑞昊,push”。目标仅瑞昊 `rh.cc3798.com`；未操作其他客户实例。
- 固定功能提交：`31b32bf36ecfcbf5f0cc3e11c80191d3582e222c`。本次运行变更仅为 `app/services/report/StoreUnifiedReportPhaseTwoServices.php`；无前端构建、配置、数据库迁移或历史数据回填。部署前 Gitee `origin/release/store-cashier-c14-main` 为父提交 `30cc66b9f195e51a2b2f995829a23092e919d3dd`。
- 实例自检：独立站点 `/www/wwwroot/rh.cc3798.com/`、独立库 `ruihao`，生产 PHP 7.4.33、磁盘可用约 208G、Swoole 单 manager；生产 `eb_cashier_v3_entitlement_service_fact.is_experience` 字段已存在，数据库迁移状态保持原样。本次不触碰其他站点、数据库、Redis 或配置。
- 发布前运行文件 SHA-256 为 `7ca5c7eb7e690443a1d99f8bdc42f940e5a3eb04f1390b82bbcb7593778bd484`，与功能提交父版本相同。独立回滚文件为 `/www/backups/rh.cc3798.com/20261004-market-experience-visits-31b32bf3/StoreUnifiedReportPhaseTwoServices.php.before`，同指纹；仅需恢复该文件并重启瑞昊 Swoole 即可回滚代码，不回写业务事实。
- 部署：2026-10-04 16:51（北京时间），先校验目标旧指纹，再上传固定提交中的单一 PHP 文件至该实例备份目录，核对暂存文件 SHA-256 `4ee32ad53d79318866049cf95fad9bd3f7c5622a252ab03cd723f802e2f99c0e` 且 PHP 语法通过，原子替换目标文件，重启瑞昊 Swoole。运行文件保持 `www:www`、`0644`；重启后单 manager、20800 监听。
- 发布后验证：生产运行文件 SHA-256 为 `4ee32ad53d79318866049cf95fad9bd3f7c5622a252ab03cd723f802e2f99c0e`、PHP 语法通过；外部瑞昊门店预览页、内部收银接口均 HTTP 200。线上只读查询门店 68、2026-10-01 至 2026-10-04：市场业绩表返回 1 行、市场明细表返回 67 行，两表均为 `metric_version=market-visits-non-experience-v2`。部署后错误日志未发现本次报表相关的 Fatal、Parse、Uncaught 或市场报表错误；未在线写入真实业务数据。上线前本地 PHP 7.4 容器的体验人次集成测试通过。
- 本文件是发布证据，不是第二份业务源码；运行变更只由上述固定提交产生。远端推送结果在执行后以 Git 远端引用为准。
