# 商家数据页门店排行进入详情：固定候选

- 基线 HEAD：`9f20e88a1aa42cd63aaa6d010889b7d0bb93cade`；目标仅为商家端数据页门店排行导航。本次未触及共享工作区其他任务的未提交改动。
- 前端源码：`前端代码/mobile-vue3/src/merchant/pages/warehouse/index.uvue`，SHA-256 `2569f393846f7d456d8e50ea2ebdd2a9a75524cd2d6aff5cf30130558d1f4723`；相对基线补丁 SHA-256 `c643382febfc581dfcbf4db2fa14d344bfb35375cc8066cb9e8c35b95ad70122`。
- 行为：组织排行和门店排行的门店行统一读取所选门店节点，并切到该门店“员工销售”详情栏目；沿用当前日期。授权范围继续由服务端节点投影裁剪。
- 后端源码、迁移、工程脚本：无变更。自动化测试：复用 `tests/mobile-warehouse/js/page-contract.mjs`。生成物：本地临时构建目录，未纳入 Git。
- 自测：页面合同 2 项通过，`git diff --check` 通过。修改后尝试完整 H5 重建，命令在 `App.uvue` 报 `default is not exported`，因此不能把这次重建记为通过；本轮未修改该文件。浏览器控制接口未找到目标 tab，未完成实际点击复测。固定候选需产品经理验收；未提交、未部署、未推送。
