<template>
  <div>
    <Card :bordered="false" dis-hover class="ivu-mt" :padding= "0">
      <div class="new_card_pd">
        <Form ref="formValidate" :model="formValidate"  :label-width="labelWidth" :label-position="labelPosition" @submit.native.prevent>
          <FormItem label="是否显示：">
            <Select v-model="formValidate.status" placeholder="请选择" clearable  @on-change="userSearchs"
                    class="input-add mb20">
              <Option value="1">显示</Option>
              <Option value="0">隐藏</Option>
            </Select>
          </FormItem>
        </Form>
      </div>
    </Card>
    <Card :bordered="false" dis-hover>
      <Form ref="formValidate" :model="formValidate"  :label-width="labelWidth" :label-position="labelPosition" @submit.native.prevent>
        <Button type="primary" @click="groupAdd('添加数据')" class="mr20">添加数据</Button>
      </Form>
      <Table :columns="columns1" :data="tabList" ref="table" class="ivu-mt"
             :loading="loading" highlight-row
             no-userFrom-text="暂无数据"
             no-filtered-userFrom-text="暂无筛选结果">
        <template slot-scope="{ row, index }" slot="status">
          <i-switch v-model="row.status" :value="row.status" :true-value="1" :false-value="0" @on-change="onchangeIsShow(row)" size="large">
            <span slot="open">显示</span>
            <span slot="close">隐藏</span>
          </i-switch>
        </template>
        <template slot-scope="{ row, index }" slot="action">
          <a @click="edit(row)">编辑</a>
          <Divider type="vertical"/>
          <a @click="del(row,'删除这条信息',index)">删除</a>
        </template>
      </Table>
      <div class="acea-row row-right page">
        <Page :total="total" :current="formValidate.page" show-elevator show-total @on-change="pageChange"
              :page-size="formValidate.limit"/>
      </div>
    </Card>
  </div>
</template>

<script>
import { mapState } from 'vuex'
import {
  seckillTimeList,
  seckillTimeCreate,
  seckillSetStatus
} from "@/api/marketing";
import Setting from "@/setting";
export default {
  name: 'config',
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
      formValidate: {
        status: '',
        page: 1,
        limit: 20
      },
      total: 0,
      tabList: [],
      columns1: [
        {
          title: "ID",
          key: "id",
          width: 80,
        },
        {
          title: "活动名称",
          key: "title",
          minWidth: 170,
        },
        {
          title: "开始时间",
          key: "start_time",
          minWidth: 150,
        },
        {
          title: "结束时间",
          key: "end_time",
          minWidth: 150,
        },
        {
          title: "描述",
          key: "describe",
          minWidth: 240,
        },
        {
          title: "是否可用",
          slot: "status",
          minWidth: 100,
        },
        {
          title: "操作",
          slot: "action",
          minWidth: 110,
          fixed: 'right'
        },
      ],
      loading: false
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
    // 添加表单
    groupAdd () {
      this.$modalForm(seckillTimeCreate(0)).then(() => this.getList());
    },
    // 编辑表单
    edit (row) {
      this.$modalForm(seckillTimeCreate(row.id)).then(() => this.getList());
    },
    // 列表
    getList () {
      this.loading = true
      seckillTimeList(this.formValidate).then(res => {
        let data = res.data
        this.tabList = data.list
        this.total = data.count
        this.loading = false
      }).catch(res => {
        this.loading = false
        this.$Message.error(res.msg)
      })
    },
    pageChange (index) {
      this.formValidate.page = index
      this.getList()
    },
    // 表格搜索
    userSearchs () {
      this.formValidate.page = 1
      this.getList()
    },
    // 修改是否显示
    onchangeIsShow (row) {
      seckillSetStatus(row).then(async res => {
        this.$Message.success(res.msg)
        this.getList()
      }).catch(res => {
        this.$Message.error(res.msg)
      })
    },
    // 删除
    del(row, tit, num) {
      let delfromData = {
        title: tit,
        num: num,
        url: `/marketing/seckill/time/${row.id}`,
        method: "DELETE",
        ids: "",
      };
      this.$modalSure(delfromData)
          .then((res) => {
            this.$Message.success(res.msg);
            this.tabList.splice(num, 1);
            if (!this.tabList.length) {
              this.formValidate.page =
                  this.formValidate.page == 1 ? 1 : this.formValidate.page - 1;
            }
            this.getList();
          })
          .catch((res) => {
            this.$Message.error(res.msg);
          });
    }
  }
}
</script>

<style scoped lang="stylus">
/deep/ .ivu-menu-vertical .ivu-menu-item-group-title{
  display: none;
}
/deep/ .ivu-menu-vertical.ivu-menu-light:after{
  display none
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
.left-wrapper
  height 904px
  background #fff
  border-right 1px solid #dcdee2
.menu-item
  // z-index 50
  position: relative;
  display flex
  justify-content space-between
  word-break break-all
  .icon-box
    z-index 3
    position absolute
    right 20px
    top 50%
    transform translateY(-50%)
    display none
  &:hover .icon-box
    display block
  .right-menu
    z-index 10
    position absolute
    right: -106px;
    top: -11px;
    width auto
    min-width: 121px;
.tabBox_img
  width 36px
  height 36px
  border-radius:4px
  cursor pointer
  img
    width 100%
    height 100%
.ivu-menu
  z-index auto
</style>
