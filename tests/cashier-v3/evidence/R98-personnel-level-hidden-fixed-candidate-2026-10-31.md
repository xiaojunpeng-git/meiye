# R98 完整业务分配移除职级列：补充固定候选（2026-10-31）

- 基线提交：`98aeea423e6c4bf96c97f51eed2e964c9bdbadf4`（前一闭环已验收并本地提交）。
- 本次业务补丁 SHA-256：`db6c7b52fd1932667758ec6bd350348312a6262b6c5355c0c2a32d0d2da75ee8`（仅以下 3 个业务/测试文件的隔离补丁，不含本证据文件）。
- 涉及前端源码：`PersonnelPerformanceOverlay.vue`、`base.css`。后端源码、数据库迁移、工程脚本：无。自动化测试：`r98-presale-display.mjs`。生成物：门店端 `dist/`（不纳入提交）。
- 变更：完整分配模式的销售人、手艺人表格均移除“职级”表头与对应值；按售前开/关、手艺人、历史调整及手工费显隐同步收紧表格列。人员搜索和原资料中的职级保留；点客、售前、比例、金额、手工费、项目数及保存快照逻辑不变。
- 自测：R98 真实 Vue 渲染覆盖售前开关两态与简易/完整模式，并增加销售人/手艺人职级列消失检查，保留快照一致性；门店端 Vite 构建及权益预构建 70 项通过；`git diff --check` 通过。构建资源：`index-ff3f27b5.js`、`index-b3ca9d4c.css`。
- 现网目标实例与远程备份：不适用。本地共享工作区有其他任务已暂存与未暂存改动，提交时须只隔离上述补丁，不整文件暂存。

## 固定工作区文件 SHA-256

```text
0d4b350e6af02c3136776c9a78ec4b92dcc1272d69242958ff98b86ff942ae5b  前端代码/cashier-v3/src/components/cashier/PersonnelPerformanceOverlay.vue
334cdf473950acdd96901c87a2550d158d7f3f4df0f725ff126a668972bb09b3  前端代码/cashier-v3/src/styles/base.css
2d37832c48153b2c782b0b21c374422c77df2747c572895c799555f9bab7ba3e  tests/cashier-v3/js/r98-presale-display.mjs
```

状态：补充开发与自测完成，待产品经理针对本补充固定候选确认验收；未提交、未部署、未 push。
