# R42 退款会员查询范围本地自测

- 状态：本地开发与自测完成，待产品经理确认验收；未 commit、部署或 push。
- 基线 HEAD：`dac7aed256a477d375a77726fe71c67c6b5c9e79`。只改唯一源码 `美容源码/`，不连接远程实例；线上版本、迁移、备份和回滚点不适用。
- 页面范围：订单中心“选择退款会员”弹窗在搜索框右侧显示“本店 / 全部”，窄屏也优先同排并列；默认本店，切换后从第一页重新查询。原退款关键词提示与注销会员展示口径不变；收银、预约等复用弹窗的位置不变。

## 数据架构自检

- 权威源仍为 `user` 会员身份与 `store_user` 门店关系；本次只改变查询范围，不创建业务事件或事实，不涉及金额、业务时间、事务、幂等、冲销与历史回填。
- “本店”由服务端当前操作门店和 DataScope 交集确定；“全部”沿用退款入口既有跨店会员选择口径。请求仍以 `selectorEntry=order_center` 校验功能权限，不能靠前端自由字符串取得订单中心权限。首字母检索与注销过滤仍只属于原收银/预约选择器。
- 会员数与分页由同一服务端查询返回，不做当前页过滤；沿用现有查询索引与分页。无数据库迁移、跨实例配置或新增写接口。

## 固定文件

| 分组 | 文件 | SHA-256 |
| --- | --- | --- |
| 前端源码 | `前端代码/cashier-v3/src/components/common/MemberSelectorOverlay.vue` | `4691c387fa61317e2740ea9d626bc1972973322c162e2dc113e9135c59ae5987` |
| 前端源码 | `前端代码/cashier-v3/src/layouts/CashierShell.vue` | `c06c81efea564921e9e2e9c8d81c738b2ffd3d6e170186fd1977ae09706e9887` |
| 后端源码 | `后端代码/app/services/cashier/v3/member/CashierV3MemberModule.php` | `1ea1a1bdcc2b169774cf19b79da8f9611ce89c2ad98c8b833a3e6eb5e8eaa1a9` |
| 自动化测试 | `tests/cashier-v3/js/member-selector-scope-contract.mjs` | `90b16d3d742dfe288c36d2b75fff1bb54447a603580ce29760dacdad72fe5ac3` |

- 上述跟踪文件的整体工作区补丁 SHA-256：`40065054d1c3a982e20b0c9b289d3537486a62fe456d4dca216d5a1e94470f9d`。`CashierShell.vue` 和会员模块同时含有其他未提交工作，整体补丁不能单独代表本项。
- 数据库迁移：无。工程脚本：无。生成物：`前端代码/cashier-v3/dist/index.html` SHA-256 `804efa0d6b1ac307f5064b21ef22648e715ce10a065dfcee2fb0b1122952b3fa`，仅本地构建，不是 8080 集成包或源码正本。
- 注释更新：`CashierShell.vue` 说明退款上下文与订单中心授权的区分；`CashierV3MemberModule.php` 说明退款选择器复用显式本店/全部口径。

## 验证

- `node tests/cashier-v3/js/member-selector-scope-contract.mjs`：10 项 PASS，覆盖右侧位置、默认范围、请求上下文、权限入口及原搜索口径。
- PHP 7.4 容器内 `CashierV3MemberModule.php` 语法检查：PASS。
- `npm run build`：PASS；本地 Vite 构建，未打包 8080。
- 本地 `18091` 订单中心真实浏览器：退款选择器的 DOM 与可访问性顺序均为搜索框、“本店 / 全部”、查询按钮，默认“本店”；489px 浏览器宽度下两者同排，搜索框右边界 x=273.3，范围控件左边界 x=283.3。上一轮切“全部”后重新查询到 151484 位，再切回“本店”恢复 376 位；测试期间本地会员数据有变化，人数仅是测试观察值。仅执行只读查询，未选择会员或提交退款。
- `git diff --check`：PASS。未验证宽屏实机布局；共享组件其他入口通过作用域条件和构建检查，未逐个作人工页面回归。
