<template>
<!-- 营销-拼团列表 -->
    <div class="article-manager combination-group-list">
        <Card :bordered="false" dis-hover :padding="20" class="combination-filters">
            <Form :model="formValidate" :label-width="76" label-position="right" inline class="combination-query-form" @submit.native.prevent="userSearchs">
                <FormItem label="选择门店：" class="query-field query-store"><OrganizationStoreScopePicker v-model="formValidate.store_ids" :load-scope="loadScope" /></FormItem>
                <FormItem label="选择拼团：" class="query-field query-activity"><Select v-model="formValidate.cid" clearable filterable placeholder="全部拼团">
                    <Option v-for="activity in activities" :key="activity.cid" :value="activity.cid">{{ activity.title || ('拼团' + activity.cid) }}</Option>
                </Select></FormItem>
                <FormItem label="拼团状态：" class="query-field query-status"><Select v-model="formValidate.status" clearable placeholder="全部状态">
                    <Option :value="1">拼团中</Option><Option :value="2">拼团成功</Option><Option :value="3">拼团失败</Option>
                </Select></FormItem>
                <FormItem label="会员搜索：" class="query-field query-member"><Input v-model.trim="formValidate.keyword" clearable placeholder="团长/团员姓名或手机号" @on-enter="userSearchs" /></FormItem>
                <div class="combination-search-actions"><Button type="primary" @click="userSearchs">搜索</Button><Button @click="resetSearch">重置</Button><Button :loading="exporting" :disabled="loading || !total" @click="exportList"><Icon type="ios-download-outline" /> 导出</Button></div>
            </Form>
        </Card>
        <Card :bordered="false" dis-hover :padding="20" class="combination-results">
            <div v-if="listError" class="combination-list-error">{{ listError }} <Button size="small" @click="getList">重试</Button></div>
            <div ref="results" class="combination-table-scroll">
                <Table :columns="columns1" :data="tableList" :loading="loading" :max-height="tableFixed ? tableHeight : undefined" highlight-row no-data-text="暂无拼团记录">
                    <template slot-scope="{ row }" slot="price">{{ money(row.price) }}</template>
                    <template slot-scope="{ row }" slot="deadline">{{ row.deadline | formatDate }}</template>
                    <template slot-scope="{ row }" slot="status_name"><span :class="'combination-status-' + row.status">{{ row.status_name }}</span></template>
                    <template slot-scope="{ row }" slot="action">
                        <a @click="Info(row)">查看详情</a>
                        <a class="combination-action" @click="openLeaderReward(row)">团长奖励</a>
                        <a v-if="row.status === 1" class="combination-action" @click="completeGroup(row)">立即成团</a>
                        <a v-if="row.status === 2" class="combination-action" @click="openGroupRefund(row)">整团退款</a>
                    </template>
                </Table>
            </div>
            <footer class="combination-pagination">
                <span>共 {{ total }} 条 · 每页 20 条 · 第 {{ formValidate.page }} / {{ totalPages }} 页</span>
                <Button size="small" :disabled="loading || formValidate.page <= 1" @click="pageChange(formValidate.page - 1)">上一页</Button>
                <Button size="small" :disabled="loading || formValidate.page >= totalPages" @click="pageChange(formValidate.page + 1)">下一页</Button>
            </footer>
        </Card>
        <Modal v-model="groupRefundVisible" title="成团后整团退款" width="850" footer-hide :mask-closable="false">
            <p>团编号：{{ refundGroup.id }}。全部真实成员校验通过后，按各自实付金额原路退款；虚拟名额不退款。</p>
            <p style="margin:10px 0">只要一人不符合条件，就不发起任何新退款。渠道结果可能分批返回，已成功退款不会重复退。</p>
            <Table :columns="groupMemberColumns" :data="groupRefundMembers" :loading="groupMembersLoading" size="small" :max-height="240" style="margin:12px 0" />
            <Input v-model="groupRefundReason" type="textarea" :maxlength="300" placeholder="请输入整团退款原因" />
            <div style="margin:12px 0;display:flex;gap:10px">
                <Button type="error" :loading="groupRefundBusy" :disabled="groupMembersLoading || !groupRefundMembers.length" @click="confirmGroupRefund">确认整团退款</Button>
                <Button :disabled="groupRefundBusy" @click="runGroupRefund('query')">查询整团退款结果</Button>
                <Button v-if="groupRefundResult.failed_count" :disabled="groupRefundBusy" @click="confirmGroupRefund('retry')">重试失败退款</Button>
            </div>
            <p v-if="groupRefundResult.message">{{ groupRefundResult.message }}：成功 {{ groupRefundResult.success_count }} 笔，失败 {{ groupRefundResult.failed_count }} 笔，待处理 {{ groupRefundResult.pending_count }} 笔</p>
            <Table :columns="refundResultColumns" :data="groupRefundResult.results || []" size="small" :max-height="350" />
        </Modal>

        <!-- 奖励独立回执只重试失败份数；已使用权益只显示人工处理，不扣会员余额。 -->
        <Modal v-model="leaderRewardVisible" title="团长成团奖励" width="760" footer-hide :mask-closable="false">
            <p>团编号：{{ leaderRewardGroupId }} · {{ leaderReward.status_label }}</p>
            <p v-if="leaderRewardError" role="status" style="color:#b92b2b;margin:12px 0">{{ leaderRewardError }}</p>
            <Table :loading="leaderRewardLoading" :columns="leaderRewardColumns" :data="leaderReward.items || []" :max-height="400" size="small" style="margin:12px 0" />
            <p>只发放真实成团时冻结的奖励。团长原单全退或整团取消后，撤销未使用奖励；已使用奖励需人工处理。</p>
            <div style="display:flex;justify-content:flex-end;gap:12px;margin-top:16px">
                <Button :loading="leaderRewardLoading" @click="loadLeaderReward(false)">刷新</Button>
                <Button v-if="['pending','revoke_pending'].includes(leaderReward.status)" type="primary" :loading="leaderRewardLoading" @click="loadLeaderReward(true)">重试待处理奖励</Button>
            </div>
        </Modal>
        <!-- 详情模态框-->
        <Modal v-model="modals" class="combination-participants-modal" scrollable footer-hide closable title="查看详情" :mask-closable="false" width="1150">
            <Table ref="selection" :columns="columns2" :data="tabList3" :loading="loading2"
                   no-data-text="暂无数据" highlight-row max-height="600" size="small"
                   no-filtered-data-text="暂无筛选结果">
                <template slot-scope="{ row, index }" slot="nickname">
                    <span> {{row.nickname + ' / ' + row.uid}}</span>
										<span style="color: #ed4014;" v-if="row.delete_time != null"> (已注销)</span>
                </template>
                <template slot-scope="{ row, index }" slot="avatar">
                    <viewer>
                        <div class="tabBox_img">
                            <img v-lazy="row.avatar">
                        </div>
                    </viewer>
                </template>
                <template slot-scope="{ row, index }" slot="action">
                    <div>{{ row.refund_message || (row.is_refund ? '已退款' : '未退款') }}</div>
                    <!-- Amount comes from actual paid money. A pending request can only query its original receipt. -->
                    <Button v-if="rows.status !== 2 && row.refund_action" size="small" :loading="refundBusy === row.order_id_key"
                        @click="refundParticipant(row)">{{ row.refund_action === 'query' ? '查询退款结果' : (row.refund_action === 'retry' ? '重试退款' : '退款') }}</Button>
                    <Button v-if="rows.status === 1 && row.k_id > 0 && !row.is_virtual && !row.is_refund && row.refund_action === 'refund'"
                        size="small" style="margin-left:6px" @click="refundParticipant(row, true)">踢出</Button>
                    <div v-else-if="row.refund_block_reason" style="font-size:12px;color:#999">{{ row.refund_block_reason }}</div>
                </template>
            </Table>
        </Modal>
    </div>
