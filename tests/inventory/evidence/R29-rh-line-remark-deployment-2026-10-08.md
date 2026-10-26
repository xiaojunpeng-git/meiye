# R29 库存单据明细备注瑞昊发布记录（2026-10-08）

- 授权与版本：产品经理确认本轮测试无问题，要求本地 commit、部署瑞昊及 push；另行明确授权瑞昊生产库四列迁移。功能提交 `91746e4c3391d831c18ab0189dac632295ba7d3a`，仅发布 `rh.cc3798.com`，其他客户实例未操作。
- 源码边界：从该提交的 Git 归档构建 `cashier-v3` 集成包，未使用工作区其他未提交源码。线上原门店端 HTML、JS 与功能提交父版本在相同构建参数下 SHA-256 完全一致；11 个目标 PHP 文件也与父版本逐一一致。
- 独立备份：`/www/backups/rh.cc3798.com/20261008-r29-91746e4c/`。四张目标表 SQL 备份 `b4e16457bc9f45eb1161d6bc79acf77a96060cc4c75e364e5f7b1cc2f743f6b9`，含四个 `CREATE TABLE`；目标 PHP、正式前端、预览前端各自的原包均通过 `tar -tzf` 和 `gzip -t` 检查。回滚先恢复目标 PHP 和两处原前端包并重启瑞昊 Swoole；新列可保留，避免发布后新增备注丢失，不做全库回滚。
- 迁移：只在瑞昊独立数据库 `ruihao` 执行 `20261008-002-inventory-document-line-remark`。四表 `eb_inventory_batch_movement_fact`、`eb_inventory_stock_request_line`、`eb_inventory_cross_transfer_line`、`eb_inventory_salon_usage_line` 均已新增 `varchar(500) NOT NULL DEFAULT ''` 的 `detail_remark` 列；执行结果 `APPLY_OK`，既有行备注为空。没有改写库存数量、金额或单据状态。
- 发布：11 个目标 PHP 文件经 PHP 7.4 语法检查后精确替换；正式与 8080 预览门店端分别发布同一构建包，原哈希资源保留。瑞昊 Swoole 经 `/etc/init.d/ruihao_swoole restart` 重启，单 manager 监听 `20800`。发布后 11 个线上 PHP 文件均与归档内容字节一致。
- 线上核对：正式和预览 HTML、主 JS 的 HTTPS 请求均返回 200。两处服务器 HTML SHA-256 均为 `082ff23515c7d5db62860c0fd0cbc188f7f5cc4a64cfec0aaad89df25673617a`；主 JS 均为 `9100c6ac4df263407d2dd1a0f6d901eb4b87fb2e8af01fd25fb8905a628523e6`，与本地构建一致。预览反向代理按既有规则将 HTML 资源路径改写为 `/preview-8080/`，所引用 JS 指纹仍一致。已登录预览页面刷新后可见入库明细表“明细备注”列，且不再显示“商品条码”列；未在生产创建或提交测试单据。
- 已知边界：线上验证为结构、文件、服务、HTTPS 与已登录页面只读冒烟；未执行生产单据保存与读取闭环，业务保存/读取已在本地专项测试和产品经理验收中覆盖。发布期间四张表仍有正常业务新增行，因此行数不是固定验收指纹。
- 文件分组：前端源码、后端源码、数据库迁移、自动化测试及自测证据详见 `R29-document-line-remark-selftest-2026-10-08.md`；本文件为发布证据，前端 `dist/` 与两处线上 `view_cashier_v3/` 仅为生成物，不是源码正本。
