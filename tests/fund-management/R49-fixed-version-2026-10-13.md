# 第49轮费用收支单与台账分页固定版本

## 固定范围

- 收支单录入按单据分组展示明细，后端固定每页 100 张单据。
- 收支台账保持原表格样式，后端固定每页 100 条明细。
- 两个列表统一使用“总数、每页数量、当前页/总页数、上一页、页码、下一页”的底部分页栏，并固定在结果区底部。
- 台账分页先按完整授权结果计算连续余额，再截取当前页；导出继续使用完整结果。
- 本轮不包含数据库结构或生产数据变更。

## 基线与文件指纹

- 基线分支：`release/store-cashier-c14-main`
- 基线 HEAD：`87d1f4551a2e5869ddcbdfd0d289425e4b76d188`
- `前端代码/fund-vue3/src/App.vue`：`0fd0fb2a26564308d3689094d53d43e20ca52364e300d622baea22713f3321bc`
- `前端代码/fund-vue3/src/styles.css`：`90aaa599a3e03db075950a5619edbdc7ffc5dd8f1f604ee71561f173fa4ccf17`
- `后端代码/app/services/fund/StoreFundServices.php`：`5023b6d2e386c945749c1281cc4c4701eeff3968cd5008479a22821b12f88f05`
- `tests/fund-management/js/fund-vue3-contract.mjs`：`c019c0bc1c13917092fdb7496beea9a8ab42f63ed8fa8ec34409a6a3d48bc830`
- `tests/fund-management/php/fund-service-contract.php`：`9dfe73561e6d4130631e8e5220c6ab1186b9f29099689661c2071efdb2d82278`
- `tests/fund-management/php/revoke-audit-local-integration.php`：`2e4099b63f7b9213f7363f6b45b400fb4adb2325d6a58d76b514e5f34b597af1`
- `tests/fund-management/php/r49-document-group-pagination.php`：`8d6ac9526819be0bca7cae80cc9cf4d04e8066b15383f47645e80ba2a5131769`

## 自测记录

- 前端静态合同测试通过。
- 后端服务合同测试通过。
- PHP 语法检查通过。
- 第49轮本地 MySQL 回滚集成测试通过：213 项检查，事务已回滚。
- 前端生产构建通过。
- 浏览器验证：收支单录入与收支台账均显示固定 100 条分页规则，分页栏置底，台账表格样式未改变。
- 代码注释已同步补充或更新：`App.vue`、`styles.css`、`StoreFundServices.php`、第49轮集成测试。

## 验收与发布授权

- 2026-10-13，产品经理明确反馈“我测试没有问题”。
- 产品经理已授权本轮本地提交、部署瑞昊与渼约008，并在逐实例验证通过后推送。
- 发布必须从本固定版本提交构建；瑞昊和渼约008分别备份、部署和验证，其他客户实例保持不变。
