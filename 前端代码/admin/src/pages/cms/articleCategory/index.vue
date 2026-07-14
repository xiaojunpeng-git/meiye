<template>
<!-- 营销-文章分类 -->
    <div>
        <Card :bordered="false" dis-hover class="ivu-mt" :padding= "0">
        <div class="new_card_pd">
            <!-- 查询条件 -->
            <Form ref="formValidate" :model="formValidate"  
            inline
            :label-width="labelWidth" :label-position="labelPosition" @submit.native.prevent>
                <FormItem label="是否显示："  label-for="status">
                    <Select v-model="formValidate.status" placeholder="请选择" element-id="status" clearable 
                    class="input-add"
                    @on-change="userSearchs">
                        <Option value="1">显示</Option>
                        <Option value="0">隐藏</Option>
                    </Select>
                </FormItem>
                <FormItem label="分类昵称：" prop="title" label-for="status2">
                    <Input placeholder="请输入分类昵称" v-model="formValidate.title" @on-search="userSearchs"
                    class="input-add mr14"/>
                    <Button type="primary" @click="userSearchs()" class="mr10">查询</Button>
                </FormItem>
            </Form>
        </div>
        </Card>
        <Card :bordered="false" dis-hover class="ivu-mt">
            <!-- 操作 -->
            <Button v-auth="['cms-category-create']" type="primary"  @click="add">添加文章分类</Button>
            <!-- 分类文章-表格 -->
            <vxe-table
                class="ivu-mt"
                highlight-hover-row
                :loading="loading"
                header-row-class-name="false"
                :tree-config="{children: 'children'}"
                :data="categoryList">
                <vxe-table-column field="id" title="ID"  tooltip width="80"></vxe-table-column>
                <vxe-table-column field="title" title="分类昵称" min-width="130"></vxe-table-column>
                <vxe-table-column field="image" title="分类图片"  min-width="130">
                    <template v-slot="{ row }">
                        <viewer>
                            <div class="tabBox_img">
                                <img v-lazy="row.image">
                            </div>
                        </viewer>
                    </template>
                </vxe-table-column>
                <vxe-table-column field="status" title="状态" min-width="120">
                    <template v-slot="{ row }">
                        <i-switch v-model="row.status" :value="row.status" :true-value="1" :false-value="0" @on-change="onchangeIsShow(row)" size="large">
                            <span slot="open">显示</span>
                            <span slot="close">隐藏</span>
                        </i-switch>
                    </template>
                </vxe-table-column>
                <vxe-table-column field="date" title="操作" align="center"  width="250" fixed="right">
                    <template v-slot="{ row }">
                        <a @click="edit(row)">编辑</a>
                        <Divider type="vertical" />
                        <a @click="del(row,'删除文章分类')">删除</a>
                        <Divider type="vertical" />
                        <a @click="lookUp(row)">查看文章</a>
                    </template>
                </vxe-table-column>
            </vxe-table>
        </Card>
     </div>
</template>
<script>
    import { mapState, mapMutations } from 'vuex';
    import { categoryAddApi, categoryEditApi, categoryListApi, statusApi } from '@/api/cms';
    import Setting from "@/setting";
    export default {
        name: 'articleCategory',
        data () {
            return {
              roterPre: Setting.roterPre,
                grid: {
                    xl: 7,
                    lg: 7,
                    md: 12,
                    sm: 24,
                    xs: 24
                },
                loading: false,
                formValidate: {
                    status: '',
                    page: 1,
                    limit: 20,
                    type: 0
                },
                total: 0,
                columns1: [
                    {
                        title: 'ID',
                        key: 'id',
                        width: 80
                    },
                    {
                        title: '分类昵称',
                        key: 'title',
                        minWidth: 130
                    },
                    {
                        title: '分类图片',
                        slot: 'images',
                        minWidth: 130
                    },
                    {
                        title: '状态',
                        slot: 'statuss',
                        minWidth: 130
                    },
                    {
                        title: '操作',
                        slot: 'action',
                        fixed: 'right',
                        minWidth: 120
                    }
                ],
                FromData: null,
                modalTitleSs: '',
                categoryId: 0,
                categoryList: []
            }
        },
        computed: {
            ...mapState('admin/layout', [
                'isMobile'
            ]),
            labelWidth () {
                return this.isMobile ? undefined : 96;
            },
            labelPosition () {
                return this.isMobile ? 'top' : 'right';
            }
        },
        mounted () {
            this.getList();
        },
        methods: {
            ...mapMutations('admin/userLevel', [
                'getCategoryId'
            ]),
            // 添加
            add () {
                this.$modalForm(categoryAddApi()).then(() => this.getList());
            },
            // 编辑
            edit (row) {
                this.$modalForm(categoryEditApi(row.id)).then(() => this.getList());
            },
            // 删除
            del (row, tit) {
                let delfromData = {
                    title: tit,
                    num: 0,
                    url: `cms/category/${row.id}`,
                    method: 'DELETE',
                    ids: ''
                }
                this.$modalSure(delfromData).then((res) => {
                    this.$Message.success(res.msg);
                    this.getList();
                }).catch(res => {
                    this.$Message.error(res.msg);
                });
            },
            // 列表
            getList () {
                this.loading = true;
                categoryListApi(this.formValidate).then(async res => {
                    let data = res.data
                    this.categoryList = data.list;
                    this.total = data.count;
                    this.loading = false;
                }).catch(res => {
                    this.loading = false;
                    this.$Message.error(res.msg);
                })
            },
            pageChange (index) {
                this.formValidate.page = index;
                this.getList();
            },
            // 表格搜索
            userSearchs () {
                this.formValidate.page = 1;
                this.getList();
            },
            // 修改是否显示
            onchangeIsShow (row) {
                let data = {
                    id: row.id,
                    status: row.status
                }
                statusApi(data).then(async res => {
                    this.$Message.success(res.msg);
                }).catch(res => {
                    this.$Message.error(res.msg);
                })
            },
            // 查看文章
            lookUp (row) {
                this.$router.push({ path: `${this.roterPre}/cms/article/index`,
                                    query: {
                                        id: row.id
                } });
                // xia mian chu cun mei yong;
                this.getCategoryId(row.id)
            }
        }
    }
</script>

<style scoped lang="stylus">
::v-deep .vxe-cell--title {
  font-size: 12px
}
    .tabBox_img
        width 36px
        height 36px
        border-radius:4px
        cursor pointer
        img
            width 100%
            height 100%
</style>
