# 员工列表短屏分页与重复页头：瑞昊、渼约 008 发布记录

## 验收、提交与候选边界

- 产品经理确认人员管理测试通过，并明确授权：只提交本轮、部署瑞昊、推送，随后部署渼约 008。
- 业务提交：`e65251538c3c9752fc0f3c1d921c17dbaf3a2cee`，提交信息 `STAFF-00001 员工列表短屏分页与页头闭环`；只包含员工列表布局、收银壳员工路由、布局合同和固定版本记录 4 个文件。
- Gitee 发布分支：`release/staff-pagination-quark-20261017`。瑞昊验证通过后创建，部署 008 前只读核对远端指向业务提交。
- 共享工作区 `CashierShell.vue` 中另一任务的会员专属服务人多选改动未暂存、未提交，也未进入候选包。
- 当前基线 `5358c4e4` 的干净构建与两站发布前现网逐字节一致。候选从隔离 Git 树 `aa0198a1a8da0ac4ece85d25e1c4b4a7642f2e54` 构建，候选包 SHA-256 为 `d4928796766321b43bd0f4a099735712588aa99d61b2dbf035659e026f871835`。

## 构建、自测与防回退指纹

- 布局合同：`STAFF_PAGINATION_LAYOUT=PASS`。
- 收银集成快照：`ENTITLEMENT_EXPERIENCE_CHECKOUT_SNAPSHOT=PASS checks=70`。
- Vite：`2012 modules transformed`，构建成功。
- 发布前两站正式入口及瑞昊预览入口共同指向：
  - `index.html`：`6d80f10c33d5434e9368c6dee30d517f301c026632a388555c6b6cc44e62e650`
  - `assets/index-ecee9d6b.js`：`3469eb0f6449290a3fffe7dd5d40e18e6e2d55bb2f57125c08de76f06420d97f`
  - `assets/index-c1eca16e.css`：`c1eca16ed66d88b55ca9a39b0cb20b89bd68fb7175d39695990cf94617685c78`
- 发布后共同指向：
  - `index.html`：`90bf1f3faedc12e4c87b7e9d18c5d35635af71cdf36da55264bb0d38d21ba2db`
  - `assets/index-f396a3df.js`：`42b1a7b1c77b4eb3621928ce1905ed87e59490b8e4e064ce8379563a5af67244`
  - `assets/index-213d3129.css`：`213d3129dbadccb8d51022fb8e520aeb6f57a8064fc2d1835171097af2b5e230`

## 瑞昊 `rh.cc3798.com`

- 正式 `/view_cashier_v3/` 与预览 `/preview-8080/view_cashier_v3/` 发布前均重新校验为上述统一基线。
- 独立回滚点：`/www/backups/rh.cc3798.com/20261017-staff-e6525153/`。正式、预览备份包 SHA-256 分别为 `ddc6e26605943fea0f62a30bbd42760908f6e36466a7815dca69414b6b7569ed`、`1d3b3864a66adb007ff146eb7e82231e7d7bf4917636c1be150d18122de87eeb`。
- 候选包、入口及主资源在目标机校验后切换；正式与预览磁盘入口及公网主 JS/CSS 均匹配候选，管理入口 HTTP 200。
- 使用已有瑞昊登录会话刷新预览人员管理：外壳重复页头不存在，页面内保留唯一“新增员工”；分页底边位于可视区内，结果区保持独立滚动；实际加载 `assets/index-f396a3df.js`。

## 渼约 008 `008.cc3798.com`

- 瑞昊验证与首次 Gitee 推送完成后，重新读取 008 正式入口，确认仍与统一发布前基线逐字节一致。
- 独立回滚点：`/www/backups/008.cc3798.com/20261017-staff-e6525153/`；正式入口备份包 SHA-256 为 `9bfb9970873f09f1ce24ebe1689b89adb3202c45b314609c8a675e955856ec84`。
- 正式 `/view_cashier_v3/` 的公网 HTML、主 JS/CSS 均逐字节匹配候选，管理入口 HTTP 200。
- 本轮未部署 008 预览入口；未修改后端、数据库、服务配置或生产数据，未重启业务服务。007、012 及其他实例保持原样。

## 业务范围与注释

- 本轮只修正人员管理页在短可视高度下的结果区滚动与分页可达性，并移除该路由重复的外壳“员工列表 / 新增员工 / 页面说明”页头。
- 员工查询、分页参数、编辑保存、权限和数据接口均未改变。
- `StaffListView.vue` 的业务注释说明了结果区独立滚动所保护的分页可达边界。
