# R14 纯权益护理导购保存与重开核验

- 基线 HEAD：`ff1da82f802c5fc27c675c924859f7aecd08b68b`。本记录只针对本地 `18091` / 江苏金湖桓裕店，不代表生产部署。
- 故障：浏览器提交的权益护理行含朱延芳、第 1 轮；结账准备第 1 版草稿保留导购，但提交准备从草稿重建权益行时漏读 `guide_selections_json`，第 2 版草稿覆盖为 `[]`，导购事实未创建。
- 修复：`CashierV3CheckoutDraftAuthorityRebuilder.php` 权益行恢复导购选择；`c2-checkout-settlement-contract.php` 新增保存草稿后重建的断言。后端此文件新增注释说明重建边界；无前端、迁移或金额变更。
- 本地操作：原单 `FW101300006` 按用户要求作废；诊断期间产生的无导购测试单 `FW101300007`、`FW101300008` 均通过正常作废操作成功，各恢复权益 1 次。新单 `FW101300009` 正常，仅核销背部spa 1 次、来源 E地推拓客、无本次现金收款。
- 业务验证：新单结账行 `guide_selections_json` 保留员工 1273、第 1 轮；`cashier_v3_customer_guide_round_fact` 为朱延芳、第 1 轮、`effective`。订单中心同单导购列显示“朱延芳（第1轮）”。截图：`R14-pure-entitlement-guide-reopen-2026-10-13.png`。
- 自动化：`c2-checkout-settlement-contract.php` 96/96；`guide-round-fact-contract.php` 全部通过；修复文件 `php -l` 通过。相邻 `checkout-snapshot-direct-authority-contract.php` 为 46 通过、4 失败，失败涉及其静态源码断言（人员快照、销售人投影指纹、权益选择器等），未计入本闭环的通过项。
- 当前阶段：实现与定向自测完成，待产品经理对该固定版本确认验收；未 commit、未部署、未 push。

## 本轮文件指纹

```text
f33948e97672b03daf184c648da9e953b48d870a2924a4e92eeebebdc6bf0c6b  后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutDraftAuthorityRebuilder.php
f83f7e41150be95d190c796bb6474b368ea8687600d664320ba5ee803932e5bb  tests/cashier-v3/php/c2-checkout-settlement-contract.php
f6dc843dfb0051fb9680da3ff8d6734053efb67a89214df233298a7f13a558b2  tests/cashier-v3/evidence/R14-pure-entitlement-guide-reopen-2026-10-13.png
```
