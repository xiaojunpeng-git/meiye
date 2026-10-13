# R16 权益服务明细备注丢失修复：本地自测与待验收版本

- 基线 HEAD：`4058395cbb68cb7b28003dd05d9f54042550c539`。
- 范围：只修权益服务结账快照转权威行时的明细备注传递，不改订单备注、历史订单或线上数据。
- 事实核对：本地服务单 `FW101300004` 对应的结账请求 `order_note`、权益草稿行 `detail_remark_snapshot`、服务事实 `detail_remark_snapshot` 均为空；该备注原文未保存，不能从显示层恢复。会员详情、卡操作记录、订单中心服务详情和销售订单详情均按服务事实快照读取，四处空值同源。
- 原因：`CashierV3CheckoutPreparationServices::entitlementSnapshotLine()` 把页面已保存的 `detailRemark` 转为权威行时未带出；销售行转换已有该字段。现在随权益行锁定备注，后续草稿、事实及详情沿用同一快照。

## 本次文件

| 分组 | 文件 | SHA-256 |
| --- | --- | --- |
| 后端源码 | `后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutPreparationServices.php` | `ee9ac5e3f0da2601a694bfc9151ffc3f89c198d9ddff7a5e743cc4ddcda296ef` |
| 自动化测试 | `tests/cashier-v3/php/cart-line-detail-remark-contract.php` | `d311b0202beb092892dee7356dcde5afcbceae98be96f73253be54e22e301f2d` |
| 自动化测试 | `tests/cashier-v3/php/checkout-entitlement-remark-transfer-mysql.php` | `825c7e860f8b14d0ae024546f0ae4655a4e91dcf2892ca73909f7d478adc6b69` |

本次没有新增前端源码、数据库迁移、工程脚本或构建生成物。前一轮已增加的会员详情两处“明细备注”列及读取映射保持原样，见 `R16-member-detail-remarks-fixed-2026-10-13.md`；本次只修其上游写入缺口。已补业务注释：上述后端转换文件说明明细备注必须随权益权威快照保存，供服务历史读取。

## 自测

- `php tests/cashier-v3/php/cart-line-detail-remark-contract.php`：9 项 PASS；同时更新了被其他变更推进的销售行可选字段顺序断言。
- `docker exec -i mohe-cashier-app php < tests/cashier-v3/php/checkout-entitlement-remark-transfer-mysql.php`：PASS。只读数据库集成测试，在事务中对有备注、空备注两种权益行调用真实转换方法，末尾回滚。
- `node tests/cashier-v3/js/cart-line-detail-remark-contract.mjs`、会员详情前后端契约：PASS。
- `php tests/cashier-v3/php/c2-checkout-settlement-contract.php`：93 项 PASS；现有 PHP 弃用提示不影响契约结论。
- `MEMBER_DETAIL_TEST_PHONE=...` 的本地会员详情只读对账：10 条服务、4 条卡历史，13 项来源比对通过，已有非空备注服务与购买记录均能读出。
- 本地界面只做草稿操作：权益行填写测试备注后，选择来源和手艺人、进入结账预览前备注仍在购物车；未点击最终服务提交，退出并清空测试草稿。
- `php -l` 与针对本次已跟踪文件的 `git diff --check`：PASS。
- 较大的独立 `c2-entitlement-completion-contract.php` 测试为 75 PASS、1 FAIL（`locked craftsman sequence owns primary and tail allocation`）；该测试不加载本次修改的转换类，本次不改动其分摊逻辑，不能声明完整回归全绿。

## 待产品经理验收

请在本地新建一笔可测试的权益服务：在该商品行“明细备注”输入可识别文本并保存，确认购物车显示后完成服务，再核对会员服务记录、卡操作记录、订单中心服务详情和销售订单详情四处均显示原文。已完成的 `FW101300004` 保持历史原样，除非后续提供原文并单独授权可审计更正。本版本未提交、未部署、未 push。
