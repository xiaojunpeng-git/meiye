# M008-R2-20261019-STAFF-STACK 员工横向卡片固定候选

状态：开发与本地自测完成，待产品经理确认验收；未commit、未部署、未push、未保存线上装修。

## 双基线与范围

唯一正本HEAD：3faea328d6077e9eca7d52805464c826fc3051a7；两个受影响组件开发前均无未提交改动。其他任务的已暂存、未暂存文件完全保留。本项仅本地，目标实例运行版本、线上迁移、实例备份均不适用；不为本项访问瑞昊或008服务器。

用户浏览器批注明确要求：员工展示“横向滑动”改为头像在上、展示信息在下。

本轮新增：仅样式0的卡片改为固定宽度上下排列，头像居上，姓名与所选职位/职级/简介居中在下，预约按钮在信息后。编辑器和会员端分别修改对应样式0作用域。

既有能力保留，不列为新优化：样式1、2原规则，岗位筛选、员工数量与字段显隐、缺图占位、切店请求序号防旧响应、预约门店/人员参数、查看全部。合同测试逐字验证模板和脚本与基线相同，剔除本轮横向CSS后其余样式相同。

## 文件与指纹

前端源码：
- 前端代码/admin/src/components/mobilePage/home_staff_list.vue：716487542fc93d9a1470be7393c07ae5083812e0e5e7f8ec8d5704a56a3279e3
- 前端代码/uniapp/pages/index/components/staffList.vue：f9c5ae17da23e28046bde3f782a893adbdee4ecce7fa4f7241045db6e3d51006

后端源码、数据库迁移、生产脚本：无。

自动化测试与本地夹具：
- tests/diy-staff-component/stacked-harness.cjs：40eb4b9938bf7e32a196b65e285339ec0732b3e67a451a1a5754defc8e551473
- tests/diy-staff-component/stacked-serve.cjs：ad7edf38ed29d4f9c4979c46f117501c4bf368fbed310bbf4e5a5127e8e77e43
- tests/diy-staff-component/stacked-fixture.html：21535b6476b482d04e69fce3797a8ffb079bb6b64fcf262f36ad702556e885be
- tests/diy-staff-component/stacked-contract.cjs：11bfbe95b190cae9b6f40f147a273f2f247e4f908eea52b2168cfbc55de2e67c

补丁指纹：cb87f0e0d3051eed2e094b4095d48c46fc8c23ad9e6bf8c5199ad4f236a9d84c。按上述六文件顺序，串联“路径+换行+原文件字节+换行”做SHA256。

生成物：无正式构建包；SFC与样式编译在内存完成。
验收证据：本文、2026-10-19-stacked-375.png；图片是现有本地预览头像，不涉及真实员工隐私。

## 自测

- `node tests/diy-staff-component/diy-staff-component-contract.mjs`：PASS，原有岗位、请求及预约合同仍通过。
- `node tests/diy-staff-component/stacked-contract.cjs`：PASS，Vue2模板及SCSS编译，模板/脚本/非横向样式保持基线原字节。
- 本地页 http://127.0.0.1:19210/fixture.html，模拟6名员工，只替换uni-app宿主标签、rpx单位和API传输，不复制组件业务实现。
- 浏览器实际测量组件宽度375、320；两个组件头像底部不超过信息顶部、信息底部不超过预约按钮顶部；长姓名ellipsis且不超出卡片，图片全部naturalWidth>0。
- 横向滚动宽782/clientWidth300，点击下一组scrollLeft250；不是只看overflow声明。
- 关闭简介与预约按钮后DOM数量均为0，恢复后正常；点击第3名员工，selected_teacher保留id3和store_id101，跳转/pages/users/user_card_list/index；只在夹具内记录，不创建预约或读写生产数据。
- 切样式1保留原编辑器row/会员端column，切样式2两端row，未让横向column规则覆盖其他样式。旧预览样式1与会员端存在历史差异，本项未顺带整改。
- 本地页为组件级验收，不冒充平台整包、H5生产构建、小程序构建或线上可用验收。

## 架构、注释与授权

本次没有改变数据权威源、API请求、事件、事实粒度、时间、金额、事务、并发、幂等、历史快照或权限；只是既有员工字段的CSS排列，不写业务事实。旧序号保护和门店/岗位过滤原样保留。两个源文件均在新增样式之前说明只影响横向卡片、固定轨道及预约位置的边界；测试/夹具说明仅本地模拟，避免当作第二份业务源码。

产品经理确认此固定版本后仅精准本地commit；本项没有线上发布、全包构建、数据库执行或push授权。链接目录迁移属于另一个独立候选，不与本项混合提交。
