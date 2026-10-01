# R48 入库无效日期偶发提示修复自测（2026-10-01）

## 问题与根因

- 用户现场截图中的生产日期为 `2026/06/31`，6 月实际只有 30 天。
- Chromium 日期控件可能保留用户输入的无效分段供其继续编辑，但控件提交值为空；原页面只提交 v-model 值，因此服务端按缺失日期返回“请填写生产日期和到期日”。
- 正常日历日期不会触发该路径，所以常规自测难以复现。

## 修复范围

- 浏览器拒绝无效日历日期时，保留该控件的 `bad input` 状态到行模型。
- 保存前逐行校验生产日期、到期日、日历有效性和日期先后关系；无效时明确提示行号和具体字段，不发起库存写请求。
- 商品明细行改用规格稳定键，避免删除或重选商品后复用旧日期控件状态。
- 批量填写合法日期时清除对应的无效输入标记。
- 库存模块根容器统一拦截日期控件的键盘录入、软键盘输入、粘贴和拖放文本；日历点击选择、Tab 导航及日历打开/关闭按键继续可用。
- 该统一规则覆盖库存管理内当前及后续嵌套的日期控件，包括单据日期、商品批次日期、盘点日期和统一查询周期，不依赖逐页面重复绑定。
- 服务端日期校验、库存事务、幂等、批次事实与权限判断保持不变，仍作为最终权威门禁。

## 固定版本

- 基线 HEAD：`f0140ba049bc4565ec92c2cf9e8508472f8d1294`
- 前端源码：
  - `前端代码/inventory-vue3/src/App.vue`
  - `前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue`
  - `前端代码/inventory-vue3/src/inboundDateValidation.js`
  - `前端代码/inventory-vue3/src/inventoryDatePickerOnly.js`
- 自动化测试：
  - `tests/inventory/js/inbound-date-validation-contract.mjs`
  - `tests/inventory/js/inventory-date-picker-only-contract.mjs`
- 文件 SHA-256：
  - `App.vue`：`5495a1050f8b1c23565f5f70c8afc74b847963781fa9bea23c3d88435d1b4a49`
  - `InventoryBusinessModal.vue`：`afc5a6574fc866844540bb4b4ce254427fe38e0dd9f0166eac6375f0450e1c47`
  - `inboundDateValidation.js`：`094466e242c7567540cd16f54d2adbc95a9b800f03cc17875815a8bcf7d98b75`
  - `inventoryDatePickerOnly.js`：`1beab3eba641e5ecf4497eb59d8518aa3f14423eb59e42e2c191314fcc785d23`
  - `inbound-date-validation-contract.mjs`：`fc24757e8e00bf031cc2c9ea75b854eb59d8a926e3b2669d68156e06aa0a1c0c`
  - `inventory-date-picker-only-contract.mjs`：`0cbfa5f6be0def4c5e42b7192d0db716d04b920a9d7da61fd04157ce4c3de11d`

## 自测结果

- 入库日期校验合同：通过，覆盖有效日期、`2026-06-31`、浏览器 bad-input、缺失日期和到期日早于生产日期。
- 库存日期只允许选择合同：通过，覆盖数字键拦截、导航/日历按键保留、普通文本框不受影响，以及软键盘、粘贴、拖放拦截。
- 当前库存编辑器操作栏合同：6/6 通过。
- `inventory-vue3` 构建：通过，1821 个模块。
- `cashier-v3` 集成构建：通过，1986 个模块。
- 既有 `inventory-v3-c3-contract.mjs` 仍断言盘点按钮位于已经废弃的顶部操作栏，与此前已验收的按钮迁移冲突；本次没有修改该旧测试，也不把它记为本轮回归。

## 数据架构自检

- 权威业务源仍为服务端手工入库命令及其库存批次事实；本次不在客户端计算或写入库存结果。
- 事实粒度、生效事件、业务日期、记录时间、事务、并发、幂等、Outbox、冲销、历史快照和数据权限均未改变。
- 前端只提前拦截无效输入；合法请求仍由服务端重新校验日期、商品、批次、范围和库存上下文。
- 无数据库迁移、历史回填、报表口径、索引或远程实例变更。

## 当前状态

- 产品经理已针对本固定版本明确要求 `commit`，本地验收与提交授权已取得。
- 本轮只创建本地提交；尚未部署或 push。
