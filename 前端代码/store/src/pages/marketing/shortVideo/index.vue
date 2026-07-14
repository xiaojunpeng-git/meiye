<template>
    <!-- 营销-短视频列表 -->
    <div>
        <Card :bordered="false" dis-hover class="ivu-mt" :padding= "0">
            <!-- 查询条件 -->
            <div class="new_card_pd">
                <Form
                        ref="tableFrom"
                        :model="tableFrom"
                        :label-width="labelWidth"
                        :label-position="labelPosition"
                        inline
                        @submit.native.prevent
                >
                    <FormItem label="时间筛选：">
                        <DatePicker
                                :editable="false"
                                :clearable="true"
                                @on-change="onchangeTime"
                                :value="timeVal"
                                format="yyyy/MM/dd"
                                type="datetimerange"
                                placement="bottom-start"
                                placeholder="自定义时间"
                                class="input-add"
                                :options="options"></DatePicker>
                    </FormItem>
                    <FormItem label="视频搜索：" label-for="store_name" class="button">
                        <Input
                                placeholder="请输入视频简介/ID"
                                v-model="tableFrom.keyword"
                                @on-search="userSearchs"
                                class="input-add mr14"
                        />
                        <Button type="primary" @click="userSearchs()" class="mr14">查询</Button>
                        <Button @click="reset">重置</Button>
                    </FormItem>
                </Form>
            </div>
        </Card>
        <Card :bordered="false" dis-hover class="ivu-mt">
            <!-- Tab栏切换 -->
            <Tabs @on-click="onClickTab">
                <TabPane
                        :label="item.name"
                        :name="item.type"
                        v-for="(item, index) in headeNum"
                        :key="index"
                />
            </Tabs>
            <!-- 操作 -->
            <Button
                    type="primary"
                    @click="add"
                    class="mr10"
            >添加视频</Button>
            <!-- 视频-表格 -->
            <Table
                    :columns="columns1"
                    :data="tableList"
                    :loading="loading"
                    highlight-row
                    no-userFrom-text="暂无数据"
                    no-filtered-userFrom-text="暂无筛选结果"
                    class="ivu-mt"
            >
                <template slot-scope="{ row }" slot="image">
                    <viewer>
                        <div class="tabBox_img">
                            <img v-lazy="row.image" />
                        </div>
                    </viewer>
                </template>
                <template slot-scope="{ row }" slot="desc">
                    <Tooltip max-width="200" placement="bottom">
                        <span class="line2">{{row.desc}}</span>
                        <p slot="content">{{row.desc}}</p>
                    </Tooltip>
                </template>
                <template slot-scope="{ row }" slot="is_verify">
                    <div v-if="row.type==1">
                        <Tag color="orange" size="medium" v-show="row.is_verify == 0">待审核</Tag>
                        <Tag color="default" size="medium" v-show="row.is_verify == 1">审核通过</Tag>
                        <Tag color="red" size="medium" v-show="row.is_verify == -1">审核未通过</Tag>
                        <Tag color="red" size="medium" v-show="row.is_verify == -2">已强制下架</Tag>
                    </div>
                    <div v-else>-</div>
                </template>
                <template slot-scope="{ row }" slot="is_show">
                    <i-switch
                            v-model="row.is_show"
                            :value="row.is_show"
                            :true-value="1"
                            :false-value="0"
                            :disabled="row.is_verify == -2?true:false"
                            @on-change="onchangeIsShow(row)"
                            size="large"
                    >
                        <span slot="open">开启</span>
                        <span slot="close">关闭</span>
                    </i-switch>
                </template>
                <template slot-scope="{ row }" slot="is_recommend">
                    <div>{{row.is_recommend==1?'是':'否'}}</div>
                </template>
                <template slot-scope="{ row, index }" slot="action">
                    <a @click="seeInfo(row)">详情</a>
                    <Divider type="vertical" />
                    <a @click="edit(row)">编辑</a>
                    <Divider type="vertical" />
                    <a v-if="row.is_verify == 1" @click="comment(row)">查看评论</a>
                    <Divider type="vertical" v-if="row.is_verify == 1" />
                    <a @click="del(row, '删除视频', index)">删除</a>
                </template>
            </Table>
            <div class="acea-row row-right page">
                <Page
                        :total="total"
                        :current="tableFrom.page"
                        show-elevator
                        show-total
                        @on-change="pageChange"
                        :page-size="tableFrom.limit"
                />
            </div>
            <video-details ref="detailss"></video-details>
        </Card>
    </div>
</template>

