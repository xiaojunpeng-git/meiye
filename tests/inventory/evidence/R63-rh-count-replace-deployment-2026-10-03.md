# R63 瑞昊盘点选品与清空发布记录

- 产品经理于 2026-10-03 确认本轮测试通过，并明确授权 `commit`、部署瑞昊、线上验证通过后 `push`。
- 本地业务提交：`a7baeab2ed0aadc1257a22af48372f33d27f5a31`，仅包含 R63 盘点组件、两项契约测试和自测记录。R62 盘点选品库存批次带入已在此前验收并提交为 `a5ac18d5`。
- 隔离发布提交：`528a272c65d327f982f69b65b2a3fdb85a20f8d8`，从瑞昊现网对应的 `ad81afd8` 基线仅叠加 R62/R63 文件，分支 `release/r63-count-replace-20261003`。未把当前工作区中其他收银、预约及后端未提交改动带入发布包。
- 本次目标仅为 `rh.cc3798.com` 客户独立实例。无后端代码、配置、数据库迁移或服务重启；未操作其他客户实例。

## 构建、基线和备份

- 从隔离发布提交归档构建门店端 `cashier-v3` 和共用盘点模块 `inventory-vue3`，两项 `npm run build` 均通过；保留既有 ExcelJS 大包提示。前端生成物不纳入 Git 源码。
- 用发布父版重建得到的门店端、盘点模块入口 SHA-256，分别为 `fa6f04abb18f338e41ce1cc7fe9975db3d6dbb649b1809125c98314f6583ad72`、`b80f59c7ee3049a583ad9030da2e2d879e9beb42f784dcca8cab2bfcc63b0901`，与瑞昊切换前现网入口完全一致。切换前再次复核三个目标入口仍是该旧版。
- 瑞昊独立回滚目录：`/www/backups/rh.cc3798.com/20261003-r63-count-replace-528a272c/`。完整静态目录压缩包及 SHA-256：正式门店端 `cashier-prod-before.tar.gz` 为 `42e032e2f03c12f62c755bf9276bc73bb96f940b32de34098e08736d77ba6398`；预览门店端 `cashier-preview-before.tar.gz` 为 `4e9c5e83c79629254d3795d5411f3f320932944d9814eb24f273b74e45129814`；正式盘点模块 `inventory-prod-before.tar.gz` 为 `125a688fdd2e74bed436614bfe496d3e05acdacd0a75572f8c2d77f17d74cb5c`。三个归档均完成读取校验。
- 上传隔离构建到上述回滚目录的 `stage/`，校验入口和主 JS 哈希后，先追加带内容哈希的静态资源，再以同目录临时 HTML 原子切换入口；保留旧资源。回滚可从对应压缩包恢复原目录。

## 线上只读验证

| 入口 | 在线入口 SHA-256 | 在线主 JS SHA-256 | 在线 CSS SHA-256 |
| --- | --- | --- | --- |
| 正式门店端 `/view_cashier_v3/` | `2ea1ef3dfdd2a215ba392e3f7a8e8fac1fbf6daa1b1694de403e7fdc20d4cabe` | `c98ebffa34138d15fbfa8e2d87a6b24a3e1adcc27f67fd816c110a231fea34da` | `1c97b9ccc5b9b6de98d9d06ea4984a7dbef76ce1a656fb32e22d0933b47b4059` |
| 预览门店端 `/preview-8080/view_cashier_v3/` | 服务端入口同上；HTTP 响应由 Nginx 改写预览资源前缀 | `c98ebffa34138d15fbfa8e2d87a6b24a3e1adcc27f67fd816c110a231fea34da` | `1c97b9ccc5b9b6de98d9d06ea4984a7dbef76ce1a656fb32e22d0933b47b4059` |
| 正式盘点模块 `/view_inventory_v3/` | `9b2b86966f4dde19d3462decd2d36570f85c242094516402d600e26ecbb1b0ab` | `c406b286cf9c1c0f373a8e9981b1500c68c480285525b7c76db1d05d75d66fc6` | `dcb2c56c9cdc05c2d0abcf255cadc5b231c749771af6a1d327f2c30d7efdb61b` |

以上 HTTPS 资源均返回成功，指纹与隔离构建逐一一致；在线盘点主 JS 包含新增清空提示文案。新版本预览页可打开到登录页，浏览器控制台未见错误。该只读冒烟不包含登录后的真实盘点提交，不能冒充线上业务写入验收；业务路径此前已由产品经理在本地固定版本测试通过。

本次发布记录仅为验收证据；前端源码与自动化测试清单见 R63 自测记录。无后端源码、数据库迁移、工程脚本或其他生成物提交。
