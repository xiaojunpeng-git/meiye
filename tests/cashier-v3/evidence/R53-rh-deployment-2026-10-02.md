# R53 服务记录来源与销售单号：瑞昊发布记录

- 授权：产品经理对固定版本回复“我测试没有问题,commit,部署瑞昊,push”。目标仅 `rh.cc3798.com` / 独立库 `ruihao`；其他客户实例未连接、未更新。
- 本地业务提交：`78248b17`（7 个精准文件）。隔离发布基线：瑞昊已发布的 R60 版本 `84339a57ec595584c551afb197e0635720f05316`；仅叠加 R53 七文件的发布代码提交：`db9b872458d21cceed4d6844ab416eaa2924f7e3`，分支 `release/r53-service-source-sales-no-20261002`。六个源码／测试文件均与业务提交逐字节一致，未带入工作区其他未提交改动或混合开发分支中的额外提交。
- 唯一源码正本为本机 `美容源码/`；固定 Git archive 位于临时构建目录 `/tmp/r53-release.AoxKmQ`，仅用于生成不可编辑的发布包，不作为第二套源码。门店端构建源对应 18091 正在使用的 `前端代码/cashier-v3/`，并以 R60 已发布的 `inventory-vue3`、`shared` 依赖构建；1988 modules，构建通过，保留既有大 chunk 提示。
- 数据库迁移、配置变更、历史数据回填、真实资金／核销写入：均无。瑞昊升级日志 `eb_database_upgrade_log` 发布前后均为 202 条，目标所需来源选择表及字段已存在；没有触及其他客户数据库、Redis、上传或支付配置。

## 部署前检查与备份

- 线上主机 `iZbp1fif4p5s7gj96nnec0Z`，站点 `/www/wwwroot/rh.cc3798.com/`，预览 `/www/wwwroot/rh.cc3798.com-8080/`；PHP 7.4.33，Swoole 单 manager，磁盘可用约 215G。
- 发布前后端文件 SHA-256：记录查询 `86197284509a04b97d45df50541e22c4d1ba36001d53237f4088503798d5abed`，统一查询字段合同 `f14b9277d17bd44e69c335c03f2d16763db00e45ba281eb2850052ea447f6298`；正式与预览 HTML 均为 `4fa2ddfba4975b396dec8b92e271b8c6b5b8be1326ad37c08a36138a869dcbd9`，与 R60 已登记现网一致。
- 瑞昊独立备份目录：`/www/backups/rh.cc3798.com/20261002-r53-service-source-db9b8724/`。`backend-before.tar.gz` SHA-256 `7324983aa139cde6d8cbae0270be3a9e53bb0c89617fec1546b3c70af07c91b7`；`prod-before.tar.gz` 为 `9f00cecf7e81c63ae572e3f5dfd0d25b6d29e0c0d0ab20e46b236c011a8a5c0a`；`preview-before.tar.gz` 为 `6a86ea1e77a76f5f5763d531da03f5cbf3840a9b67fdc767b029d2647828ec9e`。三个包 `gzip -t` 通过，后端包目录核对通过；本轮无数据库结构或业务数据写入，沿用瑞昊既有 ECS 每日快照。
- 远端暂存文件与隔离提交的 SHA 一致，两项 PHP 7.4 lint 均通过；启用前再次守卫现网旧指纹。

## 发布与线上验证

- 两套静态入口只追加哈希资源、不删除旧资源；随后原子替换两个后端 PHP 文件并仅重启 `/etc/init.d/ruihao_swoole`，再原子切换两处收银 HTML。未运行全量后端同步脚本，未重启 AI／Excel worker，也未修改服务器 Git。
- 发布后后端 SHA-256：记录查询 `9e83b09b63a693f2ce1aa3c69056ca9421cce2ee7f5aaa422b8daa0f6ae04586`，字段合同 `cdf09116a777562ddb2c09fe6a012abcd540b48ca43ba926d5783847a82ef944`。
- 正式与预览 HTML 均为 `67339cc3bff81e3186fb476acf02a37483bb5df46a5abd557fa960fa26094813`；两处主 JS `index-ad1c9f28.js` 均为 `f8d3289e08917f81ccf15ee7092a8500d2d88db58dc92bdd67400ea003dfb066`。正式和预览 HTTPS 页面、JS 均返回 200 且下载 JS 与固定构建指纹一致。
- 线上 `ruihao` 只读会话调用新记录查询投影：`FW100200162` 恰好 1 条，来源为“A老客首护”、同次销售单号为空（页面显示“—”）；这是同次结账来源选择，未用会员当前来源或原卡购买单号。查询未写订单／权益／业绩／支付数据。
- `.env` SHA-256 `24b6eef35143aed11786d9bdac4f53eb160fc610974c3fd4cb0b98463191ee82`、`config/swoole.php` SHA-256 `6578c0bde1be74fc99741289e83ff61976029bf8e1e416908253b931a91027de` 与发布前相同；Swoole 重启后 `manager_count=1`，manager PID `4775`；当日日志最近 150 行未见 Fatal/Parse/Uncaught。未通过已登录线上 UI 进行业务写入，产品经理本地页面验收见 R53 自测记录。
- 回滚：仅在目标文件仍为本 R53 指纹且无后续发布时，从本次瑞昊备份恢复上述两个后端文件和正式／预览 HTML，并重启瑞昊 Swoole；旧哈希静态资源保留，数据库无需回滚。不得覆盖 `.env`、上传目录或其他任务成果。
- 推送目标：仅 `origin`（Gitee `mhfz/rh`）的 `release/r53-service-source-sales-no-20261002` 分支；不推送混合开发分支、GitHub 或其他客户版本。线上验证通过后执行，远端最终 SHA 以 `ls-remote` 核对。
