# 收银 V3 销售欠款行人员快照

- `upgrade_key`: `20260805-008-cashier-v3-sales-debt-line-personnel-authority`
- MySQL 5.6 compatible.
- 只为升级后新产生的 V3 销售欠款冻结逐行销售人快照。
- 不回填既有欠款；没有快照的欠款补交必须 fail-closed，禁止从整单现金业绩反推。
