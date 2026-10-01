# R47 瑞昊库存合计格式部署记录（2026-10-01）

## 验收与授权

- 产品经理已确认本轮测试无问题，并明确授权 `commit`、部署瑞昊和 `push`。
- 本次只调整库存查询合计行的展示格式：数量按整数展示，金额按人民币元保留两位小数。
- 目标实例仅为瑞昊 `rh.cc3798.com`；未修改其他客户实例、数据库、后端服务或实例配置。

## 固定版本与文件边界

- 主开发线功能提交：`61b51349`（`R47-00002 库存合计数量金额格式闭环`）。
- 隔离发布功能提交：`7449d31d82db106b1abe63cfec80ecf9d4d9d282`，父提交为 `728eeb79bac9b99299784b4f344bc861fc554141`。
- 发布分支：`release/r47-stock-summary-20261001`。
- 本轮功能文件：
  - `前端代码/inventory-vue3/src/App.vue`
  - `前端代码/inventory-vue3/src/quantityFormat.js`
  - `tests/inventory/js/stock-summary-row-contract.mjs`

## 自测与构建

- 库存合计行合同测试：7/7 通过。
- 库存滚动合同测试：5/5 通过。
- `inventory-vue3` 构建通过。
- `cashier-v3` 隔离构建通过，共处理 1984 个模块。
- 构建源：Git 归档提交 `7449d31d82db106b1abe63cfec80ecf9d4d9d282`，未混入工作区其他未提交改动。
- 构建产物校验：
  - `index.html` SHA-256：`391718df223ed2dcc0b9863ac23bd88b894faab97f860b5e63cc20820864af6b`
  - `assets/index-fadaa627.js` SHA-256：`851edd185771b2579a66fdab7d7867da1ce95614f8fa178c828d3a0d053fe760`
  - `assets/index-7faf5b8e.css` SHA-256：`7faf5b8ebc3b4c76b7ca1bc635717d3057ed9f8c1be266c3e4df0077450b0786`
  - 前端发布包 SHA-256：`042417c0c5ea77ecb57ccd9b739871024c2c13bc4b7ddfb1bd2e7e94482dfbf1`

## 瑞昊部署

- 部署前生产及预览入口 `index.html` SHA-256 均为：`7ddd92be4f349d7b8901307c0b964b36f9cd30724732ae305db46ff1dc08cf55`。
- 备份目录：`/www/backups/rh.cc3798.com/20261001-r47-format-7449d31d/`。
- 生产备份 `prod-before.tar.gz` SHA-256：`9b0381bddc8a12fe5db6fa5c546c5fade27aaae7dbf20a2aca7490527eff1bcc`。
- 预览备份 `preview-before.tar.gz` SHA-256：`e83d69dec0dd94d785b8929a0347445be889554ac071013362decb193e47923a`。
- 两份备份压缩包均已完成完整性校验。
- 仅替换生产和预览目录的门店端静态产物；未重启后端服务，未执行数据库迁移，未修改配置。
- 部署后生产和预览磁盘 `index.html` SHA-256 均为：`391718df223ed2dcc0b9863ac23bd88b894faab97f860b5e63cc20820864af6b`。
- 生产和预览线上脚本均命中 `assets/index-fadaa627.js`，SHA-256 均为：`851edd185771b2579a66fdab7d7867da1ce95614f8fa178c828d3a0d053fe760`。
- 生产及预览 HTTP 状态均为 200，后端端口 20800 保持监听。

## 线上验证

- 在瑞昊已有登录会话中进入库存管理的“库存”页面，按真实页面路径读取合计行。
- 线上合计行实际值：`合计 / - / 2125 / ¥179983.00 / -`。
- 数量已按整数展示，金额已保留两位小数，符合产品经理本轮确认口径。
- 浏览器脚本仅用于页面导航和读取 DOM，没有提交表单或产生业务写入。

## 回滚

- 如需回滚，仅恢复上述备份目录中的 `prod-before.tar.gz` 和 `preview-before.tar.gz` 到各自静态目录。
- 本次没有数据库和后端变更，无需执行数据库恢复或后端回滚。
