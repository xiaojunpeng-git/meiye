# R35 销售订单手艺人保存误报修复：瑞昊部署记录

## 固定版本与范围

- 产品经理已确认本地测试结果，并明确授权 commit、部署瑞昊和 push。
- 本轮业务提交：`7e8574fa`（`R35 修复销售订单手艺人保存后误报`）。
- 目标仅为瑞昊 `rh.cc3798.com`；未连接、未部署 `007`、`008`、`012` 等其他客户实例。
- 本次精准发布门店端 `view_cashier_v3` 构建包和后端 `CashierV3SalesOrderQueryServices.php`；无 SQL、无数据库迁移、无配置和业务数据变更。

## 问题与修复

- 手艺人调整命令实际已成功，保存后的销售订单列表却复用了写入前的签名分页游标，后端正确拒绝旧快照后，页面把回刷异常误报成“修改失败”。
- 前端在人员调整成功后静默重建销售订单首页，并完整移除新旧命名的游标和快照字段。
- 后端统一规定第一页始终建立新快照；第二页起仍严格校验签名、筛选指纹、权限版本和页码，不放宽翻页安全边界。

## 本地验证

- 实际页面复测销售单 `XS26092500002` 的“头部放松”：保存后弹窗正常关闭、列表正常刷新，无“操作失败”提示。
- 手艺人结果保持为张丽函，消耗业绩 `1001`、手工费 `0`、项目数 `21`，订单金额未变化；复测只新增本地调整审计记录。
- `order-center-sales-service-lines-contract.mjs`：通过。
- 后端 PHP 语法检查通过；相关补丁 `git diff --check` 通过。
- 门店端生产构建成功，Vite 处理 1972 个模块；仅有既有 chunk 大小提示。

## 备份与回滚点

- 独立备份目录：`/www/backups/rh.cc3798.com/20260925-185110-r35-7e8574fa-craftsman-refresh`。
- `backend-before.tar.gz`：SHA-256 `53a3cc723ad353058a5a1b4a5f942d06605dece883e1f92cc7cd6a81110d2832`。
- `cashier-main-before.tar.gz`：SHA-256 `1fe434d54c1db906ecab02994ac6db7fc1e1e01b238039110d2f6931606d66bd`。
- `cashier-preview-before.tar.gz`：SHA-256 `3fd18d52c453ada9c3e0edf65524d36634b6fde940c749bb9783881ee51415c4`。
- 回滚时恢复对应后端文件和两个门店端静态目录，清理瑞昊运行缓存并重启瑞昊 Swoole；本次不涉及数据库回滚。

## 部署与线上验证

- 本地、瑞昊正式入口和 8080 预览入口的静态文件集合 SHA-256 均为 `8f543ccb24225366b580915091f7179ef875496bf5dcd133b5d470ad7f9e978c`。
- 瑞昊后端文件 SHA-256 为 `89d258a06b707b19edc53b0657f93736f3ab601ce3993a688c2bd53f8ac5e2d5`，与本地固定提交一致；远端 PHP 语法通过。
- 正式入口和 8080 预览入口订单中心均返回 HTTP 200，并加载 `assets/index-93b7a2de.js` 与 `assets/index-5d5adc6c.css`。
- 登录后的瑞昊 8080 预览订单中心已正常显示销售订单、购买标签和可点击的手艺人数据，无通用操作失败提示。
- 瑞昊 Swoole 重启成功并保持 `manager_count=1`；AI execution worker、Excel worker 和 supervisor 均为 active；部署后近期日志无新增 `Fatal error`、`Parse error` 或 `Uncaught`。
- 线上验证只读，未提交手艺人调整命令，未新增、修改或删除瑞昊生产业务数据。
