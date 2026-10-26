<template>
  <!-- 社区评论列表 -->
  <div>
    <Card :bordered="false" dis-hover class="ivu-mt" :padding="0">
      <div class="new_card_pd">
        <!-- 查询条件 -->
        <Form
          ref="tableFrom"
          :model="tableFrom"
          :label-width="labelWidth"
          :label-position="labelPosition"
          inline
          @submit.native.prevent
        >
          <FormItem label="时间选择：">
            <DatePicker
              :editable="false"
              @on-change="createTime"
              :value="timeVal"
              format="yyyy/MM/dd"
              type="daterange"
              placement="bottom-start"
              placeholder="时间选择"
              :options="options"
              class="selwidth"
            ></DatePicker>
          </FormItem>
          <FormItem label="审核状态：">
            <Select
              v-model="tableFrom.is_verify"
              clearable
              class="selwidth"
              @on-change="tableSearchs"
            >
              <Option value="0">待审核</Option>
              <Option value="1">审核通过</Option>
              <Option value="-1">审核不通过</Option>
            </Select>
          </FormItem>
          <FormItem label="评论搜索：">
            <Input
              v-model="tableFrom.keyword"
              placeholder="请输入"
              element-id="name"
              clearable
              class="selwidth"
              maxlength="20"
            >
              <Select
                v-model="tableFrom.field_key"
                slot="prepend"
                style="width: 80px"
                default-label="全部"
              >
                <Option value="community">文章标题</Option>
                <Option value="id">评论 ID</Option>
                <Option value="comment">评论内容</Option>
                <Option value="user">用户昵称</Option>
              </Select>
            </Input>
          </FormItem>
          <FormItem>
            <Button type="primary" @click="tableSearchs" class="mr14"
              >查询</Button
            >
            <Button @click="reset">重置</Button>
          </FormItem>
        </Form>
      </div>
    </Card>
    <Card :bordered="false" dis-hover class="ivu-mt">
      <Table
        :columns="columns"
        :data="tableList"
        :loading="loading"
        highlight-row
        no-userFrom-text="暂无数据"
        no-filtered-userFrom-text="暂无筛选结果"
      >
        <template slot-scope="{ row }" slot="is_show">
          <i-switch
            :disabled="isAgentAdmin"
            v-model="row.is_show"
            :value="row.is_show"
            :true-value="1"
            :false-value="0"
            @on-change="onchangeStatus(row)"
            size="large"
          >
            <span slot="open">开启</span>
            <span slot="close">关闭</span>
          </i-switch>
        </template>
        <template slot-scope="{ row }" slot="content">
          <Tooltip
            theme="dark"
            max-width="300"
            :content="row.content"
            :delay="600"
            :transfer="true"
          >
            <div class="title line2">{{ row.content }}</div>
          </Tooltip>
        </template>
        <template slot-scope="{ row }" slot="is_verify">
          <Tag color="blue" size="medium" v-if="row.is_verify == 0">待审核</Tag>
          <Tag color="green" size="medium" v-else-if="row.is_verify == 1"
            >审核通过</Tag
          >
          <Tag color="red" size="medium" v-else-if="row.is_verify == -1"
            >审核不通过</Tag
          >
        </template>
        <template slot-scope="{ row, index }" slot="action">
          <a
            v-auth="['admin-community-comment-reply']"
            @click="reply(row)"
            v-if="row.is_verify == 1"
            >回复</a
          >
          <Poptip
            v-auth="['admin-community-comment-verify']"
            transfer
            confirm
            title="确定审核通过?"
            cancel-text="拒绝"
            @on-ok="verify(row, 1)"
            @on-cancel="verify(row, -1)"
            v-else-if="row.is_verify == 0"
          >
            <a>审核</a>
          </Poptip>
          <Divider
            v-auth="[
              'admin-community-comment-verify',
              'admin-community-comment-reply',
            ]"
            type="vertical"
            v-show="row.is_verify != -1"
          />
          <a
            v-auth="['admin-community-comment-delete']"
            @click="del(row, '删除社区评论', index)"
            >删除</a
          >
          <!-- <Divider type="vertical" />
          <a @click="lookChild(row)">查看回复</a> -->
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
    </Card>
  </div>
