# R53 服务记录来源与销售单号：本地自测交接

- 基线 HEAD：`9a7ccf7bc888f5691a1d80aff8c010e83559749d`
- 六个源码／测试文件补丁 SHA-256：`c525e73bc140b4443635ef4e0835870d805184f444ac397aa3aaa87e6c71ce3e`
- 验收：产品经理于 2026-10-02 明确回复“我测试没有问题,commit,部署瑞昊,push”，本固定版本验收通过，并单独授权瑞昊部署与线上验证通过后的 push。
- 本记录提交前状态：本地自测与产品经理验收通过；部署和 push 结果另记发布证据，不以本记录预先宣称完成。

## 业务口径和权威来源

- 服务来源先读同次销售订单冻结的来源；纯权益核销没有同次销售订单，读该结账请求保存的来源选择。两者都缺失才显示“—”，不回读会员当前来源。
- 销售单号只取同次真实销售订单号。纯权益核销本次没有销售单号，显示“—”；不借用原卡购买订单号或内部结账凭据编号。
- 列表固定为“服务记录号、来源、销售单号、业务日期…”，旧账号保存的可见列配置也自动补齐这两列；后端查询、筛选和导出沿用同一记录投影。

## 本闭环文件与 SHA-256

- 前端源码：`前端代码/cashier-v3/src/views/OrderCenterView.vue` — `8b80cf4496dc5ceb8b8f36a75c4ec4fc0689e8cefe7f827461eaa0b28794161a`
- 后端源码：`后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php` — `9e83b09b63a693f2ce1aa3c69056ca9421cce2ee7f5aaa422b8daa0f6ae04586`
- 后端源码：`后端代码/app/services/cashier/v3/order/CashierV3OrderCenterUnifiedQueryContract.php` — `cdf09116a777562ddb2c09fe6a012abcd540b48ca43ba926d5783847a82ef944`
- 自动化测试：`tests/cashier-v3/php/order-center-records-readonly.php` — `c17f84567299f9a6cddd586320255263d79422d5b2457df45a0050c6e504cc01`
- 自动化测试：`tests/cashier-v3/php/order-center-unified-export-contract.php` — `30f9e187005b3da4edfcdf271f24592bf919764dd16490fdab51e43c05ee6c52`
- 自动化测试：`tests/cashier-v3/js/order-center-sales-readonly-contract.mjs` — `7d9d35ab8651593a3f57d1f3bfff83aa15866de2db449675a5b34063d91fd748`
- 数据库迁移：无。工程脚本：无。生成物：本地 `cashier-v3/dist/` 构建产物（非源码、不纳入提交）。本文件属于验收证据，不计入上述补丁指纹。

## 验证

- `php -l` 两个后端文件：通过；`git diff --check`：通过。
- `php tests/cashier-v3/php/order-center-records-readonly.php`：42 项通过，0 失败。
- `npm run build`（`前端代码/cashier-v3/`）：通过；仅有既有大 chunk 提示。
- 本地 `ruihao` 只读实数核验（2026-10-02、门店 69）：纯权益服务 `FW100200004`、`FW100200003` 来源为“A老客首护”，同次销售单号为空；现金销售关联服务 `FW100200005` 显示 `XS26100200004`。按来源关键词／精确筛选分别找到 2 条，按销售单号筛选找到 1 条，均与列表一致。未执行写入。
- `order-center-unified-export-contract.php` 中本次新增的来源／销售单号断言通过；整套脚本另有两个与本次无关的销售日期旧断言失败。`order-center-sales-readonly-contract.mjs` 在到达本次断言前被既有 Toolbar 状态断言拦截；本次前端列顺序另外运行了定向断言并通过。这两套旧用例不能记为整套通过，待独立维护。
- 尝试读取本地 in-app browser 标签时连接超时；没有把构建与合同测试冒充为页面真人验收，需产品经理在固定版本页面核对展示。

## 注释更新

本次更新了 `OrderCenterView.vue` 的固定列兼容说明、`CashierV3OrderCenterRecordQueryServices.php` 的来源权威与销售单号边界说明、`CashierV3OrderCenterUnifiedQueryContract.php` 的纯权益空值说明。未发现本次替换后可安全删除的旧业务分支。
