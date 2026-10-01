# R52 请货受理方本地自测（2026-10-02）

## 固定版本

- 基线 HEAD：`402c9948`
- 范围：总部供货请货单新增独立受理方目录、单据快照、创建/编辑选择、平台请货管理条件筛选。
- 明确不变：供货仓、库存归属、批次扣减、调拨履约、成本口径。
- 历史数据：本地 9 张总部供货历史请货单保持 `acceptance_party_id=0`、空快照，未伪造历史归属。

## 本地数据库迁移验证

- MySQL：5.7 本地开发容器 `mohe-mysql`，数据库 `ruihao`；未连接任何远程客户实例。
- 升级前检查：请货单表存在，历史单据 9 张。
- 正式升级：`APPLY_OK`。
- 升级后：目录表 1 张、单据字段 2 个；初始目录为“总部 / 办公室 / 采购部”。
- 对账：非总部供货错误受理方 0 条；有受理方 ID 但无名称快照 0 条。

## 自动化与构建

- `node tests/inventory/js/request-dialog-contract.mjs`：22/22 PASS。
- `php tests/inventory/php/request-acceptance-party-contract.php`：7/7 PASS。
- `php tests/inventory/php/store-request-transfer-command-contract.php`：7/7 PASS（仅第三方依赖 PHP 8.5 弃用提示）。
- `npm run build`（`前端代码/inventory-vue3`）：PASS。
- `npm run build`（`前端代码/cashier-v3`）：PASS。
- `git diff --check`：PASS。
- 库存全量契约前 83 项通过；在既有“手工入库详情成本可见性”断言处停止，该失败对应未改动的手工入库代码，不属于本闭环。

## 关键文件 SHA-256

- `App.vue`：`4f7c3622e242cbcd8932e3706c0858bcdf53f53f013052f269af5dc2f9061c37`
- `InventoryBusinessModal.vue`：`9512c7e49f96c3e869057e959eaeec3e7ed4f90d27de6e2d11781a9f7c2141da`
- `inventoryApi.js`：`ccb0a00d2c2a7df60e5a478a6fde86e28b1506bd8479aa9e12dbed6894dbc2bc`
- `InventoryStockRequestServices.php`：`cd580ff5619e3922fbfb5a35f27be52995076d9c85589bcc1f67d9ccfbffef7d`
- `InventoryStockRequestQueryServices.php`：`b1ddf085ca0686a74f79c9a97c853384d73885e2b046927f43c7784db7da85f3`
- 迁移包内部校验：`后端代码/database/upgrades/2026-10-02-库存V3请货受理方/SHA256SUMS.txt`。

## 产品经理验收

- 2026-10-02：产品经理完成页面测试后要求提交本闭环。

已验收范围：

1. 新建请货选择总部仓后显示必选“受理部门”，选项为总部、办公室、采购部。
2. 供货方改为门店后受理部门立即隐藏并清空，保存仍沿用原门店供货逻辑。
3. 请货管理将供货方筛到总部仓后显示受理部门筛选；切换其他供货方后隐藏并清空。
4. 列表与详情显示单据受理部门，历史单据显示未设置而不是伪造归属。
5. 新建单据日期按北京时间当天生成；保存后列表日期范围自动覆盖该业务日期，刚保存的记录不会被“今日”条件隐藏。
