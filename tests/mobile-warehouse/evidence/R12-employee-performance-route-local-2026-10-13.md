# R12 员工业绩明细请求失败：本地修复与验收边界

## 复现与原因

- 2026-10-13，本地 `18104` 商家 H5 连接瑞昊 `https://rh.cc3798.com`，从排行进入 `employeeId=922` 的员工业绩页。浏览器的 `POST /api/mobile/merchant/warehouse/employee-performance` 失败，CDP 给出 `MissingAllowOriginHeader`。
- 对瑞昊线上同一路径做不带有效凭证的只读路由探针，返回 HTTP 400 `ROUTE_NOT_FOUND` 且没有 `Access-Control-Allow-Origin`；相同方式请求已注册的 `warehouse/overview` 返回鉴权协议错误并带跨域头。故页面的“网络请求失败”是未注册路由落入兜底错误，被浏览器跨域检查掩盖，不是员工没有业绩。
- 本地源码已有 `MobileWarehouseServices::employeePerformance` 与前端请求契约，缺少 `api-mobile.php` 路由和 `Warehouse` 控制器入口。

## 本地修复与验证

- 基线 HEAD：`a0e08bc37206186dd0b907e092dcb412e1898e01`。后端路由增加只读 `POST warehouse/employee-performance`，仍处在商家会话、跨域和统一响应中间件组；控制器只解析服务器会话，再将请求筛选传给既有数仓服务，不在客户端或控制器重算指标。
- 源码 SHA-256：`后端代码/route/api-mobile.php` 为 `b86557931a2164c1c4975e07d757fe6ca139d3d4ca525e2032127acd41fed5b9`；`后端代码/app/controller/mobile/merchant/Warehouse.php` 为 `e4246d2a63d9c034186ca8f399a9be4ab47a6857488a258c90d73b3ee76055f4`。两文件**未暂存工作区补丁** SHA-256 为 `8e11862b964bd978f5d1cca8ef3d7978482372f2ffe9757cb08128f3df3edbc2`。
- 两文件 `php -l` 和 `git diff --check` 通过；`bash tests/mobile-warehouse/run-contract.sh`（层级、排行权限、页面合同）及 `mobile-session-contract.php` 通过。
- 本地 `18093` 对新路径返回带 `Access-Control-Allow-Origin: http://127.0.0.1:18104` 的 HTTP 401 `AUTH_REQUIRED`，证明路由进入商家会话保护而未落入 `ROUTE_NOT_FOUND`。使用线上同一账号尝试本地登录时，本地独立数据库返回 `MOBILE_ENTRY_DISABLED`，所以尚不能把该账号的本地真实业绩读取记作通过。瑞昊线上仍运行旧路由，原 H5 页面会持续报错直到获准发布并完成线上验证。

## 数据架构自检与交付边界

- 权威源仍是统一业绩事实和指标 Reader；现金/劳动业绩分别按原事实最小粒度、`business_date`、原冲销规则和口径版本查询。此次只补充 HTTP 入口，不写业务事实、不改变成功时点、事务、幂等、Outbox、分摊、退款、历史快照、索引或聚合，也不新增数据库迁移。服务端原有授权门店集合和个人范围约束继续作用于汇总与明细；请求中的员工、日期和模式只缩小范围。线上发布后须以授权账号核对汇总、明细、越权查询和空结果，且确认下钻值能被明细解释。
- 前端源码：无修改。后端源码：上述路由与控制器。数据库迁移、自动化测试、工程脚本、生成物：无修改。验收证据：本文、用户页面截图和本地/线上只读 HTTP 探针。业务注释补在上述两份后端源码，说明会话与权限边界。
- `api-mobile.php` 已有其他任务预先暂存的 `target-scheme` 路由；本次闭环未修改那部分。产品经理已对上述 R12 固定版本明确确认验收，并授权本轮提交及部署瑞昊。提交必须使用隔离暂存，仅收录本次两文件的对应补丁，不能夹带既有暂存改动；`push` 未获本轮授权。
