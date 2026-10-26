# R33 会员领用选店范围修复自测

- 基线 HEAD：`0b112a58b6416615471b12c39929dba3db063c5a`
- 固定版本文件哈希合并指纹：`75024fb66311c031bc5c560b6437171dba01663636594dea68a0a101701ffb72`
- 变更范围：门店领用控制器传递已认证选店会话；领用服务按当前所选门店读取，写入仍核验岗位功能并记录真实员工；补充回滚测试及更新过时静态断言。
- 前端源码：无修改。后端源码：`app/controller/store/product/inventory/PresaleClaim.php`、`app/services/cashier/v3/presale/CashierV3PresaleClaimServices.php`。数据库迁移、工程脚本、生成物：无。自动化测试：`tests/inventory/php/presale-claim-selected-store-scope-rollback.php`、`tests/inventory/js/presale-claim-platform-contract.mjs`。本文件为验收证据。
- 代码注释：控制器增加可信选店会话传递说明；服务层增加当前店读取与写入岗位权限、真实员工审计边界说明。
- 数据口径：权威来源仍为原预售/赠送可领用事实；查询只使用已认证选店会话的 `store_id`，不扩大至其他门店；不改变事实粒度、金额、统计时点、事务或原有幂等规则；无生产数据变更。
- 本地回滚探针：当前店预售及赠送列表与正常门店员工结果一致；详情可读；写审计身份为员工而非会话；只读会话可读不可写；其他门店、错误会话及过期会话拒绝。通过，测试事务已回滚。
- 静态契约：`node tests/inventory/js/presale-claim-platform-contract.mjs`，21 项通过。
- PHP 语法：控制器与服务均通过 `php -l`；`git diff --check` 通过。
- 浏览器：本地 `#/presale-claim`，霍山文峰店选店会话，查询从 `presale_claim_scope_denied` 恢复为 HTTP 200；页面显示当前店可领用预售共 236 条。
- 状态：开发与自测完成，待产品经理对上述固定版本确认验收；未 commit、未部署、未 push。

| 文件 | SHA-256 |
| --- | --- |
| `后端代码/app/controller/store/product/inventory/PresaleClaim.php` | `70a42976d5011094d484f3d1de7593173463c63d13b7c88b7a67c759180a8986` |
| `后端代码/app/services/cashier/v3/presale/CashierV3PresaleClaimServices.php` | `8386229c33ba7215e3b73bc1036457d68fccfdd3cf1f287d8cbae13c9a64e7dc` |
| `tests/inventory/js/presale-claim-platform-contract.mjs` | `22746c6d20819d9f4b21b700e2b76c23516c8abec6194ee193e697fc3e49b948` |
| `tests/inventory/php/presale-claim-selected-store-scope-rollback.php` | `7889e2a5a0928eebfbc397a4083b0ed461c73395bfe40086bd58389afcc9c336` |
