<template>
  <!--内容详情-->
  <div>
    <Drawer
      :closable="false"
      width="1000px"
      class-name="order_box"
      v-model="modals"
      :styles="{ padding: 0 }"
    >
      <div class="header acea-row row-between-wrapper">
        <div class="acea-row row-middle">
          <div class="pictrue">
            <img :src="formValidate.image" class="obj-contain" />
          </div>
          <div class="text">
            <div class="name">{{ formValidate.title }}</div>
            <div class="info">
              ID：{{ formValidate.id }}
              <span class="time" v-if="formValidate.is_verify == 1"
                >审核时间：{{ formValidate.verify_time }}</span
              >
            </div>
          </div>
        </div>
        <div class="" v-if="formValidate.is_verify == 0">
          <Poptip
            :ref="'poptip_' + formValidate.id"
            placement="bottom"
            width="300"
          >
            <Button>审核拒绝</Button>
            <template #content>
              <div>
                <Form :model="formTurn" :label-width="80">
                  <FormItem label="拒绝原因：">
                    <Input
                      v-model="formTurn.refusal"
                      type="textarea"
                      :rows="4"
                      placeholder="请输入拒绝原因"
                    />
                  </FormItem>
                </Form>
                <div class="acea-row row-right">
                  <Button @click="popCancel()">取消</Button>
                  <Button type="primary" class="ml14" @click="verify(-1)"
                    >确定</Button
                  >
                </div>
              </div>
            </template>
          </Poptip>
          <!-- -->
          <Button type="primary" class="ml14" @click="verify(1)"
            >审核通过</Button
          >
        </div>
      </div>
      <Tabs v-model="activeName">
        <TabPane label="基础信息" name="detail"></TabPane>
        <TabPane label="关联商品" name="product"></TabPane>
        <TabPane label="评论" name="comment"></TabPane>
      </Tabs>
      <Form
        ref="formValidate"
        :model="formValidate"
        :label-width="labelWidth"
        :label-position="labelPosition"
        @submit.native.prevent
      >
        <Row v-show="activeName === 'detail'">
          <Col span="24">
            <FormItem label="封面图：" prop="image" v-viewer>
              <div class="pictrueBox">
                <div class="pictrue">
                  <img v-lazy="formValidate.image" class="obj-contain" />
                </div>
              </div>
            </FormItem>
          </Col>
          <Col span="24" v-if="formValidate.content_type == 1">
            <FormItem label="图片：" prop="slider_image">
              <div class="pictrueBox">
                <div
                  class="pictrue"
                  v-for="(item, index) in formValidate.slider_image"
                  :key="index"
                  v-viewer
                >
                  <img v-lazy="item" class="obj-contain" />
                </div>
              </div>
            </FormItem>
          </Col>
          <Col span="24" v-else>
            <FormItem label="视频：" prop="video_url">
              <div class="iview-video-style" v-if="formValidate.video_url">
                <video
                  class="video-style"
                  :src="formValidate.video_url"
                  controls="controls"
                ></video>
              </div>
            </FormItem>
          </Col>
          <Col span="24">
            <FormItem label="内容标题：" prop="title">
              <Input
                v-model="formValidate.title"
                placeholder="请输入内容标题"
                v-width="'80%'"
                disabled
                readonly
              />
            </FormItem>
          </Col>
          <Col span="24">
            <FormItem label="内容作者：" prop="author">
              <Input
                v-model="formValidate.author"
                placeholder="请输入内容作者"
                v-width="'80%'"
                disabled
                readonly
              />
            </FormItem>
          </Col>
          <Col span="24">
            <FormItem label="内容话题：" prop="author">
              <div class="lable">
                <Tag v-for="(label, k) in formValidate.topic" :key="k">{{
                  label.name
                }}</Tag>
              </div>
            </FormItem>
          </Col>
          <Col span="24">
            <FormItem label="内容描述：" prop="content">
              <Input
                v-model="formValidate.content"
                type="textarea"
                :rows="3"
                placeholder="请输入视频简介"
                v-width="'80%'"
                disabled
                readonly
              />
            </FormItem>
          </Col>
          <Col span="24">
            <FormItem label="推荐星级：" prop="star">
              <Rate v-model="formValidate.star" disabled />
            </FormItem>
          </Col>
          <Col span="24" v-if="[-1, -2].includes(formValidate.is_verify)">
            <FormItem label="拒绝/下架原因：" prop="refusal">
              <Input
                v-model="formValidate.refusal"
                placeholder="请输入拒绝/下架原因"
                v-width="'80%'"
                disabled
                readonly
              />
            </FormItem>
          </Col>
        </Row>
        <div v-show="activeName === 'product'" class="margins">
          <Table
            :columns="columns"
            :data="tableData"
            highlight-row
            no-userFrom-text="暂无数据"
            no-filtered-userFrom-text="暂无筛选结果"
          >
            <template slot-scope="{ row }" slot="info">
              <div class="imgPic acea-row row-middle">
                <viewer>
                  <div class="pictrue"><img v-lazy="row.image" /></div>
                </viewer>
                <div class="info">
                  <Tooltip max-width="200" placement="bottom" transfer>
                    <span class="line2">{{ row.store_name }}{{ row.suk }}</span>
                    <p slot="content">{{ row.store_name }}{{ row.suk }}</p>
                  </Tooltip>
                </div>
              </div>
            </template>
          </Table>
        </div>
        <div v-show="activeName === 'comment'" class="margins">
          <div style="width: 930px">
            <Table
              :load-data="handleLoadData"
              update-show-children
              row-key="id"
              :columns="columns2"
              :data="tableList"
              highlight-row
              no-userFrom-text="暂无数据"
              no-filtered-userFrom-text="暂无筛选结果"
              style="width: 950px"
            >
              <template slot-scope="{ row, index }" slot="action">
                <a @click="reply(row)" v-if="row.is_verify == 1">回复</a>
                <Poptip
                  transfer
                  confirm
                  title="确定审核通过?"
                  cancel-text="拒绝"
                  @on-ok="verifyReply(row, 1)"
                  @on-cancel="verifyReply(row, -1)"
                  v-else-if="row.is_verify == 0"
                >
                  <a>审核</a>
                </Poptip>
                <!-- <a @click="verifyReply(row)" v-else>审核</a> -->
                <Divider v-if="row.is_verify == 1 || row.is_verify == 0" type="vertical" />
                <a @click="del(row, '删除社区评论', index)">删除</a>
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
          </div>
        </div>
      </Form>
    </Drawer>
  </div>