</template>

<script>
    import formatBusinessMoney from '@/utils/formatBusinessMoney.js';
    import OrganizationStoreScopePicker from '@/components/organization/OrganizationStoreScopePicker.vue';
    import exportExcel from '@/utils/newToExcel.js';
    // 复用平台统一请求客户端；libs 下没有请求模块，错误路径会阻断整个平台开发编译。
    import request from '@/plugins/request';
    import { formatDate } from '@/utils/validate';
    import { combineListApi, orderPinkListApi, combinationParticipantRefundApi, combinationCompleteApi, combinationWholeRefundApi } from '@/api/marketing';

    export default {
        name: 'combinalist',
        filters: {
            formatDate (time) {
                if (time !== 0) {
                    let date = new Date(time * 1000);
                    return formatDate(date, 'yyyy-MM-dd hh:mm');
                }
            }
        },
        components: { OrganizationStoreScopePicker },
        data () {
            return {
                activities: [],
                leaderRewardVisible: false, leaderRewardLoading: false, leaderRewardGroupId: 0, leaderReward: {}, leaderRewardError: '', leaderRewardEpoch: 0,
                leaderRewardColumns: [{ title: '奖励内容', key: 'name', minWidth: 180 }, { title: '发放状态', key: 'status_label', minWidth: 150 },
                    { title: '处理说明', key: 'message', minWidth: 260, render: (h, { row }) => h('span', { style: { whiteSpace: 'normal', wordBreak: 'break-word' } }, row.message || '—') }],
                appliedFilters: {}, exporting: false, listError: '', tableHeight: 400, tableFixed: false, requestSequence: 0,
                groupRefundVisible: false, groupRefundBusy: false, groupRefundReason: '', refundGroup: {}, groupRefundResult: {},
                groupRefundMembers: [], groupMembersLoading: false,
                groupMemberColumns: [
                    { title: '成员', key: 'nickname', width: 110 }, { title: '订单号', key: 'order_id', minWidth: 190 },
                    { title: '原单实付', width: 100, render: (h, { row }) => h('span', formatBusinessMoney(row.paid_amount)) },
                    { title: '当前退款情况', minWidth: 230, render: (h, { row }) => h('span', { style: { whiteSpace: 'normal', wordBreak: 'break-word', lineHeight: '1.5' } }, row.refund_block_reason || row.refund_message || '待校验') }
                ],
                refundResultColumns: [
                    { title: '订单ID', key: 'order_id_key', width: 100 },
                    { title: '原支付渠道', width: 105,
                        render: (h, { row }) => h('span', row.provider === 'suixingpay' ? '随行付'
                            : (row.provider === 'balance' ? '余额' : (row.provider === 'fuyou' ? '富友' : '—'))) },
                    { title: '退款状态', key: 'message', minWidth: 180, render: (h, { row }) => h('span', { style: { whiteSpace: 'normal', wordBreak: 'break-word', lineHeight: '1.5' } }, row.message) },
                    { title: '退款金额', key: 'amount', width: 100, render: (h, { row }) => h('span', formatBusinessMoney(row.amount)) },
                    { title: '退款单号', key: 'refund_no', minWidth: 220 }
                ],
                refundBusy: 0,
                modals: false,
                loading: false,
                formValidate: {
                    status: '',
                    data: '',
                    cid: '',
                    group_id: '',
                    store_ids: [], keyword: '',
                    page: 1,
                    limit: 20
                },
                columns1: [],
                tableList: [],
                total: 0,
                loading2: false,
                tabList3: [],
                columns2: [
                    {
                        title: 'ID',
                        key: 'id',
                        width: 80
                    },
                    {
                        title: '用户名称',
                        slot: 'nickname',
                        width: 170
                    },
                    {
                        title: '用户头像',
                        slot: 'avatar', width: 80
                    },
                    {
                        title: '订单编号',
                        key: 'order_id', width: 185
                    },
                    {
                        title: '金额',
                        width: 90,
                        render: (h, params) => h('span', formatBusinessMoney(params.row.paid_amount))
                    },
                    {
                        title: '退款状态及操作',
                        slot: 'action', width: 190
                    },
                    { title: '原支付渠道', width: 105,
                        render: (h, { row }) => h('span', row.provider === 'suixingpay' ? '随行付'
                            : (row.provider === 'balance' ? '余额' : (row.provider === 'fuyou' ? '富友' : '—'))) },
                    ...[
                        ['原渠道订单号', 'channel_order_no'], ['原支付交易号', 'channel_transaction_id'],
                        // 列名使用通用业务名称，仍读取原渠道追踪字段，不改变历史退款凭据。
                        ['渠道退款流水号', 'channel_refund_id'], ['退款追踪号', 'fuyou_refund_trace_no'],
                        ['商户退款单号', 'refund_no']
                    ].map(([title, key]) => ({
                        title, key, width: 205,
                        // The table stays compact while full payment references remain inspectable on hover.
                        render: (h, { row }) => h('span', { attrs: { title: row[key] || '—' },
                            class: 'combination-participants-modal__reference' }, row[key] || '—')
                    }))
                ],
                rows: {}
            }
        },
        computed: {
            totalPages() { return Math.max(1, Math.ceil(this.total / 20)); },

        },
        created () {
            this.appliedFilters = this.snapshotFilters();
            this.getList();
            this.loadScope().catch(error => this.$Message.error(error.msg || '筛选项读取失败'));
        },
        mounted() {
            // Measure only this result box: table body scrolls while filters and pagination stay reachable.
            this.resizeObserver = new ResizeObserver(() => this.fitTable());
            this.resizeObserver.observe(this.$el);
            this.resizeObserver.observe(this.$el.querySelector('.combination-filters'));
            this.fitTable();
        },
        beforeDestroy() {
            this.requestSequence++;
            if (this.resizeObserver) this.resizeObserver.disconnect();
        },
        methods: {
            money: formatBusinessMoney,
            openLeaderReward(row) { this.leaderRewardGroupId = row.id; this.leaderReward = {}; this.leaderRewardError = ''; this.leaderRewardVisible = true; this.loadLeaderReward(false); },
            // 弹窗切换团时丢弃迟到响应，不把另一团的发放/撤销状态写入当前团。
            async loadLeaderReward(retry) {
                const epoch = ++this.leaderRewardEpoch; const groupId = this.leaderRewardGroupId; this.leaderRewardLoading = true; this.leaderRewardError = '';
                try {
                    const res = await request({ url: 'marketing/combination/leader-reward/' + groupId + (retry ? '/retry' : ''), method: retry ? 'post' : 'get' });
                    if (epoch === this.leaderRewardEpoch) this.leaderReward = res.data;
                } catch (error) { if (epoch === this.leaderRewardEpoch) this.leaderRewardError = error.msg || error.message || '奖励读取失败'; }
                finally { if (epoch === this.leaderRewardEpoch) this.leaderRewardLoading = false; }
            },
            // Cap long lists at the available viewport; short lists keep their natural height like other platform tables.
            fitTable() {
                const results = this.$refs.results;
                if (!results) return;
                const bottom = Math.min(window.innerHeight, this.$el.getBoundingClientRect().bottom);
                this.tableHeight = Math.max(180, Math.floor(bottom - results.getBoundingClientRect().top - 82));
            },
            snapshotFilters() { return { ...this.formValidate, store_ids: [...this.formValidate.store_ids] }; },
            loadScope() {
                return combineListApi({ view: 'options' }).then(res => { this.activities = res.data.activities; return res.data; });
            },
            resetSearch() {
                this.formValidate = { status: '', data: '', cid: '', group_id: '', store_ids: [], keyword: '', page: 1, limit: 20 };
                this.userSearchs();
            },
            // Export uses submitted conditions, never an unsent filter draft or only the current page.
            exportList() {
                this.exporting = true;
                return combineListApi({ ...this.appliedFilters, view: 'export' }).then(({ data }) => {
                    exportExcel(data.header, data.filekey, data.filename, data.export);
                }).catch(error => this.$Message.error(error.msg || '导出失败')).finally(() => { this.exporting = false; });
            },
            openGroupRefund(row) {
                if (this.groupRefundBusy) return this.$Message.warning('当前整团退款正在处理，请等待结果后再切换');
                this.refundGroup = row; this.groupRefundReason = ''; this.groupRefundResult = {}; this.groupRefundVisible = true;
                this.groupRefundMembers = []; this.groupMembersLoading = true;
                // Preview exact original orders before the confirmation; final authority remains the group transaction.
                return orderPinkListApi(row.id).then(({ data }) => {
                    if (this.refundGroup.id === row.id) this.groupRefundMembers = data.list.filter(member => !member.is_virtual && member.uid > 0);
                }).catch(error => this.$Message.error(error.msg || '团成员读取失败'))
                    .finally(() => { if (this.refundGroup.id === row.id) this.groupMembersLoading = false; });
            },
            confirmGroupRefund(action = 'start') {
                if (action !== 'retry') action = 'start';
                if (!this.groupRefundReason.trim()) return this.$Message.warning('请填写整团退款原因');
                this.$Modal.confirm({ title: action === 'retry' ? '重试失败退款' : '确认整团退款',
                    content: '将校验并退款给本团全部真实付款成员。确认继续？', onOk: () => this.runGroupRefund(action) });
            },
            runGroupRefund(action) {
                if (this.groupRefundBusy) return;
                this.groupRefundBusy = true;
                return combinationWholeRefundApi(this.refundGroup.id, { action, reason: this.groupRefundReason.trim() || '查询整团退款' })
                    .then(({ data }) => {
                        this.groupRefundResult = data;
                        // Match server results to the original participant order, so the current
                        // status column immediately explains verified channel feedback as well.
                        const results = new Map((data.results || []).map(item => [String(item.order_id_key), item]));
                        this.groupRefundMembers = this.groupRefundMembers.map(member => {
                            const result = results.get(String(member.order_id_key));
                            return result && result.message ? { ...member, refund_block_reason: '', refund_message: result.message } : member;
                        });
                        this.getList();
                    })
                    .catch(error => this.$Message.error(error.msg || '整团退款处理失败'))
                    .finally(() => { this.groupRefundBusy = false; });
            },
            // Completion fills only missing seats and leaves real participants' paid orders untouched.
            completeGroup(row) {
                this.$Modal.confirm({ title: '立即成团', content: '将自动补齐缺少的虚拟名额并使拼团成功，是否继续？',
                    onOk: () => combinationCompleteApi(row.id).then(res => {
                        this.$Message.success(res.data.message); this.getList();
                    }).catch(error => this.$Message.error(error.msg || '成团失败')) });
            },
            // Query/retry states are server-derived; the browser never supplies a refund amount or provider identity.
            refundParticipant(row, kick = false) {
                const run = (reason) => {
                    this.refundBusy = row.order_id_key;
                    return combinationParticipantRefundApi(this.rows.id, {
                        order_id: row.order_id_key, reason,
                        retry: row.refund_action !== 'query', action: kick ? 'kick' : 'refund'
                    }).then(res => {
                        this.$Message.info(res.data.message);
                        this.getList();
                        this.Info({ ...this.rows, id: res.data.group_id || this.rows.id });
                    }).catch(error => this.$Message.error(error.msg || '退款处理失败'))
                      .finally(() => { this.refundBusy = 0; });
                };
                if (row.refund_action === 'query') return run(row.refund_reason || '查询拼团退款');
                let reason = '';
                this.$Modal.confirm({
                    title: kick ? '确认退款并踢出团员' : '确认整笔原路退款',
                    render: h => h('div', [h('p', `订单：${row.order_id}，实际退款：¥${formatBusinessMoney(row.paid_amount)}。退款成功后收回全部未使用权益。`),
                        h('Input', { props: { type: 'textarea', placeholder: '请输入退款原因', maxlength: 300 },
                            on: { input: value => { reason = value; } } })]),
                    onOk: () => { if (!reason.trim()) this.$Message.warning('请填写退款原因'); else return run(reason.trim()); }
                });
            },
            // 查看详情
            Info (row) {
                this.modals = true;
                this.rows = row; this.tabList3 = []; this.loading2 = true;
                orderPinkListApi(row.id).then(async res => {
                    let data = res.data
                    this.tabList3 = data.list;
                    this.loading2 = false;
                }).catch(res => {
                    this.loading2 = false;
                    this.$Message.error(res.msg);
                })
            },
            getList() {
                const sequence = ++this.requestSequence;
                this.loading = true; this.listError = '';
                return combineListApi(this.appliedFilters).then(({ data }) => {
                    if (sequence !== this.requestSequence) return;
                    this.tableList = data.list; this.total = data.count; this.formValidate.page = data.page;
                    this.appliedFilters.page = data.page;
                    this.tableFixed = !!(data.table_layout && data.table_layout.fixed);
                    this.columns1 = data.columns.map(column => ({ ...column,
                        fixed: this.tableFixed ? column.fixed : undefined,
                        ...( ['price', 'deadline', 'status_name'].includes(column.key) ? { slot: column.key } : {}),
                        tooltip: true
                    })).concat([{ title: '操作', slot: 'action', fixed: this.tableFixed ? 'right' : undefined, width: 220 }]);
                }).catch(error => {
                    if (sequence !== this.requestSequence) return;
                    this.tableList = []; this.total = 0; this.listError = error.msg || '拼团列表加载失败';
                }).finally(() => { if (sequence === this.requestSequence) this.loading = false; });
            },
            pageChange(index) { this.formValidate.page = index; this.appliedFilters.page = index; this.getList(); },
            userSearchs() { this.formValidate.page = 1; this.appliedFilters = this.snapshotFilters(); this.getList(); }

        }
    }
