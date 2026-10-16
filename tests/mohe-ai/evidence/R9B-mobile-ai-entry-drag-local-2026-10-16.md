# R9B 商家手机端魔核 AI 入口拖动：本地固定版本

- 基线 HEAD：`7d40da77fb980831ba4fd7e2c313e47611a258d6`
- 源码与测试补丁指纹（`git diff --binary` 接测试文件内容的 SHA-256）：`61a3c98f06e273ead515e7ebd9ac242816ed13637544dd098cea2d69fe54e5dc`
- 状态：开发与本地自测完成，产品经理已明确确认 R9B 验收通过并授权仅本地提交；未部署、push 或上传体验版。

## 实现与文件边界

商家手机 Vue 3 共用魔核 AI 入口增加单指拖动。移动超过 5px 才开始拖动，拖动结束不打开工作台；轻触仍打开。位置只保存在当前设备的本地存储，页面重建时恢复；屏幕缩小时限制在可触及范围，并给底部 Tab 和安全区留位。首次使用维持原 CSS 默认位置。没有改问答、权限、数据或服务端逻辑。

| 分类 | 文件 | SHA-256 |
| --- | --- | --- |
| 前端源码 | `前端代码/mobile-vue3/src/shared/components/mohe-ai-entry.uvue` | `22de19ec6311d14d229512e4eadfed5f8e0ce933838bcc437f6f71f672c10690` |
| 自动化测试 | `tests/mohe-ai/mobile-entry-drag-contract.mjs` | `18098b733bd29a77f5958086d6e7fc2696c59aafe6734618d26122f4a7cbc39b` |

后端源码、数据库迁移、工程脚本：无。生成物：本地 HBuilderX 的 `unpackage/dist/dev/mp-weixin`，不纳入 Git。验收证据：本文。

新增业务注释位于 `mohe-ai-entry.uvue`，说明位置仅是设备偏好、底部安全边界及拖动不能打开工作台。未引入第二个入口实现，也未发现本闭环替代后需删除的旧业务分支。

## 自测结果与限制

1. `node tests/mohe-ai/mobile-entry-drag-contract.mjs` 通过，执行实际 UTS 控制器经 TS 降级后的函数，覆盖拖动与点击隔离、位置恢复、3px 轻触、缩屏及安全区边界。
2. `node tests/mohe-ai/browser-mobile-guidance-contract.mjs` 通过，既有手机问答控制器 219 项和关联展示回归通过。
3. HBuilderX 本地监听编译于 22:14:23 更新微信小程序 JS/WXML/WXSS：WXML 含 `bindtouchstart`、`catchtouchmove`、`bindtouchend`、`bindtouchcancel`；生成 JS 的 `node --check` 通过。生成 JS SHA-256 `ef0a3ef11b13225b46fe2daffa82939d35457e89a2f6b1143dfb4d808e071874`；WXML SHA-256 `91bdb222a6e47223f63e267b740ba28a9ef2fefef6397f2629e4096afb56ccff`。
4. `git diff --check` 通过。仅本闭环的源码和测试文件进入固定范围。
5. `scripts/check-mobile-merchant.sh` 在既有缺失文件 `src/merchant/api/mobile-login-client.uts` 处停止；`tests/mobile-vue3/run-all.sh` 54 项中 34 项通过、20 项因当前其他未闭环的迁移与缺失合同文件失败，未作为本闭环通过证明。
6. 本地 18104 商家端页面打开后停在登录页，当前无有效商家会话，无法在实际手机页面拖动入口。微信开发者工具当前打开的是旧的临时 R8K 工程，未用它冒充本次源码的真人操作验收。小程序真机手势仍需在体验版上传获授权后核对。

本次只做统一源码的本地开发与构建核对；远程客户实例、线上迁移、备份和回滚点不适用。工作区另有多项既存未提交改动，提交只能精准包含本表源码、测试和本文。
