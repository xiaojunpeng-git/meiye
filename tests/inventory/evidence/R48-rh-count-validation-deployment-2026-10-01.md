# R48 瑞昊库存日期与盘点错误定位发布记录（2026-10-01）

## 验收、范围与版本

- 产品经理确认本轮测试无问题，并明确授权 `commit`、部署瑞昊和 `push`。
- 本轮包含两个已验收闭环：库存管理日期只允许通过日历选择；完成盘点前明确提示首条问题商品、自动定位并高亮问题行，同时把旧服务端技术错误码转换为中文业务提示。
- 主开发线提交：`4c5c0e65c0e7f5f4f099ca50ea24e358a912c30e`（日期控件）和 `462fa3e5`（盘点错误定位）。
- 瑞昊隔离发布提交：`7b0df49da0dfc51b0c22d4e7b97d0cfbe1c831fd`，以已推送且已部署的 R47 发布记录提交 `1bebc21e23274462e131220c6a603a6634450989` 为父提交，仅叠加本轮 11 个源码、测试和既有自测证据文件。
- 目标实例仅为瑞昊 `rh.cc3798.com`；`007/008/012` 未修改。本轮无后端、数据库迁移、配置、Redis、队列、定时任务或进程变更。

## 隔离构建与自测

- 构建源由 Git 提交 `7b0df49d` 独立导出，未读取共享工作区的其他未提交源码。
- 盘点提交合同、入库日期校验、库存日期只允许选择合同均通过；库存 API 合同 58/58 通过。
- `cashier-v3` 集成构建通过，共处理 1986 个模块。
- 构建产物：
  - `index.html` SHA-256：`efb170e7d80fe3c9016317565dd05e20b62f56bb4364e0aac76707fe4cae63cc`
  - `assets/index-d40b5040.js` SHA-256：`aa63aae04b0cc7af9a56da7ce3b816fd66b2e445469e68b58834313b40fc96f8`
  - `assets/index-07edb040.css` SHA-256：`07edb040f729c76b90e2933ab0995c3980964729386e5aabfc0ebeee2754ed2b`
  - 全包清单指纹：`40fb844bc57a7fffb7e20ed18bb95fa0d59e8f730440aa035ee74af9bffbe32d`

## 瑞昊部署与回滚

- 部署前正式端和 8080 预览端 `index.html` SHA-256 均为 R47 已登记指纹 `391718df223ed2dcc0b9863ac23bd88b894faab97f860b5e63cc20820864af6b`；两处均为实际目录，磁盘剩余 217G。
- 回滚目录：`/www/backups/rh.cc3798.com/20261001-r48-count-validation-7b0df49d/`。
- 正式端旧包 `prod-before.tar.gz` SHA-256：`263c1fb74992d558a2d72a80c52fc821ea65b23fb348db14eab1dbd4657409e4`。
- 预览端旧包 `preview-before.tar.gz` SHA-256：`16818792e9d43d506c583de15434885b6b89ad1370b18794967257ecdb9ead45`。
- 两份回滚包均通过 `tar -tzf` 完整性检查。构建包先上传到同目录 `stage/` 并核对 HTML、JS、CSS 指纹和关键提示/高亮标识，再同步新哈希资源并原子替换两处 `index.html`；旧哈希资源保留供已打开页面使用。
- 部署后正式端和预览端 HTML 均为 `efb170e7…`，主 JS 均为 `aa63aae0…`，与隔离构建一致。回滚只恢复对应前端压缩包，不覆盖数据库或业务数据。

## 线上验证

- 正式端、8080 预览端及两处新主 JS 均返回 HTTP 200；HTTPS 获取的主 JS SHA-256 均为 `aa63aae0…`。
- 瑞昊已有登录会话使用 `release=r48-count-validation` 重新加载成功，门店为“江苏淮盛店”、账号为“王高玲”，收银与库存宿主页面正常渲染。
- 线上只做登录态页面读取和静态指纹验证；没有点击“完成盘点”、保存草稿或提交其他业务单据，没有改变生产库存。

## 文件分组

- 前端源码：`App.vue`、`InventoryBusinessModal.vue`、`inventoryApi.js`、`countSubmission.js`、`inboundDateValidation.js`、`inventoryDatePickerOnly.js`。
- 自动化测试：4 个库存 JS 合同文件。
- 后端源码、数据库迁移、工程脚本：无改动。
- 生成物：隔离构建的 `cashier-v3/dist/` 和瑞昊两处门店端静态包，不作为源码正本。
- 验收证据：本文件及此前的 `R48-inbound-invalid-date-selftest-2026-10-01.md`。
