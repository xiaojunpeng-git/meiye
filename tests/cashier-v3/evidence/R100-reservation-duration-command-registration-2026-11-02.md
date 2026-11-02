# R100 服务时长保存命令登记补修

- 用户复现：门店预约详情有项目、输入 80 分钟后点击保存，页面报 `UNKNOWN_COMMAND_ACTION`；这条错误由浏览器端桥接层在请求发送前返回，未写入时长。
- 根因：门店端 `cashierV3ActionManifest.js` 未登记 `change-reservation-service-duration`；后端命令、详情动作和列表页调用已存在。
- 修复：在 C3 命令清单中登记该动作，沿用预约操作策略；不新增岗位权限，不修改预约数据、后端保存逻辑或商家端接口。
- 基线：本地已验收提交 `d02374471ec4b49d53766df29dddeab27a299e70`。本补丁仅 `前端代码/cashier-v3/src/services/cashierV3ActionManifest.js` 两行，补丁 SHA-256 `6e98459594261b9036fa488d1d0de147c9015fa00bd96785847e419ed1967484`，文件 SHA-256 `e974f191efeb5ea6440ff320f0ca45ed643c1fbef6b2757c3c5916490d4a8669`。
- 自测：直接导入前端清单，断言命令登记、C3 command 类型及预约操作策略通过；门店 Vite 构建和预构建 70 项通过；补丁 `git diff --check` 通过。现有 `frontend-manifest-sync.mjs` 全套检查有后端 JSON 文件缺失等基线问题，未以该结果冒充本补丁通过。
- 限制：浏览器控制当前不可用，页面提交与服务端重读尚未复测；未执行真实预约写入。
- 前端源码：一项；后端源码、数据库迁移、自动化测试和工程脚本：无；生成物：本地 `dist/`，不提交；验收证据：本文件。
