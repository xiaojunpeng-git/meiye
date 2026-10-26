# R49-00001 第4轮库存查询 V2：操作按钮跟随筛选字段

状态：本项开发与专项自测完成，待产品经理确认验收；未提交、部署或 push。

## 已确认范围

- 门店库存查询：最大值右侧显示查询，查询右侧显示重置。
- 保留上一项已实现的门店查询设置入口隐藏；V2 固定版本替代尚未验收的 V1，旧清单保留历史记录。
- 共用工具栏增加默认关闭的显式布局开关，仅门店库存查询启用。平台及其他接入页保持原位。
- 收起筛选字段或没有第二行字段时，操作回到原首行，避免按钮不可用；展开后恢复字段尾部。
- 导出、收起/展开、查询条件和后端业务口径不作改动。

## 文件分组与注释

- 前端源码：inventory-vue3/src/App.vue；shared/unified-query-vue3/src/components/UnifiedQueryToolbar.vue；shared/unified-query-vue3/src/styles/unified-query.css（均位于前端代码/）。
- 自动化测试：tests/inventory/js/store-menu-contract.mjs、stock-settings-entry-contract.mjs；tests/unified-query/js/query-actions-after-fields-contract.mjs。
- 后端、数据库迁移、工程脚本：无。
- 生成物：inventory-vue3/dist/ 本地构建，非发布包；未打包或部署 8080。
- 证据：本记录、R49-stock-query-actions-desktop-2026-10-09.png、R49-stock-query-actions-detail-2026-10-09.png、V2 固定版本 JSON。
- 上述新增/修改的前端与专项测试均补充了范围、回退、避免遮挡或测试职责注释。

## 数据架构自检

权威源和事实粒度继续沿用库存查询后端。仅复用现有 submitQuery/resetQuery 改变渲染位置；业务事件及四类时间、事务、并发、幂等、Outbox、冲销、历史快照、后端数据权限、指标计算、分页、索引、性能方案均无变更。没有新写库或迁移，不连接生产实例；客户目标、远程备份/回滚不适用。本地源码基线和文件指纹见 V2 清单。

## 自测结果

1. stock-settings-entry-contract：13 项通过，包含门店开关启用与平台不开启。
2. query-actions-after-fields-contract：17 项通过，验证默认关闭、8 组显隐组合、字段后顺序、查询/重置复用原回调及首行互斥回退。
3. custom-filter-layout-contract、date-range-contract 均通过。
4. inventory-vue3 `npm run build` 通过，1822 modules；保留原有大 chunk 警告。最终资产 index-93dc6d53.css / index-30aa4995.js。
5. git diff --check 通过。
6. 真人路径浏览器：桌面尺寸覆盖 1624×1063；最大值右边界 861.33，查询左边界 871.33，重置左边界 940.67；同排无重叠，各按钮仅一个。数字为浏览器缩放后的 CSS 坐标。
7. 移动后的查询按钮查询“POS机纸”，返回一条库存记录、合计 11；移动后的重置清空名称，恢复列表和合计 1822 / ¥89300.00。
8. 收起后查询/重置仍可见；展开后恢复字段尾部。窄屏尺寸覆盖 768×900（缩放后的 CSS 视口宽 1024），两个按钮同排相邻且在视口内。临时尺寸覆盖均已撤销。

## 如实保留的限制

- shared-package-contract：1 通过 / 3 失败；toolbar-context-actions-contract：2 项通过后在旧字符串结构断言停止。以 HEAD 的共用工具栏和样式在内存中覆盖重跑，复现同样失败；未更改这些旧测试来掩盖结果。
- gateway-envelope-contract 缺少 UNIFIED_QUERY_GATEWAY_FIXTURE，未执行成功，不能计为通过。
- V1 已记录的数量范围 bccomp 报错以及广域旧契约失败仍未修复，不属于本次位置变更；商品名称查询通过不代表数量范围查询通过。
- 平台开关与默认页面回退由 AST/逻辑契约验证，本次未操作平台真实设置。
- 没有对生产实例执行任何操作；其他任务脏工作区与暂存区保持原样。