<script>
    import { mapState } from "vuex";
    import { videoList, videoSetStatus, videoSetRecommend, videoVerify, videoTakeDown } from "@/api/marketing";
    import { formatDate } from "@/utils/validate";
    import timeOptions from "@/utils/timeOptions";
    import videoDetails from './components/videoDetails';
    import Setting from "@/setting";
    export default {
        name: "index",
        components: {
            videoDetails
        },
        filters: {
            formatDate(time) {
                if (time !== 0) {
                    let date = new Date(time * 1000);
                    return formatDate(date, "yyyy-MM-dd");
                }
            },
        },
        data() {
            return {
                timeVal: [],
                tableFrom: {
                    keyword: "",
                    data: "",
                    page: 1,
                    limit: 15,
                    is_verify: ""
                },
                options:timeOptions,
                headeNum: [
                    { type: "", name: "全部" },
                    { type: "1", name: "已通过审核" },
                    { type: "0", name: "待审核" },
                    { type: "-1", name: "审核未通过" },
                    { type: "-2", name: "强制下架" }
                ],
                loading: false,
                columns1: [
                    {
                        title: "ID",
                        key: "id",
                        width: 80,
                    },
                    {
                        title: "视频",
                        slot: "image",
                        minWidth: 90,
                    },
                    {
                        title: "视频介绍",
                        slot: "desc",
                        minWidth: 150,
                    },
                    {
                        title: "浏览数",
                        key: "play_num",
                        minWidth: 100,
                    },
                    {
                        title: "评论数",
                        key: "comment_num",
                        minWidth: 100,
                    },
                    {
                        title: "收藏数",
                        key: "collect_num",
                        minWidth: 100,
                    },
                    {
                        title: "点赞数",
                        key: "like_num",
                        minWidth: 80,
                    },
                    {
                        title: "分享数",
                        key: "share_num",
                        minWidth: 80,
                    },
                    {
                        title: "关联商品",
                        key: "product_num",
                        minWidth: 80,
                    },
                    {
                        title: "审核状态",
                        slot: "is_verify",
                        minWidth: 100,
                    },
                    {
                        title: "是否展示",
                        slot: "is_show",
                        minWidth: 100,
                    },
                    {
                        title: "是否推荐",
                        slot: "is_recommend",
                        minWidth: 100
                    },
                    {
                        title: "排序",
                        key: "sort",
                        minWidth: 80,
                    },
                    {
                        title: "创建时间",
                        key: "add_time",
                        minWidth: 150,
                    },
                    {
                        title: "操作",
                        slot: "action",
                        fixed: "right",
                        minWidth: 200,
                    }
                ],
                tableList: [],
                grid: {
                    xl: 7,
                    lg: 10,
                    md: 12,
                    sm: 24,
                    xs: 24,
                },
                total: 0,
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
            // 具体日期
            onchangeTime(e) {
                this.timeVal = e;
                this.tableFrom.data = this.timeVal[0] ? this.timeVal.join("-") : "";
                this.tableFrom.page = 1;
                this.getList();
            },
            onClickTab(type){
                this.tableFrom.page = 1;
                this.tableFrom.is_verify = type;
                this.getList();
            },
            reset(){
                this.tableFrom.keyword = "";
                this.tableFrom.data = "";
                this.timeVal = "";
                this.tableFrom.page = 1;
                this.tableFrom.is_verify = "";
                this.getList();
            },
            verify(row){
                let data = {
                    id: row.id,
                    verify: row.is_verify
                };
                videoVerify(data).then(res=>{
                    this.$Message.success(res.msg);
                    this.getList()
                }).catch(err=>{
                    this.$Message.error(err.msg)
                })
            },
            comment(row){
                this.$router.push({ path: `${Setting.routePre}/marketing/short_video/comment`,query:{'id':row.id} });
            },
            seeInfo(row){
                this.$refs.detailss.modals = true
                this.$refs.detailss.getInfo(row.id)
                this.$refs.detailss.activeName = "detail"
            },
            force(row){
                videoTakeDown(row.id).then(res=>{
                    this.$Message.success(res.msg);
                    this.getList()
                }).catch(err=>{
                    this.$message.error(err.msg)
                })
            },
            // 添加
            add() {
                this.$router.push({ path: `${Setting.routePre}/marketing/short_video/create` });
            },
            // 列表
            getList() {
                this.loading = true;
                this.tableFrom.is_verify = this.tableFrom.is_verify || "";
                videoList(this.tableFrom)
                    .then(async (res) => {
                        let data = res.data;
                        this.tableList = data.list;
                        this.total = res.data.count;
                        this.loading = false;
                    })
                    .catch((res) => {
                        this.loading = false;
                        this.$Message.error(res.msg);
                    });
            },
            // 编辑
            edit(row) {
                this.$router.push({
                    path: Setting.routePre + "/marketing/short_video/create/" + row.id
                });
            },
            // 删除
            del(row, tit, num) {
                let delfromData = {
                    title: tit,
                    num: num,
                    url: `/marketing/video/del/${row.id}`,
                    method: "DELETE",
                    ids: "",
                };
                this.$modalSure(delfromData)
                    .then((res) => {
                        this.$Message.success(res.msg);
                        this.tableList.splice(num, 1);
                        if (!this.tableList.length) {
                          this.tableFrom.page =
                              this.tableFrom.page == 1 ? 1 : this.tableFrom.page - 1;
                        }
                        this.getList();
                    })
                    .catch((res) => {
                        this.$Message.error(res.msg);
                    });
            },
            pageChange(index) {
                this.tableFrom.page = index;
                this.getList();
            },
            // 表格搜索
            userSearchs() {
                this.tableFrom.page = 1;
                this.getList();
            },
            // 修改是否显示
            onchangeIsShow(row) {
                let data = {
                    id: row.id,
                    status: row.is_show,
                };
                videoSetStatus(data)
                    .then(async (res) => {
                        this.$Message.success(res.msg);
                        this.getList()
                    })
                    .catch((res) => {
                        this.$Message.error(res.msg);
                    });
            },
            // 修改是否推荐
            onchangeIsRecommend(row){
                let data = {
                    id: row.id,
                    recommend: row.is_recommend,
                };
                videoSetRecommend(data)
                    .then(async (res) => {
                        this.$Message.success(res.msg);
                        this.getList()
                    })
                    .catch((res) => {
                        this.$Message.error(res.msg);
                    });
            }
        },
    };
</script>

<style scoped lang="stylus">
    .button{
        /deep/.ivu-btn{
            padding 0 30px!important;
        }
    }
    .line2{
        max-height 36px;
    }
    .tabBox_img {
        width: 36px;
        height: 36px;
        border-radius: 4px;
        cursor: pointer;

        img {
            width: 100%;
            height: 100%;
        }
    }
</style>