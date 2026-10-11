# R10-00001 瑞昊库存入口权限修复发布记录

- 产品经理确认本轮本地测试通过，并授权 commit、部署瑞昊及 push。
- 本地业务提交 `88eea4f1`，仅包含 `CashierShell.vue` 中库存父入口权限修复、对应合同测试和固定版自测记录。该 Vue 文件中其他任务的人员选择修改未进入提交或发布包。
- 目标仅为瑞昊 `rh.cc3798.com` 的正式与 `preview-8080` 门店静态入口。无后端、数据库迁移、配置、权限数据或其他客户变更。

## 构建与发布

- 用已部署 R9 提交 `8daec7d5` 的 cashier-v3、inventory-vue3 和 shared 源码树构建，旧包的 HTML、JS、CSS SHA-256 与现网 R9 发布清单完全一致；随后仅叠加本轮已提交的 `CashierShell.vue` 构建候选包。
- 新包 `index.html` SHA-256 `f5858ad29c422ea4ccf2002d77355fa84bb9cd95633b7cfe594bc3733d94b336`；JS `assets/index-47ac4dd7.js` SHA-256 `5c3f77d9a71fc70f8481b48a6bd8776a4a38d34e8f2e51d71576a2d01a60c4c6`；CSS `assets/index-40bef4cf.css` SHA-256 `40bef4cf07b419ef2e64dfef4513a5cfb5c9dc50054da56701126deaf1abcec9`。
- 候选压缩包 SHA-256 `899022d54045808d9cb2505e3f4ead7a4ddf706de0e8c673d5024188dc3fe2e2`，上传后校验一致。
- 发布前两处旧 HTML 均为 R9 清单的 `46a4606cea728fe3671f61fd63f38e559cbde97a8d0ea4ba154e47053fff6fd1`，切换前再次检查未漂移。先安装新哈希资源，再原子切换两处 HTML；保留旧资源。

## 备份与验证

- 两处原静态目录分别备份于 `/www/backups/rh.cc3798.com/20261011-r10-inventory-nav-88eea4f1/`，`gzip -t` 和 tar 清单检查通过。正式备份 SHA-256 `49e72397f4ac1333ff774ef93f92e7c326f04566f85a4ac242f4bc88fdd94b13`；预览备份 SHA-256 `9f35791012a65e4013ee7fa27eb97a70aa3cc6ddfa8d1135aed477a567ab4597`。回滚时先核对无后续发布，再从对应备份恢复旧 HTML；本次不需恢复数据库或重启后端。
- 正式与预览入口、JS、CSS 均通过 HTTPS 返回 200；正式 HTML 和预览 JS 的在线内容指纹与候选包一致，预览 HTML 使用既有 Nginx 路径重写并指向新哈希资源。
- 本地权限合同测试通过。瑞昊预览浏览器刷新后原会话过期并跳转登录页，因此没有声称完成登录后实际侧栏验证；产品经理此前对本轮本地固定版已测试通过。
- 本次更新的业务注释位于 `前端代码/cashier-v3/src/layouts/CashierShell.vue`，明确库存父入口不继承收银权限，子功能及接口仍独立校验。
