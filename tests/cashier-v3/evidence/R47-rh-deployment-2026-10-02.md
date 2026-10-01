# R47 充值闭环瑞昊发布记录

产品经理于 2026-10-02 对本轮汇总明确“我测试没有问题,commit,部署瑞昊,push”。授权涵盖零本金赠金充值、备注、正常范围、销售人列表及编辑器回显、本地缺失数据库依赖对应的瑞昊补齐。只操作瑞昊实例。

## 提交与发布边界

- 本轮前期本地提交 `fd82afc02f76c8a70b3f81c909ef9af8bcdb767d`；剩余闭环本地提交 `7a51539a`（3 个后端文件、1 个合同测试、2 份自测记录）。
- 隔离发布提交 `965e9be8c542afa1cf357189d86641d4145c53f0`，父版为已推送库存 R51 的 `76a7d102`。该父版没有包含此前实际线上门店构建所用的完整 R43 前端，因此将前端相关目录固定为既有线上构建源 `29c3d1ef`，再叠加本轮两个已验收前端文件；与当前 HEAD 的 cashier-v3 前端无差异。此前 R43/R44 已部署的后端与证据按其提交补入发布树，保留既有功能。
- 本次实际替换后端仅 5 文件：`CashierV3RechargeCheckoutModule.php`、`CashierV3RechargeModule.php`、`CashierV3OrderCenterRecordQueryServices.php`、`CashierV3RechargePersonnelAdjustmentServices.php`、新增 `CashierV3RechargePersonnelSnapshotReader.php`。其余已部署 PHP 未覆盖，其他客户实例未操作。
- 主工作区其他 96 项脏改动未纳入提交或部署，主开发分支不整体推送。

## 构建与本地验证

从固定隔离提交 Git 归档生成临时构建输入，复用本地既有依赖；该临时目录只用于派生构建，不维护第二份源码。门店端 cashier-v3 构建通过，1986 modules。初次缺少 inventory-vue3 的 exceljs 依赖路径，补接该工程既有依赖后构建通过，源码未改变。

- HTML SHA-256：`1771a2edb5086f0e7bd8db7c50e29ea7a908da67982cc5faf684bf748f6fd295`。
- 主 JS `index-53010098.js`：`b9f454546483e2625a9e54939170d3172ce31d529a52d47bf3b12374a493f6e8`。
- 主 CSS `index-a766deaa.css`：`a766deaa53a5ffde7b8befd43e04b4f0f5288533458c5efe6d0e07744b488245`。
- 本轮前端合同 38 PASS，订单中心只读合同 40 PASS，充值后端合同及正常范围合同 PASS；源码指纹与已验收自测记录一致。

## 瑞昊备份、升级与部署

正式站点 `/www/wwwroot/rh.cc3798.com/`，预览静态目录 `/www/wwwroot/rh.cc3798.com-8080/public/view_cashier_v3/`；PHP 7.4.33，数据库 `ruihao`，MySQL 5.7.44-log。独立备份目录 `/www/backups/rh.cc3798.com/20261002-r47-recharge-965e9be8/`。

- `backend-before.tar.gz`：`45f882819f93ec3dc6d40a8c9a557c842144f1a5b0da8fab23b23bbbe649b841`。
- `prod-before.tar.gz`：`69f9839eab78741b1b55423c96dc52c86d0dcab86ea83f4463cbd2cd3054aec4`。
- `preview-before.tar.gz`：`0398a386bf7d232cb55da8006814b40f6d6fa5583b56f631bd8d8883d901ce20`。
- 受影响 6 表独立备份 `coupon-dependencies-before.sql`，1,319,960 字节，权限 0600，SHA-256 `6c1c88aeec570813938b8f3f1bd9e20d6eb6a3b94aa486656caf08b8a83861d5`。没有输出或提交生产凭证。三份代码/静态归档读取检查通过。

瑞昊同样缺失两个存储表及领取自增主键。按正本既有 `20260822-001`、`20260805-005` 执行升级，不回填历史。原 `20260822-001` 后置检查按索引列数计算，将一个单列和一个双列唯一索引算成 3 个而误报失败；只读补充核对 `COUNT(DISTINCT INDEX_NAME)=2`、InnoDB 和无非法行，实际结构通过。未为此改写旧升级正本或夹带工作区既有 SQL 修改。赠券领取升级前置/执行/后置均 PASS。原领取 5,022 行与原字段 CRC32 汇总 `11426057621196` 升级前后一致。

部署前再次验证线上旧文件与登记指纹一致；暂存五个 PHP 文件经生产 PHP 语法检查通过。后端逐文件替换；新静态哈希资源追加上传，两处入口 HTML 原子替换，旧资源保留。瑞昊 Swoole 重启后 manager_count=1，20800 监听正常。

## 线上验证与限制

- 五个线上 PHP SHA-256 与固定提交逐个一致；两处服务器 HTML 均为上述 HTML 指纹。
- 正式、8080 预览入口均 HTTP 200；正式、预览主 JS 均 HTTP 200 且 SHA-256 与固定构建一致。checkout-catalog 接口 HTTP 200。
- 生产代码只读验证：零本金赠 300 元且有备注的条款解析通过，缺备注被拒绝；充值正常范围查询通过；既有线上充值人员编辑 entry 读取通过。
- 发布后错误日志检查未发现 Fatal error、Parse error 或 Uncaught。最初远端没有 rg，改用 grep 完成检查。
- 未在线提交充值、发放券、作废或执行真实资金写入。整笔写流程验收依据为产品经理本地测试，线上验证覆盖结构、代码、静态资源及只读业务路径。

回滚代码时恢复旧后端及入口 HTML，并重启 Swoole；新增 reader 在旧调用代码恢复后不参与执行。数据库新增表/主键一旦承载新业务，不得直接删表或删除领取主键，也不盲目恢复备份覆盖后续数据，应保留追加事实并按既有迁移说明前向修复。

## 数据架构与文件分组

零本金充值仍只增赠金余额，现金业绩为 0；状态由主单与成功生命周期操作共同读取；人员身份用同门店/租户成功调整快照，业绩金额取有效事实；券领取映射及冲销审计使用 InnoDB、唯一键和原事务边界。未改变业务时点、统计口径或历史事实。

前端 2 文件；后端 5 文件；数据库执行 2 套既有升级；自动化测试与自测清单见本轮已提交记录；工程脚本无新增；生成物为固定提交构建的门店包；验收与发布证据为本文件。隔离分支 `release/r47-recharge-20261002` 在上述线上验证通过后推送 Gitee origin，并以远端 ref 核对结果；GitHub 未列入本次发布目标。
