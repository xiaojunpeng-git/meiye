# R58 第15轮：按批次重复添加与导入库存

状态：开发与自测完成，待产品经理对本固定版本验收；本项未 commit、部署或 push。上一已验收闭环已提交 10c6a54f，本项基线相同。

## 已确认口径

同一商品规格可以按不同批号增加多行。新增“导入库存”，将现有批次的数量、批号、单价、生产日期、到期日填入盘点草稿；不直接入账。批次实盘只调整所列批次，未盘批次不改变。旧商品总量盘点继续兼容，同规格不能混用总量与批次方式；同批号不能重复结算。

成本无权限时接口返回 null、草稿留空，已有批次结算由后端读取真实成本；新批次盘盈仍需有效成本与日期。重复导入不覆盖用户已录入实盘。总量行已有实盘时不会自动改为批次行，提示先删除该总量行，避免静默丢失填写。

## 数据架构自检

1. 权威源：当前仓 inventory_stock / inventory_batch；分页附加当前批次是编辑器只读准备，不以历史查询截止日冒充当前余额。
2. 事实粒度：一张盘点单的一条商品规格+批号调整，现有移动事实复用；按总量旧命令保留 FEFO。
3. 生效/时间：导入、草稿不产生正式事实；完成时在原事务创建单据与盘盈亏事实，业务日与真实入账时间分离。
4. 事务/并发/幂等：按原仓范围锁商品、库存、批次并保护版本；一个幂等键整单只入账一次；批次差异与商品总余额同步更新。
5. 历史：不重写历史流水，复用盘点明细既有批次快照列展示批次盘亏；旧已确认草稿原命令重放不变。
6. 权限：门店默认仓从登录态强制确定，总部仓由已有可写仓鉴权；成本按原库存成本权限控制；不能按客户端输入扩大范围。
7. 复用：两端共享编辑器、批次准备服务和确认服务，没有第二套库存算法；正式列表/成本继续读已有事实。
8. 性能/迁移：目录按50规格分页补批次，每页与整次导入超10000批次明确失败、不截断；无新增数据库结构或API路由，不产生新权限迁移。
9. 对账：测试比较各批次余额与商品总余额、未盘第三批次保留、重复请求无新入账，并核对草稿恢复与成本权限。

## 文件分组与业务注释

前端：InventoryBusinessModal.vue、countWorksheet.js、inventoryApi.js、utils/countSubmission.js。
后端：两个目录 controller、InventoryCountBatchCatalog.php（新增）、InventoryErrorMessage.php、两个目录服务、InventoryStockCountDetailProjectionServices.php、InventoryStockCountDraftServices.php、InventoryStockCountServices.php。
测试：下列 SHA 清单最后5项。
数据库迁移/工程脚本：无。
生成物：inventory-vue3/dist 本地 Vite 产物，不是8080集成包。
证据：本记录和 R58-batch-count-import-2026-10-02.png。
上述新增/改动业务文件均补充了粒度、库存扣减范围、草稿边界、隐藏粒度往返、成本权限或兼容约束注释。

## 自测结果

- 隔离库 headquarters-count-draft-integration：21 PASS / failed=0，所有测试业务夹具和事件事务回滚。
- 关键用例：同规格 A/B 分别盘亏，C 不盘保持3；商品总余额等于批次余额；同批号/混合粒度整单拒绝；当前批次带出资料且无成本权限不返回单价；多批次草稿恢复、确认、幂等重放；原门店组织身份草稿继续通过。
- JS：count-batch-entry、count-submission、count-worksheet、count-physical-authority、count-draft-list-visibility 合同测试均 PASS。
- 工作簿：旧12列文件与1001行往返保持兼容，批次文件使用隐藏粒度列保护多批次，前导零批号/条码、日期及金额数值经过往返验证；未创建用户交付工作簿，仅维护既有业务导入导出代码与测试。
- 9个业务PHP文件在实际本地容器PHP链语法检查通过；git diff --check通过。
- Vite build通过1821模块（既有大chunk提示）。最后补充选择器接线后再次构建通过；其后只改共用错误提示文字。
- 真实平台页面：新增按钮可见，导入5批次、数量/批号/单价/生产日/到期日可见。将首行实盘89改88后，再次导入新增0行，88保持。未保存、未完成盘点；测试标签关闭，用户原标签保留。
- 门店端独立测试标签进入登录页，没有代登录；不冒充门店真人路径验收。两端共用源码/后端逻辑已经合同与隔离库回归，需产品经理补验18091实际路径。
- 本地18086旧模板缓存导致初次看不到新增按钮，核对原平台标签处于列表无未保存编辑后仅重启该本地服务并验证新页面；未重启用户18091页面。

