# R49-00004 零库存详情瑞昊发布与线上验证

## 验收与版本

产品经理明确确认“我测试没有问题,本轮commit,部署瑞昊,push”，验收范围为 `R49-zero-stock-detail-v1-2026-10-09`。提交前复核固定清单全部文件 SHA-256 一致，复跑数据库集成 17 项通过且所有测试夹具回滚；本轮已有 45 项回归及两款本地零库存商品实际页面验证。

功能提交：`8c32aad6f0edd3fb4e5ec85a81877a133cc863a2`，信息 `R49-00003 门店零库存商品详情闭环`，基线 `44dd39f9426e6bdfedbdc229fdc123d9af5067d9`。

精确提交 6 个文件：后端源码 1、自动化测试 1、固定版本/自测记录 2、本地截图 2。无前端、迁移、永久工程脚本或构建产物。业务注释随 `InventoryStoreReadModelServices.php` 提交。旧固定清单和自测记录保留验收前历史状态，实际验收及发布以本记录为准。

## 实例与发布

- 仅瑞昊 `rh.cc3798.com`，主机 `iZbp1fif4p5s7gj96nnec0Z`，运行根目录 `/www/wwwroot/rh.cc3798.com`，数据库 `ruihao`。
- 实查 MySQL `5.7.44-log`、迁移最新 id `215`；服务器遗留 Git HEAD `3254ecdc` 只作历史定位。部署前被替换文件与本地基线完全一致。
- 仅从功能 commit 导出后端文件 `app/services/product/inventory/InventoryStoreReadModelServices.php`。PHP 7.4 语法检查通过，22:29:50（Asia/Shanghai）原子替换并按既有单实例脚本重启本实例 Swoole。
- 新文件 SHA-256：`b03cd00c916130840311c173be4abb617239c34742089872c87c7576ac1024be`，与功能提交一致。
- 未重新打包或更换前端。preview-8080 的实际库存接口调用正式 `/storeapi/`，已通过浏览器实查；没有覆盖 preview 旧 PHP 源、启动其 20801 后端或调整 nginx。
- 正式/预览 `.env`、两个门店 HTML、nginx 配置、Swoole init、库存批次 provider 和门店范围 resolver 的前后 SHA-256 一致。未修改数据库、Redis、计划任务或独立 AI 进程；007/008/012 保持原状。

## 备份与回滚

本实例独立备份目录：`/www/backups/rh.cc3798.com/20261009-r49-zero-detail-8c32aad6/`，权限 0700。

- `InventoryStoreReadModelServices.before.php`：旧运行文件，SHA-256 `90ae64f8fab50826c58822971fb7284dd7a208a0a32b7544f4f162eb45c5d9dc`。
- `candidate.php`：来自固定功能提交，已校验指纹和语法。
- `unchanged-dependencies.sha256`：相关配置、前端和共享服务指纹，不含密钥内容。

本轮无写库或迁移，因此不新建数据库备份。切换脚本带失败回滚；实际部署成功，未触发回滚。若后续需要回滚，应先核对没有更新部署，再原子恢复此旧 PHP 文件并重启 `/etc/init.d/ruihao_swoole`，不覆盖配置或业务数据。

## 真实线上验证

1. 使用原已登录浏览器，霍山文峰店筛选“花园SPA生态平衡调理 / 全部”，查询返回 1 条、库存 0。
2. 点击原报错商品“查看”，详情正常显示总可用库存 0、库存对账正常、暂无当前批次，以及 3 条历史流水（调拨入库 10、两条库存调整出库各 5）。未新增或修改任何流水。
3. 原接口 `https://rh.cc3798.com/storeapi/product/inventory/v3/product-summary/117175/detail` 返回 HTTP 200、业务 status=200；商品 117175、对账 MATCHED、库存 0、当前批次数 0、历史流水数 3。
4. 正式运行代码的 READ ONLY 事务复查相同商品，确认库存 0、对账 MATCHED、3 条历史；无成本权限时所有流水单位成本/成本金额均为 null。
5. 同门店正库存商品 38927，数量 14、对账 MATCHED、当前批次非空。验证结束 ROLLBACK，迁移 id 仍为 215。
6. Swoole manager_count=1、PID=23089，20800 正常监听，最近 100 行运行日志 Fatal/Parse/Uncaught 计数 0；`/cashierapi/config` 正常返回未登录业务状态 410000。

线上截图：`R49-zero-stock-detail-rh-online-2026-10-09.png`。浏览器留在原报错商品的成功详情；没有签发新令牌、改权限、录入库存或执行真实交易。

## 推送边界

线上验证通过后准备执行普通 fast-forward push：`origin / release/store-cashier-c14-main`。远端推送前为基线 `44dd39f9`，待推为本轮功能及本发布证据，不推送 github、不 force push。

原有 9 个无关暂存文件在功能提交前后 binary diff SHA-256 均为 `dc31b057aca12a17ee75ed7a86e023bfeba79933849b91605faa54e209c95b46`。其他任务的脏工作区继续保留，不夹带、不清理。实际 push 回执与远端最终 SHA 在交付时报告。