</script>

<style scoped>
.combination-group-list { height: calc(100vh - 150px); min-height: 420px; display: flex; flex-direction: column; gap: 16px; }
.combination-filters { flex: none; }
.combination-query-form { display: flex; flex-wrap: wrap; align-items: flex-start; gap: 14px 16px; }
.combination-query-form .ivu-form-item { margin: 0; }
.query-store { width: 218px; }
.query-activity { width: 264px; }
.query-status { width: 208px; }
.query-member { width: 294px; }
.combination-filters >>> .ivu-form-item-label { line-height: 32px; padding: 0 8px 0 0; }
.combination-filters >>> .ivu-form-item-content { line-height: 32px; }
.combination-filters >>> .ivu-select, .combination-filters >>> .ivu-date-picker { width: 100%; }
.query-store >>> .organization-store-scope-picker, .query-store >>> .organization-store-scope-picker__trigger { width: 100%; }
.combination-search-actions { display: flex; align-items: center; gap: 10px; }
.combination-results { flex: 0 1 auto; min-height: 0; }
.combination-results >>> .ivu-card-body { display: flex; flex-direction: column; min-height: 0; }
.combination-table-scroll { min-height: 0; overflow: hidden; }
.combination-table-scroll >>> .ivu-table-header th { height: 48px; background: #f8f8f9; }
.combination-table-scroll >>> .ivu-table-cell { padding: 0 12px; }
.combination-table-scroll >>> .ivu-table-row td { height: 48px; }
.combination-action { margin-left: 12px; }
.combination-pagination { flex: none; display: flex; align-items: center; justify-content: flex-end; gap: 12px; padding-top: 18px; color: #808695; }
.combination-list-error { padding: 8px 0 12px; color: #c53b37; }
.combination-status-2 { color: #299548; }
.combination-status-3 { color: #c53b37; }
.tabBox_img { width: 36px; height: 36px; border-radius: 4px; cursor: pointer; }
.tabBox_img img { width: 100%; height: 100%; }
@media (max-width: 1100px) { .query-store, .query-activity, .query-status, .query-member { width: calc(50% - 8px); } }
@media (max-width: 650px) { .query-store, .query-activity, .query-status, .query-member { width: 100%; } .combination-pagination { flex-wrap: wrap; } }
</style>

<style lang="stylus">
// Scope the compact cells to this dialog; wide payment references scroll inside the result table.
.combination-participants-modal
    .ivu-modal
        max-width calc(100vw - 24px)
    .ivu-modal-body
        padding 12px
    .ivu-table-header th
        height 42px !important
        white-space nowrap
    .ivu-table-cell
        padding 6px 10px !important
        white-space nowrap
        overflow hidden
        text-overflow ellipsis
        line-height 20px
    .ivu-table-row td
        height 48px
    &__reference
        display inline-block
        max-width 100%
        overflow hidden
        text-overflow ellipsis
        vertical-align middle
</style>
