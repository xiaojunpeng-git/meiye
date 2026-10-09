# R49-00001 第4轮库存查询 V3：库存数量快捷筛选

状态：开发与专项自测完成，待产品经理对 V3 固定版本确认验收；未提交、部署或 push。

## 已确认范围与行为

- 门店“数量范围”改为“库存数量”，四段滑块顺序为大于0、等于0、自定义、全部。
- 默认及重置为大于0，保持原有正库存列表口径；选择前三种预设中的大于0、等于0，或全部时直接查询。
- 只有自定义显示最小值和最大值，支持单边界、零、小数、包含上下界；双空表示全部非负库存。录入后点击查询或回车。
- 切换预设忽略之前的自定义上下界，并清除区间错误；自定义草稿可在切回后继续使用。
- 保留本轮前两项：门店查询设置隐藏，查询紧随筛选字段、重置在查询右侧。平台标签、普通区间、设置入口及布局不变。
- 零库存指现有已结存批次汇总为零的商品，不为从未入库的目录商品虚构库存行。

## 文件分组与注释

- 前端源码：前端代码/inventory-vue3/src/App.vue；前端代码/shared/unified-query-vue3/src/components/UnifiedQueryToolbar.vue、UnifiedQueryNumericRangePresets.vue；src/contracts/numericRangePresets.js；src/styles/unified-query.css。
- 后端源码：后端代码/app/services/product/inventory/query/InventoryBatchStockUnifiedQueryProvider.php、InventoryProductStockSummaryServices.php。
- 自动化测试：tests/inventory/js/store-menu-contract.mjs、stock-settings-entry-contract.mjs、stock-quantity-presets-contract.mjs；tests/inventory/php/product-summary-quantity-range-contract.php、stock-quantity-presets-contract.php；tests/unified-query/js/query-actions-after-fields-contract.mjs。
- 数据库迁移、工程脚本：无。
- 生成物：inventory-vue3/dist/ 本地构建，非 8080 集成包；最终 index-ad7960dc.css / index-26b67e6a.js。
- 证据：本记录、R49-stock-quantity-presets-browser-2026-10-09.json、default/custom/detail 三张截图、V3 固定版本清单。V1/V2 保留历史记录，由 V3 替代当前待验收范围。
- 上述业务源码已补充门店限定、受控状态、非负数量口径、零结存源窗口、稳定整数键及防遮挡约束注释；专项测试说明了其验证职责。

## 数据架构自检

沿用已结存批次权威源及后端仓库权限，先按商品/SKU 汇总，再由统一查询执行数量过滤、排序、分页及完整结果合计。前端只生成条件，不过滤业务行或计算经营指标。显式数量条件包含零结存，未指定数量条件的旧调用仍使用原正库存源窗口。汇总行原文本稳定键与注册整数契约冲突，现改为最小来源批次整数 ID，商品/SKU 身份字段不变；扫描确认旧文本键无其他调用依赖。冻结导出沿用同一查询执行路径。无新业务写入、事务、并发写、幂等、冲销、快照或迁移变更。

仅使用本地 Docker mohe-cashier-app（PHP 7.4.33 / BCMath）及本地 mysql:3306 / ruihao 数据库验证，未连接或改变远程实例，无真实资金操作。生产目标、远程备份及回滚点不适用。唯一源码与当前热更新入口一致；基线及构建指纹见 V3 清单。

## 技术与浏览器自测

1. stock-quantity-presets-contract.mjs：19 项通过，验证模式顺序、默认、零值、小数、单边界、旧区间隔离、门店限定与控件标签。
2. stock-quantity-presets-contract.php：10 项通过，使用真实聚合器及统一查询注册、校验、执行链，覆盖零库存、正库存、全部、130-200 边界、先聚合60+70再筛选、完整结果合计、分页、权限外999排除、小数及无匹配。
3. product-summary-quantity-range-contract.php：7 项通过，新增整数稳定键、逆序一致性和零结存窗口验证。
4. stock-settings-entry-contract：13 项通过；query-actions-after-fields-contract：17 项通过；custom-filter-layout-contract、date-range-contract 通过。
5. unified-query-registration-contract.php：14 项通过；stock-summary-total-contract.php：8 项通过。两个修改的后端服务 PHP 语法检查通过。
6. inventory-vue3 npm run build 通过，1824 modules，保留既有大 chunk 提示；git diff --check 通过。
7. 真实页面自定义130-200返回一次性内裤130、一次性手套200，合计330；等于0返回两条零库存商品，合计0；全部及大于0的库存合计均为1822，原金额合计89300保持不变。
8. 自定义200-130显示“库存数量的最小值不能大于最大值。”；切换等于0后提示清除且输入框隐藏；重置恢复大于0、隐藏输入框、清除提示。
9. 桌面1624×1063和窄屏768×900验证控件无重叠、四段文字完整、查询与重置相邻；临时视口覆盖已恢复。截图保留自定义130-200的可见结果。

## 已知限制与验收边界

- V2 记录的数量范围 bccomp 报错已在 V3 修复并通过真实查询验证。
- 现有统一查询列表分页/条数展示未整改：查询后当前页最多20条，现有前端条数展示使用当前页行数；全部并不表示所有结果在同一页展示。后端完整结果合计由专项测试验证。本轮没有扩展分页改造。
- V1/V2 已记录的广域旧契约失败保留：store-menu 19通过/2失败、inventory-stock-list-scroll 4通过/1失败、shared-package 1通过/3失败、toolbar-context-actions 两项通过后旧字符串断言失败；此前均已在基线复现。gateway-envelope 缺少 fixture，不能计为通过。未用修改无关旧测试掩盖失败。
- 平台不变由限定条件与契约验证，本轮未操作平台真实设置；冻结导出复用同一路径，未另行下载文件。
- 工作区及暂存区存在其他任务改动，未暂存、覆盖或提交。开发自测不代替产品经理验收，不代表已上线。
