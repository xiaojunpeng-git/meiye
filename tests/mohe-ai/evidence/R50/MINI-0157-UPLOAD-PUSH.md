# R50 小程序 0.15.7 上传与推送记录

- 日期：2026-09-28；产品经理已确认测试无问题，并明确要求“push、上传体验版”。
- 已部署业务版本：`9d90d5c8`；验收归档：`32bf876f`。
- 为避免混合分支夹带其他业务，使用临时 Git index 将上述两个提交的精确补丁应用到既有 AI 发布基线 `6d24ae70`，生成 `f5ecf20b00f0b5363c101e77ce6a50fabd5f87da`。没有切换工作区、创建源码副本或更改其他任务。
- 已推送到既有 Gitee 魔核 AI 发布分支 `origin/release/mohe-ai-r46-20260927`，远端 SHA 已核对为 `f5ecf20b`。分支名称沿用历史；本次内容是 R50。未推送混合业务分支或 GitHub。
- 发布范围与已验收源码、测试及 R50 证据路径比对一致。线上核心双端结果见 `RH-FOLLOWUP-DEPLOYMENT.md`，产品经理验收已通过。

## 小程序构建与上传

- 唯一构建源：`前端代码/mobile-vue3/`，HBuilderX 5.26 于 00:05 编译完成；开发模式构建，上传开启压缩，不冒充发行模式构建。
- 手机业务源码与发布版本一致；既有 manifest 未提交改动保持原状。编译生成 touristappid 后，仅在派生 project.config 恢复既有正确 AppID 并开启 minified，没有修改派生业务代码。
- AppID：`wx29d9b63c92555701`；版本：`0.15.7`；描述：R50 魔核AI日期完整查询、确认卡展示与等待续查修复。
- 接口保持 `https://rh.cc3798.com`，未切本地、未更换客户。
- 微信官方开发者工具 CLI 明确返回 `✔ upload`，包体 `1,227,371` 字节。首次指定 info-output 返回路径错误，未成功；去掉可选输出参数后上传成功。
- 本次手机合同回归 178 项及 R45 分组展示契约通过；不将合同测试冒充实体手机验收。
- 上一上传版本 `0.15.6` 可作为历史回退参考；未提交审核、未发布正式版、未改库或重部署后端。

SHA-256：

```text
71cec38d706d37133f4d81983f667a861f59df3996907b826e516a07d7a1a6e3  src/shared/components/mohe-ai-entry.uvue（源码）
504a908a42e7100ea617cea88170e6dd324a4a35564a88fdd89f2ba427f8d5ea  src/shared/components/mohe-ai-entry.js（mp-weixin 产物）
1acf4310b616d149fbcce387ab283d649f44eb4323563258501a8f9e694ea0d5  project.config.json（mp-weixin 产物）
```

## 尚待人工操作

开发版本上传成功不等于已经设为体验版。访问 `mp.weixin.qq.com` 被工具安全策略明确阻止，未尝试绕过。请产品经理在微信公众平台“小程序 → 版本管理 → 开发版本”将 `0.15.7` 设为体验版；此步骤尚未由 Codex 确认完成。
