# 员工列表短视口分页固定版本（2026-10-17）

## 问题与结论

- 线上入口：`https://rh.cc3798.com/preview-8080/view_cashier_v3/#/management-center/staff`
- 夸克与 Codex 内置浏览器显示的是同一批 11 条员工数据，分页组件也同时存在；本次不是查询结果或浏览器缓存不一致。
- 线上预览入口当前资源：`assets/index-471c5530.js`、`assets/index-68c95382.css`。
- 根因是员工页外层按表格内容自然增高，收银壳又会裁剪路由外层溢出。夸克保留标签栏、地址栏和书签栏，可用页面高度较短，因此分页被表格推到裁剪区之外；内置浏览器可用高度较高时刚好可见。

## 固定范围

- 基线 HEAD：`5358c4e4beca2c6fa60d0594c6ee694843250827`
- 基线复核：原验收基线 `4905abc3` 到当前 HEAD 的员工页、收银壳、路由、基础样式及前端依赖均无提交级变化；重新生成后的三个本轮文件 SHA-256 与验收时一致。
- 前端源码：`前端代码/cashier-v3/src/views/StaffListView.vue`
  - SHA-256：`4d3903b68cfa3cb224cbc6f923ab8427122c13dc7f982f557bb8e86f3777cd95`
  - 页面固定占满收银工作区；查询区和分页保持固定高度；仅结果表格占用剩余空间并独立滚动。
- 前端壳源码：`前端代码/cashier-v3/src/layouts/CashierShell.vue`
  - 本轮隔离候选 SHA-256：`6029d2eee9fa1eb0f922862faaf139dee79ab601d737fef22ae984be291254f2`
  - 将员工列表登记为无外壳页头页面，移除失效的外壳“新增员工 / 页面说明”入口；页面内部“人员管理 / 新增员工”保留。
  - 当前共享工作区的该文件另有会员专属服务人多选改动，本轮补丁和候选 SHA 已排除该改动，后续必须按补丁分块精确暂存。
- 自动化测试：`tests/unified-query/js/staff-pagination-layout-contract.mjs`
  - SHA-256：`09b699871455f504793e8ac8f122a8bf442537004503c6c86ce9bca36b5acada`
  - 固定“外层不滚动、结果区可收缩并滚动、分页位于结果区之后、员工路由不再渲染外壳页头”的布局合同。
- 补丁指纹（以上三个文件相对当前基线、由隔离 Git 索引导出的统一差异）：`dbd5a56e265ac11cdabee580a4916cd09a1b78d99a325f73053acccdfa308f8e`
- 隔离候选源码树：`aa0198a1a8da0ac4ece85d25e1c4b4a7642f2e54`
- 隔离候选包 SHA-256：`d4928796766321b43bd0f4a099735712588aa99d61b2dbf035659e026f871835`
- 候选入口 / 主资源：`index.html` `90bf1f3f…`；`assets/index-f396a3df.js` `42b1a7b1…`；`assets/index-213d3129.css` `213d3129…`。

当前工作区还有其他任务改动，均不属于本固定版本；后续只允许按上述本轮补丁精确暂存和提交。

## 自测

1. `node tests/unified-query/js/staff-pagination-layout-contract.mjs`
   - `STAFF_PAGINATION_LAYOUT=PASS`
2. `npm run build`（`前端代码/cashier-v3`）
   - `ENTITLEMENT_EXPERIENCE_CHECKOUT_SNAPSHOT=PASS checks=70`
   - Vite：`2012 modules transformed`，构建成功。
3. 本地真实页面：`http://127.0.0.1:18091/view_cashier_v3/#/management-center/staff`
   - 顶部外壳页头不存在，主工作区从顶部开始，页面内部保留唯一“新增员工”按钮。
   - 模拟夸克短视口：页面可视高度 `700px`；结果区 `clientHeight=397px`、`scrollHeight=493px`，表格独立滚动；分页底边 `682px`，仍完整位于可视区内。
4. `git diff --check`：通过。

本轮只修改员工列表布局、该路由的重复外壳页头和对应回归合同，没有修改员工查询、分页参数、编辑保存、权限或数据接口。