</template>

<script>
import { mapState } from "vuex";
import timeOptions from "@/utils/timeOptions";
import {
  commentListApi,
  commentReplyApi,
  commentVerifyApi,
  communityReplyStatusApi,
} from "@/api/community";

export default {
  name: "comment",
  data() {
    return {
      options: timeOptions,
      timeVal: [],
      tableFrom: {
        page: 1,
        limit: 15,
        data: "",
        keyword: "",
        is_verify: "",
        is_reply: "",
        field_key: "",
      },
      loading: false,
      columns: [
        {
          title: "ID",
          key: "id",
          width: 80,
        },
        {
          title: "用户昵称",
          key: "author",
          minWidth: 150,
        },
        {
          title: "文章标题",
          key: "title",
          minWidth: 150,
        },
        {
          title: "评论内容",
          slot: "content",
          minWidth: 200,
        },
        {
          title: "显示隐藏",
          slot: "is_show",
          minWidth: 100,
        },
        {
          title: "回复数",
          key: "comment_num",
          minWidth: 100,
        },
        {
          title: "点赞数",
          key: "like_num",
          minWidth: 100,
        },
        {
          title: "待审核数",
          key: "verify_count",
          minWidth: 100,
        },
        {
          title: "上级评论",
          key: "comment_reply_content",
          minWidth: 100,
        },
        {
          title: "评论时间",
          key: "add_time",
          minWidth: 150,
        },
        {
          title: "审核状态",
          slot: "is_verify",
          minWidth: 100,
        },
        {
          title: "操作",
          slot: "action",
          fixed: "right",
          width: 170,
        },
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
      showReplyModal: false,
      replyList: [],
      replyForm: {
        page: 1,
        limit: 20,
        is_reply: 0,
        reply_id: "",
      },
      replyTotal: 0,
      replyLoading: false,
      isAgentAdmin: this.__isAgentPath(),
    };
  },
  computed: {
    ...mapState("admin/layout", ["isMobile"]),
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
    createTime(e) {
      this.timeVal = e;
      this.tableFrom.time = this.timeVal.join("-");
      this.tableSearchs();
    },
    // 列表
    getList() {
      this.loading = true;
      commentListApi(this.tableFrom)
        .then((res) => {
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
    lookChild(row) {
      this.replyForm.reply_id = row.id;
      this.showReplyModal = true;
      this.getReplyList();
    },
    getReplyList() {
      this.replyLoading = true;
      commentListApi(this.replyForm)
        .then((res) => {
          let data = res.data;
          this.replyList = data.list;
          this.replyTotal = res.data.count;
          this.replyLoading = false;
        })
        .catch((res) => {
          this.replyLoading = false;
          this.$Message.error(res.msg);
        });
    },
    replyPageChange(val) {
      this.replyForm.page = val;
      this.getReplyList();
    },
    // 表格搜索
    tableSearchs() {
      this.tableFrom.page = 1;
      this.getList();
    },
    reset() {
      this.tableFrom.data = "";
      this.tableFrom.keyword = "";
      this.tableFrom.is_verify = "";
      this.tableFrom.field_key = "";
      this.tableFrom.page = 1;
      this.tableFrom.time = "";
      this.is_reply = 1;
      this.field_key = "";
      this.timeVal = [];
      this.getList();
    },
    // 回复
    reply(row) {
      this.$modalForm(commentReplyApi(row.id)).then(() => this.getList());
    },
    // 审核
    verify(row, type) {
      commentVerifyApi(row.id, { is_verify: type })
        .then((res) => {
          this.$Message.success(res.msg);
          this.getList();
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    // 删除
    del(row, tit, num) {
      let delfromData = {
        title: tit,
        num: num,
        url: `/community/comment/del/${row.id}`,
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
    onchangeStatus(row) {
      communityReplyStatusApi(row.id, row.is_show)
        .then((res) => {
          this.$Message.success(res.msg);
          this.getList();
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
  },
};
</script>

<style scoped lang="stylus">
.line2 {
  max-height: 36px;
}

.selwidth {
  width: 250px;
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
