# R5 瑞昊库存优化部署记录（2026-10-03）

- 授权：产品经理确认第 5 轮测试通过，并要求 commit、部署瑞昊、push。
- 本地闭环提交：`4a465c351bff2f6eaf93f71d149ef7d796282c19`（库存详情批次列）；`1e2ceff3bc79aab350c036c040a82888a9440561`（盘点零实盘预填）。
- 目标：仅 `rh.cc3798.com`，无数据库迁移或数据写入；`18091` 热更新源码未替换。
- 隔离发布分支：`release/r5-store-inventory-20261003` 为 `38e756c4880b982266dc710e9245950e57c4e64a`，基于线上门店端对应源码；`release/r5-inventory-module-20261003` 为 `3b56524187be7b7a35f08409870fd2c48f2c266b`，基于线上独立库存端对应源码。两分支均已推送并通过 `git ls-remote` 核对；未推送混有其他任务改动的主工作分支。
- 备份：`/www/backups/rh.cc3798.com/20261003-r5-inventory-1e2ceff3/`，含后端、正式门店端、预览门店端、独立库存端的变更前 tar.gz，均已通过 `gzip -t`。
- 上线后 SHA-256：后端 `InventoryStoreReadModelServices.php` 为 `75121b0736ad6e0c8880d59738ed92c90d7e46c2694d01f2c2cb10bf3acaeab8`；正式与预览门店端 HTML 均为 `e672afcef775407de80e53772758650dcdbcade19d23f13b834c3155d3c0b69a`；独立库存端 HTML 为 `9319e4bef3f983ad97af9cf4b1ad33150c798f91fd55a3e77416361fe56e14ba`，与隔离构建一致。
- 验证：远端 PHP 7.4 lint 通过，Swoole 重启后 `20800` 端口监听；上述三个公网 HTML 入口与对应 JS 资源均返回 HTTP 200；线上预览门店端登录页在浏览器正常渲染。
- 限制：未持有线上登录态，未执行登录后的库存业务操作。未登录请求新库存详情及未修改的旧盘点接口均返回同一 `Undefined offset: 1`，属于本次只读无凭据检查不能覆盖的鉴权路径；不把它记录为登录后业务通过。
