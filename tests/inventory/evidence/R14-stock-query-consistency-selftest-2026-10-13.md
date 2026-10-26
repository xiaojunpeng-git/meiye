# R14 库存查询两端一致性自测

- 基线 HEAD：`5754b8b5a1c82d3e225825c826c3cfe16569d709`。当前工作区另有 R14 在途改动，本记录不代表这些改动已验收或已提交。
- 补丁指纹（下列已跟踪文件相对 HEAD 的 diff）：`98a2dfd729d17328367c3f291df5880c10e8c4dbc7c11fa6cdddf270385754e9`；新增分页测试另以文件 SHA-256 固定。
- 平台 Vue 2 宿主继续通过 iframe 加载唯一 `inventory-vue3` 源码；门店 Vue 3 宿主直接挂载同一模块。两端库存查询的商品名称、数量快捷筛选、查询/重置布局一致，平台保留授权仓库选择和查询设置。
- 两端库存列表使用后端完整命中总数、每页 20 条和同一分页栏；整表数量/金额合计继续取服务端汇总，不以当前页重算。切换仓库或重新查询回第 1 页，翻页保留已提交筛选。未改库存事实、金额算法、权限或数据库。
- 本地 Docker 挂载目录的 18086 Vite 服务启用文件轮询。重启该本地容器应用配置后，修改 `App.vue` 注释，不再重启，18086 编译模块即返回新内容；平台 iframe 刷新后可见新版分页栏。

## 固定源码指纹（SHA-256）

| 文件 | SHA-256 |
| --- | --- |
| `前端代码/inventory-vue3/src/App.vue` | `fcb65d098af774a5d25f412f277dc08a02de4b7d5a864bfca6ab174ed40ba16a` |
| `前端代码/inventory-vue3/vite.config.js` | `e656381b5393ca82713b787557fbcc512172e2f02c712d3f353563322d1f03c0` |
| `前端代码/shared/unified-query-vue3/src/components/UnifiedQueryToolbar.vue` | `5c0afa4fa33cb3d0b165629a33839aba3b89f0aee52bd38360821f0f17072f84` |
| `前端代码/shared/unified-query-vue3/src/styles/unified-query.css` | `de7d8274360541db24e75c618175c7fd0e080bb56f5857b5263a724d80a67bf6` |
| `tests/inventory/js/stock-settings-entry-contract.mjs` | `6ea7ff5558e8fdd5711431b94ec53e8a57f7fca3cf97c97bf1421b4ba4e44442` |
| `tests/inventory/js/stock-quantity-presets-contract.mjs` | `0092fce5e1960c3f60aa9b98d6e8b9f9a8ca7e7a77f6ef2ab17d18cce6c5592b` |
| `tests/inventory/js/stock-pagination-contract.mjs` | `91fd455ff92598d20f7392ed232f09c188fccb99911468cfd758436ec616fc09` |
| `tests/inventory/js/inventory-stock-list-scroll-contract.mjs` | `31c619e9062edc3711700efea21f54282de26f3d71629f55c749f8c440740325` |

## 验证

- `stock-settings-entry-contract.mjs` 17 项、`stock-quantity-presets-contract.mjs` 19 项、`stock-pagination-contract.mjs` 9 项、`stock-summary-row-contract.mjs` 7 项、`inventory-stock-list-scroll-contract.mjs` 5 项通过；相关文件 `git diff --check` 通过。
- `inventory-vue3` 与 `cashier-v3` 的 `npm run build` 通过；仅保留既有大 chunk 警告。未制作 8080 集成包。
- 门店 18091 真实页面：查询后总数 88、每页 20、第 5 页 8 条；翻页时合计数量 1820 / 金额 ¥88946.20 不变；空搜索 0 条且第 1/1 页，重置后恢复 88 条。
- 平台 18081/iframe18086 真实页面：单行查询栏显示仓库、商品名称、库存数量快捷筛选；切换到 `TEST-R14-三仓-A-20261013` 后查询返回 2 条，合计 1000 / ¥2198.80，分页第 1/1 页。平台与门店展示不同数量是仓库及权限范围不同，不是两套库存代码。
- 窄视口下现有库存工作台仍需横向滚动才能查看完整宽表；本次未改变现有移动端布局。未进行业务写入、远程连接、生产数据库操作、commit、部署或 push。

注释更新：`App.vue` 的服务端总数与翻页权限边界，`vite.config.js` 的 Docker 文件事件约束，以及分页测试的口径说明。

状态：开发与自测完成，待产品经理对上述固定版本确认验收。
