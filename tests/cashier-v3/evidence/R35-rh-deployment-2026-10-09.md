# R35 瑞昊部署与线上校验

- 产品经理确认：本轮本地测试无问题，授权 commit、部署瑞昊并 push。
- 源码基线：`b9547566d0a35db9fdff83e8c84fa77eefbc3fa5`；固定交付提交：`af5cba01`（销售小票）、`2cacd7e6`（订单中心）。
- 目标实例：仅瑞昊 `rh.cc3798.com`；正式站与同实例预览站静态入口同步。其他客户实例未操作。
- 数据库：无结构迁移、数据写入或连接生产数据库的验证动作。

## 交付范围

- 前端源码：`前端代码/cashier-v3/src/services/salesOrderReceiptPrint.js`、`前端代码/cashier-v3/src/views/OrderCenterView.vue`。
- 后端源码：`后端代码/app/services/cashier/v3/order/CashierV3SalesOrderQueryServices.php`、`后端代码/app/services/cashier/v3/order/CashierV3OrderCenterPartitionProvider.php`。
- 自动化测试：`tests/cashier-v3/js/sales-order-receipt-print-contract.mjs`、`tests/cashier-v3/js/order-center-sales-readonly-contract.mjs`、`tests/cashier-v3/php/order-center-sales-readonly.php`。
- 工程脚本与数据库迁移：无。生成物仅在隔离的构建目录生成并部署，不纳入源码提交。
- 本轮业务注释已随上述前后端源码更新；自测细目见两份 R35 自测记录。

## 部署与回滚点

- 先核对瑞昊远端两份 PHP 和正式/预览入口与基线一致，再从不可变的 `2cacd7e6` 归档构建。收银构建通过（1992 模块，保留既有 chunk 大小警告）。
- 独立备份：`/www/backups/rh.cc3798.com/20261009-r35-2cacd7e6-2PHt3C/`。`SHA256SUMS` 全部校验通过，三个压缩包通过完整性检查。恢复时还原该目录的两份 PHP 与正式/预览静态目录，并重启瑞昊 Swoole。
- 备份 SHA-256：后端 `8d84758dbcc0fd4d168dd36ad2483da195a8541bbd2ec76d8df7e34b7d782ee7`；正式站 `97e07a74196655b7f62d390ccc26acea7d9695b00ec11fbad3674a9caf2b54a4`；预览站 `df3006acdd4c7d2c2be65657924565481a3306fa530c7dbdcfbf1793e45c2469`。
- 先同步带哈希的静态资源和 PHP，PHP 语法检查通过，瑞昊 Swoole 重启成功；最后以旧入口哈希为保护原子切换两个 `index.html`。旧哈希资源保留以支持切换期间请求和回滚。

## 验证结果

- 本地：销售小票 JS 18/18、订单中心 JS 17/17、订单中心 PHP 50/50、根快照 PHP 10/10 通过；`git diff --check` 通过。桥接旧套件 35 通过、30 失败，其旧夹具与当前根契约/权限不一致，不能宣称桥接整套件通过。
- 瑞昊磁盘上的正式/预览 `index.html` SHA-256 均为 `790af461efcff0778227f0a0a36ed0d5521ceca13df2c3ed236a07827a884756`，与候选构建一致。
- 线上正式/预览 HTTP 入口均为 200；两站加载的 `index-494cf414.js` SHA-256 均为 `3dca1c7e37a6e180557a025cbb5f14090ed46a495a8355415191ba91dbff5666`，`index-c4b73ee7.css` 均为 `c4b73ee7aa9530bfe11b19c739e4a14ce095cfefb684e02510c031ca88081855`。
- 线上销售查询 PHP SHA-256 为 `79b2ff1de4ecc3039061ec3177cd8cd7e88b7b09da279d53d91b122f87df2e65`；订单分区 PHP 为 `4c447a1017a74c258d7d1d4a78711f263460b00c847a706f96274c89c74d3d5b`，均与候选一致。
- 瑞昊 Swoole manager 存活、端口 `20800` 监听；近期 200 行 Swoole 日志未检出 Fatal/Parse/Uncaught 错误。
- 独立预览标签进入登录页，未借用现有业务会话或执行登录后的业务操作；线上验证限于入口、资源、部署文件和服务健康，不宣称真实交易路径复测。
