# R20 销售订单作废欠款提示：固定版本

- 基线 HEAD：`5e3f84008c928d97f72492d52bc729d59ad11cfe`。
- 完成范围：销售订单因关联欠款状态被拒绝作废时，提示同时说明“具体原因”和“需要怎么做”；独立取消欠款单独识别，明确引导先在欠款管理恢复欠款，再返回销售订单作废。
- 业务口径保持不变：独立欠款取消事实不随销售订单作废重复冲销；欠款须恢复为待还状态，才由整单作废统一生成反向事实。
- 产品经理验收：2026-10-19 明确反馈“测试没有问题”，并授权本轮 commit、部署瑞昊、push、再部署渼约008。

## 文件与指纹

- 后端源码 `后端代码/app/services/cashier/v3/order/CashierV3SalesOrderReversalServices.php`：`ca1612ee7da2478c5f95e956f6bcc133352c9c9eabcf049be1e2d12d91743cf3`。
- 自动化测试 `tests/cashier-v3/php/used-card-void-message-contract.php`：`4aed19c83ad41dd4ea699c648727276d0b9c8b40600958276a1cceb7c0438e41`。
- 前端源码、数据库迁移、工程脚本和生成物：无。

## 数据架构与验证

- 权威源仍为 `store_debt` 当前状态及欠款取消/恢复追加事实；页面不推算作废资格。
- `status=3` 仅映射为独立取消欠款，不改变事务、并发、幂等、冲销、权限或金额规则。
- 新增业务注释说明独立取消欠款必须先恢复，避免整单作废重复冲销免除金额。
- PHP 语法检查通过；`USED_CARD_VOID_MESSAGE_CONTRACT=PASS`；`SALES_ORDER_VOID_RESTORED_STATE=PASS`；`git diff --check` 通过。
- 本地收银 V3 后端重启后 `http://127.0.0.1:18092/` 返回 HTTP 200；产品经理已在本地页面完成验收。
