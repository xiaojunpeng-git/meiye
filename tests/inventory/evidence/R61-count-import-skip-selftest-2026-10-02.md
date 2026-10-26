# R61 盘点导入跳过未匹配商品（产品经理验收通过）

## 范围与边界

用户确认：匹配不到的商品跳过，其他有效行导入，并提示商品名称及手动录入。只修改前端导入处理与自动化测试；没有后端、迁移或库存写入。保留门店目录权限，不按名称猜测替代 ID；有效商品的数量、日期、重复记录错误仍阻断并保持原表格。

业务注释已更新 InventoryBusinessModal.vue，说明跳过边界、权限保护和有效行原子更新规则。生成物：无。工程脚本：无。

## 固定版本

- 基线 HEAD：78248b17f8dc57efa4d2ea8f6975004975353b2c（并行任务的既有提交不属于本闭环）。
- 前端源码：前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue
  - SHA-256：cc2a8ad6ef476d980ee8b306c072aa1c5131643e1a7cf9ce6cb40b6eb5beb7bc
- 自动化测试：tests/inventory/js/count-flexible-import-contract.mjs
  - SHA-256：85239b59627392dfa5d7f9a20f99ee6e60ecb2f0321c822b2b194b372720318a

## 自测结果

- count-flexible-import-contract：通过；有效行与缺失商品混合、全部缺失、名称缺省 ID 提示、重复和数量失败回滚、不同批次兼容。
- count-worksheet-contract、count-physical-authority-contract、count-batch-entry-contract、count-submission-contract：全部通过。
- git diff --check：通过。
- 用户原始盘点资料：使用真实 Excel 解析器与组件真实导入方法，目录使用先前只读查询确认的江苏江都店 73 个商品 ID 快照；成功导入 73 行，跳过 1 行（602947），提示“​​一次性床单 防水防油款无法导入，请手动录入”。目录数量/SKU 使用模拟值，本项验证解析、匹配与跳过流程，不冒充真实库存写入验收。
- 已重启本地 cashier/inventory Vite 服务清除旧模块缓存；实际 18091 返回的组件模块包含新提示和“已跳过”逻辑。

产品经理于 2026-10-02 明确回复“我测试没有问题,commit,部署瑞昊,push”，本固定版本验收通过，并授权瑞昊部署及线上核验后的 push。未上传用户文件到生产、未保存草稿、未完成盘点。提交与部署结果另记发布证据。