</template>
<script>
import { mapState } from 'vuex';
import {
  communityInfoApi,
  commentListApi,
  commentReplyApi,
  commentVerifyApi,
  communityVerifyApi,
} from '@/api/community';

export default {
  name: 'videoDetails',
  computed: {
    ...mapState('admin/layout', ['isMobile']),
    labelWidth() {
      return this.isMobile ? undefined : 110;
    },
    labelPosition() {
      return this.isMobile ? 'top' : 'right';
    },
  },
  data() {
    return {
      modals: false,
      activeName: 'detail',
      formValidate: {},
      tableFrom: {
        page: 1,
        limit: 15,
        community_id: 0,
      },
      total: 0,
      tableList: [],
      tableData: [],
      columns: [
        {
          title: 'ID',
          key: 'id',
          width: 80,
        },
        {
          title: '商品信息',
          slot: 'info',
          minWidth: 340,
        },
        {
          title: '售价',
          key: 'price',
          minWidth: 125,
        },
        {
          title: '库存',
          key: 'stock',
          minWidth: 125,
        },
      ],
      columns2: [
        {
          title: 'ID',
          key: 'id',
          width: 80,
          tree: true,
        },
        {
          title: '用户昵称',
          key: 'author',
          minWidth: 150,
        },
        {
          title: '评价内容',
          key: 'content',
          minWidth: 180,
        },
        {
          title: '回复数',
          key: 'comment_num',
          width: 125,
        },
        {
          title: '点赞数',
          key: 'like_num',
          minWidth: 125,
        },
        {
          title: '评论时间',
          key: 'add_time',
          minWidth: 180,
        },
        {
          title: '上级评论',
          key: 'comment_reply_content',
          minWidth: 200,
        },
        {
          title: '操作',
          slot: 'action',
          fixed: 'right',
          minWidth: 120,
        },
      ],
      replyForm: {
        page: 1,
        limit: 20,
        is_reply: 1,
        community_id: '',
      },
      formTurn: {
        refusal: '',
      },
      formVisible: false,
    };
  },
  created() {},
  methods: {
    // 用户发布内容审核
    verify(type) {
      let data = { is_verify: type };
      if (type == -1) {
        this.$set(data, 'refusal', this.formTurn.refusal);
      }
      communityVerifyApi(this.formValidate.id, data)
        .then((res) => {
          this.$Message.success(res.msg);
          this.modals = false;
          this.$parent.communityHeader();
          this.$parent.getList();
          if (type == -1) {
            this.$refs['poptip_' + this.formValidate.id].visible = false;
          }
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    popCancel(id) {
      this.$refs['poptip_' + this.formValidate.id].visible = false;
    },
    //内容详情
    getInfo(id) {
      communityInfoApi(id)
        .then((res) => {
          this.formValidate = res.data;
          this.tableData = res.data.product;
          this.tableFrom.community_id = id;
          this.commentAllList();
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    commentAllList() {
      commentListApi(this.replyForm)
        .then((res) => {
          res.data.list.map((item) => {
            if (item.comment_num > 0) {
              this.$set(item, 'children', []);
              this.$set(item, '_loading', false);
            }
          });
          let data = res.data;
          this.tableList = data.list;
          this.total = res.data.count;
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    handleLoadData(item, callback) {
      item._loading = true;
      commentListApi({ is_reply: 0, reply_id: item.id }).then((res) => {
        item._loading = true;
        callback(res.data.list);
      });
    },
    // 回复
    reply(row) {
      this.$modalForm(commentReplyApi(row.id)).then(() =>
        this.commentAllList()
      );
    },
    // 审核
    verifyReply(row, type) {
      commentVerifyApi(row.id, { is_verify: type })
        .then((res) => {
          this.$Message.success(res.msg);
          this.commentAllList();
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
        method: 'DELETE',
        ids: '',
      };
      this.$modalSure(delfromData)
        .then((res) => {
          this.$Message.success(res.msg);
          this.tableList.splice(num, 1);
          if (!this.tableList.length) {
            this.tableFrom.page =
              this.tableFrom.page == 1 ? 1 : this.tableFrom.page - 1;
          }
          this.commentAllList();
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    pageChange(index) {
      this.tableFrom.page = index;
      this.commentAllList();
    },
  },
};
</script>
<style scoped lang="less">
.order_box {
  box-sizing: border-box;

  .header {
    margin: 25px 24px;

    .pictrue {
      width: 60px;
      height: 60px;

      img {
        width: 100%;
        height: 100%;
        display: block;
      }
    }

    .text {
      margin-left: 12px;

      .name {
        font-size: 16px;
        font-weight: 500;
        color: rgba(0, 0, 0, 0.85);
      }

      .info {
        font-size: 13px;
        color: #606266;
        margin-top: 3px;

        .time {
          margin-left: 15px;
          font-size: 12px;
          color: #999;
        }
      }
    }
  }

  .pictrueBox {
    display: inline-block;

    .upLoad {
      width: 58px;
      height: 58px;
      line-height: 58px;
      border: 1px dotted rgba(0, 0, 0, 0.1);
      border-radius: 4px;
      background: rgba(0, 0, 0, 0.02);
      cursor: pointer;

      .input-display {
        display: none;
      }
    }

    .pictrue {
      width: 60px;
      height: 60px;
      border: 1px dotted rgba(0, 0, 0, 0.1);
      margin-right: 15px;
      margin-bottom: 10px;
      display: inline-block;
      position: relative;
      cursor: pointer;

      img {
        width: 100%;
        height: 100%;
      }

      .btndel {
        position: absolute;
        z-index: 1;
        width: 20px !important;
        height: 20px !important;
        left: 46px;
        top: -4px;
      }
    }
  }

  .iview-video-style {
    width: 40%;
    height: 180px;
    border-radius: 10px;
    background-color: #707070;
    margin-top: 10px;
    position: relative;
    overflow: hidden;

    .video-style {
      width: 100%;
      height: 100% !important;
      border-radius: 10px;
    }

    .iconv {
      color: #fff;
      line-height: 180px;
      width: 50px;
      height: 50px;
      display: inherit;
      font-size: 26px;
      position: absolute;
      top: -74px;
      left: 50%;
      margin-left: -25px;
    }

    .mark {
      position: absolute;
      width: 100%;
      height: 30px;
      top: 0;
      background-color: rgba(0, 0, 0, 0.5);
      text-align: center;
    }
  }

  .imgPic {
    .info {
      width: 60%;
      margin-left: 10px;
    }

    .pictrue {
      height: 36px;
      margin: 7px 3px 0 3px;

      img {
        height: 100%;
        display: block;
      }
    }
  }

  .margins {
    margin: 0 35px;
  }
}

.lable {
  width: 80%;
  border: 1px solid #dcdee2;
  border-radius: 4px;
  height: 32px;
  padding-left: 7px;

  .ivu-tag {
    margin-bottom: 5px;
  }
}

/deep/ .ivu-tabs-ink-bar {
  display: none;
}

/deep/ .ivu-tabs-bar {
  background: #f5f7fa;
  border-bottom: 0;
  margin-bottom: 0;
}

/deep/ .ivu-tabs-nav-wrap {
  margin-bottom: 0;
}

/deep/ .ivu-tabs-nav {
  height: 40px;
  line-height: 40px;
}

/deep/ .ivu-tabs-nav .ivu-tabs-tab-active {
  color: rgba(0, 0, 0, 0.85);
  font-weight: 400;
  background-color: #fff;

  &::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 2px;
    background-color: #1890ff;
  }
}

/deep/ .ivu-tabs-tabpane {
  padding: 15px;

  &:first-child {
    padding: 0 25px;
  }
}

/deep/ .ivu-tabs-nav .ivu-tabs-tab {
  padding: 7px 19px !important;
  margin-right: 0;
  line-height: 26px;
}

/deep/ .ivu-tabs-nav-container {
  font-size: 13px;
}
.obj-contain {
  object-fit: contain;
}
</style>