## 固定验收 SHA-256

基线：10c6a54f1a4f239e0bff2417dae1b1f3214570ca。其他任务脏改动全部保留，不在本项文件清单内。

```
1d0d45dad8e6dfa8aa138b99f3c71178ee405fb7dbfeb7140eca8b207d3740a7  前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue
ebd65f75fa735e100ed1cf2b23a514dfb40d5237b36a42a3fc6a3cb31e38b068  前端代码/inventory-vue3/src/components/countWorksheet.js
b3e694badf61b9778c74855e61463ea344f2a7369d6c374bf5db8ffb760771b8  前端代码/inventory-vue3/src/services/inventoryApi.js
00c4d6a8edd6eef64ec45f301f868b7b4225bf4dd1795743708e2b30ee979d23  前端代码/inventory-vue3/src/utils/countSubmission.js
afd360e6a53141b4fe1a0c09e62730fb106e1a09c9b4336de585eacf9e339667  后端代码/app/controller/admin/v1/product/inventory/InventoryPlatformHqCatalog.php
237297daff56dcd4fb42595175fb39c907967980c7e921301cdb5924ad0e1e06  后端代码/app/controller/store/product/inventory/InventoryStoreCatalog.php
49db2107c34d0b5fe3bdabea620bc0e6ba6aa808fc04a983537ed99c3b900aef  后端代码/app/services/product/inventory/InventoryCountBatchCatalog.php
ccf47cd41622f2eb4588d5500b6b64af8494dcfc85b30bf1730e8b7fd228c61c  后端代码/app/services/product/inventory/InventoryErrorMessage.php
c44b6a987879cadbfe2ea3ffc9ddee6da1df7f442e43dd1a7ea6f73c5f13b79b  后端代码/app/services/product/inventory/InventoryPlatformHqCatalogServices.php
923bb139a9e25c08cc9002e8f03e7055b196e4aee7dffb1ade36103504b71bf2  后端代码/app/services/product/inventory/InventoryStockCountDetailProjectionServices.php
9d673849fc5ce91cd3db7473801a0ca82806f0c08a07f49771f1438a5456cbc0  后端代码/app/services/product/inventory/InventoryStockCountDraftServices.php
9b3ec1f89a9b463c5d3e6136a8224e5e103d50f1bc77ede8accccb9107c3df29  后端代码/app/services/product/inventory/InventoryStockCountServices.php
a53f78a2376845851ee08930b041b912d5f2361877f7b34538f883dfdef400cf  后端代码/app/services/product/inventory/InventoryStoreCatalogServices.php
39a4dc77bf6d8db307a58687ee0904a86c6687b1991839b8c7aec2114ac7c971  tests/inventory/js/count-batch-entry-contract.mjs
26175269fca9e571295a7a82181a1dcd26a95428c3ee9ab39e68a1ee2618a7e6  tests/inventory/js/count-physical-authority-contract.mjs
c14729d685b55b102f85f6c3c9ab776de00472403a12ed84883d1b6d699c6c96  tests/inventory/js/count-submission-contract.mjs
2075174b71d58bce5fc1b10595a533bd12351ab8b4860e43687dcbe076c344d7  tests/inventory/js/count-worksheet-contract.mjs
68be070bd4057a1e23522bfa8160d13ebba367706cdd57e9d7be96f1e21a0c86  tests/inventory/php/headquarters-count-draft-integration.php
```

