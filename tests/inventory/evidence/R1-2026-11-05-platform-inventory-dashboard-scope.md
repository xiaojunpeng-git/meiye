# 第 1 轮：平台库存按集团看板数据权限读取

- 基线 HEAD：`90bc70410fcb5cd37f49307069c16dd6a9ec3fc3`
- 固定补丁指纹：`a406a4341fbe6d84344155a32a5e2fa410562d0cecf50bcad8332386b1b5b72d`
- 前端源码：本轮无修改。预售领用仍是 Vue 2 页面；请货等五个库存单据页是 Vue 3 独立工程，使用 Vue 3 组织选择器。
- 后端源码：
  - `app/services/product/inventory/InventoryPlatformAccessPolicy.php` SHA-256 `a4f05623dfb1e75459ece294ba3d6964004df46dbd495becb114220f7d8359ea`
  - `app/services/product/inventory/InventoryPlatformWarehouseServices.php` SHA-256 `3162d2c4a8bc26ea1e0ff89d37d0327abbacf7b7ff52bf2c4d927af2c8fd06f5`
  - `app/services/product/inventory/query/InventoryPlatformUnifiedQueryContextFactory.php` SHA-256 `79fab446e22a6ae8648cba3e23ec8b3b22648beda3098e760c3ed709b6c29583`
  - `app/controller/admin/v1/product/inventory/InventoryPlatformWarehouse.php` SHA-256 `990dac6ec92c36b3c5d8566407faeb7338dfc9eb1df20244325340806d6987c4`
- 数据库迁移、工程脚本、生成物：本轮无。
- 自动化测试：
  - `tests/inventory/php/platform-inventory-dashboard-scope-local.php` SHA-256 `382c8e0395394e0139c9f2fbb51d6243ed89b2f50a9c4a02f41d22e662e4c12f`
  - `tests/inventory/php/platform-operational-query-contract.php` SHA-256 `eab32e6f0f350771e4af6b322b7f41521de718843980eac45807d25f7ba256e4`
  - 已由新事务测试覆盖并删除旧的 `tests/inventory/php/platform-organization-store-read-contract.php`；引用扫描仅见历史证据，无执行入口。
- 核对的既有功能：总部仓读取保留；五个单据页组织/门店选择、预售领用列表与明细、库存查询、盘点门店查询和仓库候选共享员工数据范围；门店端原有范围与平台写权限没有提升。内置 `admin` 且 `level=0` 的账号保留全门店读取。
- 自测：PHP 语法检查、`git diff --check`、新增本地事务测试、五页组织只读集成测试、预售领用旧集成测试、库存查询旧集成测试、Vue 3 选择器合同测试、平台库存查询合同测试均通过。事务测试覆盖受限员工仅一店、越权请求拒绝、库存候选裁剪及 `admin` 豁免；事务已回滚。
- 另有旧 `tests/inventory/js/presale-claim-platform-contract.mjs` 的日期过滤和库存详情文案两项源码文本断言失败，所检查的业务文件均非本轮修改；本轮以实际本地预售领用集成测试验证权限路径。
- 状态：开发与本地自测完成；尚待产品经理对上述固定版本确认验收。未 commit、push 或部署。
