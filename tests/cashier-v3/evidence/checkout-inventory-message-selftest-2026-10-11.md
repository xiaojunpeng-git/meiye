# 结账库存不足提示自测（2026-10-11）

- 基线 HEAD：`217200a11472c7942d5c2813c12a01c31a25de31`。
- 问题证据：本地收银结账日志的 `submit-checkout` 失败原因为 `sale_inventory_stock_not_ready`，原来向用户显示通用“正式结账未能完整落账”。本轮只改错误文案映射，不改库存判断、扣减、订单、收款、幂等或事务回滚。
- 业务口径：当前门店无可售库存记录和可售数量不足均提示“库存不足”；库存并发变化、库存数据不一致、库位未配置分别给出对应提示。非库存错误沿用原提示，原因码和详情保留供排查。
- 前端源码：无。现有结账界面直接展示后端结果消息，不改收银页面布局或交互。
- 后端源码：`CashierV3CheckoutInventoryFailureMessage.php`、`CashierV3SaleOnlyCheckoutSubmissionServices.php`、`ThinkPhpCashierV3CheckoutSubmissionExecutionPort.php`，均位于 `后端代码/app/services/cashier/v3/settlement/`。
- 数据库迁移、工程脚本、生成物：无。
- 自动化测试：`tests/cashier-v3/php/checkout-inventory-failure-message-contract.php` 新增无数据库的混合结账异常转换行为测试及纯销售路径映射引用检查；`tests/cashier-v3/js/guest-card-and-inventory-guard-contract.mjs` 更新旧库存文案断言。
- 注释：新映射类的职责、未知原因边界及无可售库存的业务口径；混合结账转换分支的状态保持约束；新测试的无事务边界。
- 验证：PHP 契约测试 11 个原因码通过；上述 4 个 PHP 文件语法检查通过；相关已跟踪文件 `git diff --check` 通过。JS 综合契约测试仍在其第 20 行旧 `isMemberRequiredOpen = ref(false)` 页面断言失败，未执行到本轮更新的库存断言；该失败不作为通过证据。
- 资金与数据安全：未重试当前结账，未写订单、收款、库存或挂单；未做真实结账联调。当前已打开的旧错误弹窗不会因后端源码修改而自行改变。
- 状态：开发与本地自测完成，待产品经理对本固定版本确认验收；未 commit、未打包 8080、未部署、未 push。

## 固定文件 SHA-256

| 文件 | SHA-256 |
| --- | --- |
| `后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutInventoryFailureMessage.php` | `e873c6bb8a94ae535748f8aeaa938875b17a6865945a8f02ca5d1a34a0d9c8e7` |
| `后端代码/app/services/cashier/v3/settlement/ThinkPhpCashierV3CheckoutSubmissionExecutionPort.php` | `1e92d64de321d8f7d3d468e76e52318be815d0cb2ae0aa52392f8818f1922ac5` |
| `后端代码/app/services/cashier/v3/settlement/CashierV3SaleOnlyCheckoutSubmissionServices.php` | `bb5ffc54b1bf552a70a105ce131880e1cd8884bb29aea05c560cd7af6b05aec6` |
| `tests/cashier-v3/php/checkout-inventory-failure-message-contract.php` | `99abbefeb65ef5ef6eb667de71801c303c5964ea822101a9382633255bbf57e3` |
| `tests/cashier-v3/js/guest-card-and-inventory-guard-contract.mjs` | `346ca11b3ff6408de864c2ecbc89cbf24cf6eb558cde428bdc00261c9bf01e46` |

本轮已跟踪文件的补丁指纹：`6115a5ad892a5bd55b062882ad93439048d534ab10f900de1f5a493422faa8ee`；新增文件以上述文件 SHA-256 固定。
