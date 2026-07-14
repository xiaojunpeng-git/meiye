<template>
    <!-- 同城配送 -->
    <div>
        <Card :bordered="false" dis-hover class="mt15 ivu-mt" :padding="0">
            <div class="new_card_pd">
                <!-- 查询条件 -->
                <Form
                        ref="orderData"
                        :model="formData"
                        :label-width="labelWidth"
                        :label-position="labelPosition"
                        class="tabform"
                        inline
                        @submit.native.prevent
                >
                    <FormItem label="时间选择：">
                        <DatePicker
                                :editable="false"
                                @on-change="onchangeTime"
                                :value="timeVal"
                                format="yyyy/MM/dd"
                                type="datetimerange"
                                placement="bottom-start"
                                placeholder="自定义时间"
                                class="input-add"
                                :options="options"
                        ></DatePicker>
                    </FormItem>
                    <FormItem label="配送状态：">
                        <Select v-model="formData.status" clearable placeholder="全部" class="input-add" @on-change='orderSearch'>
                            <Option value="">全部</Option>
                            <Option value="2">待取货</Option>
                            <Option value="3">配送中</Option>
                            <Option value="4">已完成</Option>
                            <Option value="-1">已取消</Option>
                            <Option value="9">物品返回中</Option>
                            <Option value="10">物品返回完成</Option>
                            <Option value="100">骑士到店</Option>
                        </Select>
                    </FormItem>
                    <FormItem
                            label="订单搜索："
                            label-for="nickname"
                    >
                        <Input
                                placeholder="请输入配送订单号/原订单号"
                                v-model="formData.keyword"
                                class="input-add"
                        />
                    </FormItem>
                    <FormItem>
                        <Button type="primary" @click="orderSearch" class="ml75">查询</Button>
                        <Button class="ml20" @click="orderReset">重置</Button>
                    </FormItem>
                </Form>
            </div>
        </Card>
        <Card :bordered="false" dis-hover class="mt15 ivu-mt">
            <Table
                    :columns="columns"
                    :data="data"
                    ref="table"
                    :loading="loading"
                    highlight-row
                    no-userFrom-text="暂无数据"
                    no-filtered-userFrom-text="暂无筛选结果"
            >
                <template slot-scope="{ row }" slot="yOrderId">
                    <div>{{row.orderInfo.order_id}}</div>
                </template>
                <template slot-scope="{ row }" slot="status">
                    <Tag color="orange" size="medium" v-if="row.status == 2">待取货</Tag>
                    <Tag color="blue" size="medium" v-if="row.status == 3">配送中</Tag>
                    <Tag color="default" size="medium" v-if="row.status == 4">已完成</Tag>
                    <Tag color="default" size="medium" v-if="row.status == -1">已取消</Tag>
                    <Tag color="red" size="medium" v-if="row.status == 9">物品返回中</Tag>
                    <Tag color="default" size="medium" v-if="row.status == 10">物品返回完成</Tag>
                    <Tag color="blue" size="medium" v-if="row.status == 100">骑士到店</Tag>
                </template>
                <template slot-scope="{ row }" slot="distance">
                    {{row.distance}}km
                </template>
                <template slot-scope="{ row }" slot="mark">
                    {{row.mark || '-'}}
                </template>
                <template slot-scope="{ row }" slot="action">
                    <a @click="cancelOrder(row)">取消发单</a>
                </template>
            </Table>
            <div class="acea-row row-right page">
                <Page
                :total="total"
                :current="formData.page"
                show-elevator
                show-total
                @on-change="pageChange"
                :page-size="formData.limit"
                @on-page-size-change="limitChange"
                show-sizer
                />
            </div>
        </Card>
    </div>
</template>

<script>
    import { mapState } from "vuex";
    import timeOptions from "@/utils/timeOptions";
    import { deliveryList,deliveryCancelForm } from '@/api/setting';
    export default {
        name: "record",
        components: {},
        data() {
            return {
                options: timeOptions,
                timeVal: [],
                formData:{
                    data:'',
                    status:'',
                    keyword:'',
                    page:1,
                    limit:20
                },
                loading:false,
                columns:[
                    {
                        title: "ID",
                        key: "id",
                        width: 60,
                    },
                    {
                        title: "配送订单号",
                        key: "delivery_no",
                        minWidth: 110,
                    },
                    {
                        title: "原订单号",
                        slot: "yOrderId",
                        minWidth: 110,
                    },
                    {
                        title: "配送起点",
                        key: "from_address",
                        minWidth: 130,
                    },
                    {
                        title: "配送终点",
                        key: "to_address",
                        minWidth: 130,
                    },
                    {
                        title: "配送状态",
                        slot: "status",
                        minWidth: 80,
                    },
                    {
                        title: "配送距离",
                        slot: "distance",
                        minWidth: 80,
                    },
                    {
                        title: "配送费用",
                        key: "fee",
                        minWidth: 80,
                    },
                    {
                        title: "消费时间",
                        key: "add_time",
                        minWidth: 110,
                    },
                    {
                        title: "备注",
                        slot: "mark",
                        minWidth: 90,
                    },
                    {
                        title: '操作',
                        slot: 'action',
                        fixed: 'right',
                        minWidth: 85
                    }
                ],
                data:[],
                total:0
            };
        },
        computed: {
            ...mapState("store/layout", ["isMobile"]),
            labelWidth() {
                return this.isMobile ? undefined : 96;
            },
            labelPosition() {
                return this.isMobile ? "top" : "right";
            },
        },
        created() {
            this.getList();
        },
        methods: {
            cancelOrder(row){
                this.$modalForm(deliveryCancelForm(row.id)).then(() => this.getList())
            },
            orderReset(){
                this.formData = {
                    data:'',
                    status:'',
                    keyword:'',
                    page:1,
                    limit:20
                };
                this.timeVal = [];
                this.getList();
            },
            orderSearch(){
                this.formData.page = 1;
                this.getList();
            },
            pageChange(index){
                this.formData.page = index;
                this.getList()
            },
            limitChange(limit) {
                this.formData.limit = limit;
                this.getList()
            },
            getList(){
                this.loading = true;
                deliveryList(this.formData).then(res=>{
                    this.loading = false;
                    this.data = res.data.list;
                    this.total = res.data.count;
                }).catch(err=>{
                    this.loading = false;
                    this.$Message.error(err.msg);
                })
            },
            // 具体日期
            onchangeTime(e) {
                this.timeVal = e;
                this.formData.data = this.timeVal.join("-");
                if (!e[0]) {
                    this.formData.data = "";
                }
				this.orderSearch();
            }
        },
    };
</script>

<style lang="stylus" scoped>
    .input-add {
        width: 250px;
    }
    .ml75 {
        margin-left:-75px;
    }
    .tabBox {
        width: 100%;
        height: 100%;
        display: flex;
        align-items: center;

        .tabBox_img {
            width: 30px;
            height: 30px;

            img {
                width: 100%;
                height: 100%;
            }
        }

        .tabBox_tit {
            flex 1;
            height:37px;
            font-size: 12px !important;
            margin: 0 2px 0 10px;
            letter-spacing: 1px;
            box-sizing: border-box;
        }
    }
</style>