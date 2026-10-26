# R20 卡项退款商品分类修复固定版本

- 基线 HEAD：`03c5d841c38f2677a7c4789a62a6ca830a2db5cd`
- 范围：订单中心卡项退款缺少购卡分类快照时，通过退款卡号对应的持卡记录、原单、会员和平台商品 ID，读取平台当前分类完整路径。已有原销售分类快照优先；关联不匹配、商品已删除或分类链不完整时保持空白。列表和导出使用同一投影，不改退款事实和金额。
- 本地旧卡样本：`TK2610130002` 的订单中心完整查询返回 `生美/卡项`；本地 `TK2610130001` 是另一笔已有销售快照的退款，不与瑞昊同号记录混同。
- 瑞昊已知数据（只读核查）：`TK2610130001` 的旧卡没有 V3 购卡回执，持卡商品 `80554` 当前分类 ID `26332`，平台分类路径为 `生美/卡项`。本轮未部署，不能把本地测试称作瑞昊线上验收。

## 文件清单与 SHA-256

| 类别 | 文件 | SHA-256 |
| --- | --- | --- |
| 后端源码 | `后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php` | `6e877d8753815f9225254e6f1bcf764a5e11316d1423c2afbac1063c16a460ca` |
| 自动化测试 | `tests/cashier-v3/php/r20-refund-card-platform-category-mysql.php` | `9b67a049e3a24fb5625d2930e9767fa55335148ecb19247feafb4c91dbf90e71` |
| 自动化测试 | `tests/cashier-v3/php/r42-refund-product-columns-contract.php` | `a1a1b859935133ada5443b9824b95d571d24d517eabc17c10d149d3e2d9b312e` |
| 自动化测试 | `tests/cashier-v3/php/order-center-records-readonly.php` | `e2247813690092de6969be8ad51ba04e4d3c893967a2186e5d34e78e30086c32` |

- 以上已跟踪文件相对 HEAD 的补丁指纹：`65502da387fdc2dc64c87bae1f13699acbdc1f281f7d6fcdc1109eacb71ce808`。读模型文件原有一处未提交的补交销售人改动，不属于本闭环；提交时必须仅暂存本闭环代码片段。
- 前端源码：无。数据库迁移：无。工程脚本：无。生成物：无。
- 业务注释：读模型补充了旧卡平台分类的来源、关联约束、错误分类链处理与销售快照优先说明；测试更新了已过期的退款状态断言。

## 自测

- 本地 PHP 7.4 容器语法检查通过。
- 只读 MySQL 集成测试：旧卡持卡商品返回 `生美/卡项`；错误会员/原单不借用分类；已有销售快照不走平台兜底；旧卡 `TK2610130002` 完整列表投影返回 `生美/卡项`。
- `r42-refund-product-columns-contract.php` PASS；`r38-supplement-product-name-contract.php` PASS；`order-center-records-readonly.php` 44 PASS、0 FAIL；`git diff --check` PASS。
- 未执行 commit、远程部署、远程写库或 push。待产品经理对本固定版本明确确认验收。
