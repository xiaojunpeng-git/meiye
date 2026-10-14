# 第 18 轮：夸克权益核销预览包构建门禁

- 基线 HEAD：`63e215ec3bd826ea913fc1712470393c45b1d1ea`。
- 线上只读证据：瑞昊预览入口引用 `index-5028c045.js`；夸克控制台在该包的权益结账快照组装处报 `ReferenceError: role is not defined`。内置浏览器旧标签仍加载 `index-07a24ea8.js`，不能用旧标签成功页证明夸克原请求终态。
- 唯一源码 `前端代码/cashier-v3/src/views/CashierWorkbenchView.vue` 的 `buildCheckoutSnapshot` 已将 `role` 和 `next` 保持在逐行回调内，本次没有修改该源码，也没有从线上包反向覆盖源码。
- 本轮前端工程文件：`前端代码/cashier-v3/package.json`，SHA-256 `bf045a09ae80ac5677a64819f9b5a42745c6fe248d187befe37e4aa605c46d87`。
- 本轮自动化测试：`tests/cashier-v3/js/entitlement-experience-checkout-snapshot.mjs`，SHA-256 `c840f7d5a56177d55d2f0c933412dbeaf022580942db462757c97cf5868f84a7`。构建前强制执行真实快照函数，覆盖纯权益与混合结账、导购和轮次，`PASS checks=70`。
- 本地构建：`npm run build` 通过，构建 JS `index-03513c0c.js`，SHA-256 `5d1d355aacedf002e1b5f986f36e922eb5596c657de22a4a942af37e4fb3c3c2`。该构建包含工作区其他未提交改动，不能直接作为发布包。
- 额外回归：`local-draft-final-confirmation-contract.mjs` 因既有 `openCheckout` 静态模式断言与当前工作区代码不一致而失败；本轮未修改该测试或结账入口。
- 数据架构自检：本轮仅增加构建门禁和只读快照断言。权威业务源、事实粒度、成功时点、事务、并发、幂等、冲销、历史快照、权限、索引、统计及对账均未改变；没有调用核销写接口或更改客户数据。
- 后端源码、数据库迁移、工程发布脚本：无变更。生成物仅在忽略的 `dist/`，未纳入源码清单。
- 状态：本地开发与定向自测完成，待产品经理确认验收；未提交、未部署、未推送。瑞昊预览入口仍需独立发布修复，夸克原请求的业务终态未核实。
