<template>
  <!-- 社区内容 -->
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
          <FormItem label="内容话题：" label-for="store_name">
            <Select
              v-model="tableFrom.topic_id"
              clearable
              class="input-add"
              @on-change="tableSearchs"
            >
              <Option
                v-for="(item, index) in topicList"
                :value="item.id"
                :key="index"
                >{{ item.name }}
              </Option>
            </Select>
          </FormItem>
          <FormItem label="推荐星级：">
            <Select
              v-model="tableFrom.star"
              clearable
              class="input-add"
              @on-change="tableSearchs"
            >
              <Option
                v-for="(item, index) in starList"
                :value="item.id"
                :key="index"
                >{{ item.name }}
              </Option>
            </Select>
          </FormItem>
          <FormItem label="内容类型：">
            <Select
              v-model="tableFrom.content_type"
              clearable
              class="input-add"
              @on-change="tableSearchs"
            >
              <Option value="1">图文</Option>
              <Option value="2">视频</Option>
            </Select>
          </FormItem>
          <!-- <FormItem label="内容来源：">
            <Select
              v-model="tableFrom.type"
              clearable
              class="input-add"
              @on-change="tableSearchs"
            >
              <Option value="0">管理后台</Option>
              <Option value="1">门店发布</Option>
              <Option value="2">用户发布</Option>
            </Select>
          </FormItem> -->
          <FormItem label="内容搜索：" label-for="keyword">
            <Input
              placeholder=" 请输入内容标题/内容ID"
              v-model="tableFrom.keyword"
              class="input-add mr14"
            />
            <Button type="primary" @click="tableSearchs" class="mr14"
              >查询</Button
            >
            <Button @click="reset">重置</Button>
          </FormItem>
        </Form>
      </div>
    </Card>
    <Card :bordered="false" dis-hover class="ivu-mt">
      <div class="new_tab" v-if="headeNum.length">
        <Tabs v-model="tableFrom.is_verify" @on-click="tableSearchs">
          <TabPane
            :label="item.name + ' (' + item.count + ')'"
            :name="item.is_verify.toString()"
            v-for="(item, index) in headeNum"
            :key="index"
          />
        </Tabs>
      </div>
      <router-link :to="`${routePre}/marketing/community/addContent`">
        <Button type="primary">添加内容</Button>
      </router-link>
      <Table
        :columns="columns"
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
              <img v-lazy="row.image" class="obj-contain" />
            </div>
          </viewer>
        </template>
        <template slot-scope="{ row }" slot="content_type">
          <div>{{ row.content_type == 1 ? '图文' : '短视频' }}</div>
        </template>
        <template slot-scope="{ row }" slot="star">
          <div>
            <Rate disabled v-model="row.star" />
          </div>
        </template>
        <template slot-scope="{ row }" slot="topicName">
          <Tooltip
            theme="dark"
            max-width="300"
            :content="row.topicName"
            :delay="600"
            :transfer="true"
          >
            <div class="title line2">{{ row.topicName }}</div>
          </Tooltip>
        </template>
        <template slot-scope="{ row }" slot="status">
          <i-switch
            v-model="row.status"
            :value="row.status"
            :true-value="1"
            :false-value="0"
            @on-change="onchangeStatus(row)"
            size="large"
          >
            <span slot="open">开启</span>
            <span slot="close">关闭</span>
          </i-switch>
        </template>
        <template slot-scope="{ row, index }" slot="action">
          <!-- <a @click="star(row)" v-show="row.is_verify == 1">推荐指数</a> -->
          <!-- <Divider type="vertical" v-show="row.is_verify == 1" /> -->
          <!-- <a @click="verify(row, 1)" v-show="row.is_verify == 0">通过</a> -->
          <!-- <Divider type="vertical" v-show="row.is_verify == 0" /> -->
          <a @click="info(row)" v-if="row.is_verify != 0">详情</a>
          <Divider type="vertical" />
          <a @click="edit(row)">编辑</a>
          <!-- <Poptip
            :ref="'poptip_' + row.id"
            transfer
            placement="left"
            width="400"
          >
            <a v-if="row.is_verify == 0">拒绝</a>
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
                  <Button @click="popCancel(row.id)">取消</Button>
                  <Button type="primary" class="ml14" @click="verify(row, -1)"
                    >确定</Button
                  >
                </div>
              </div>
            </template>
          </Poptip> -->
          <Divider type="vertical" />
          <template v-if="['0', '1'].includes(tableFrom.is_verify)">
            <Dropdown
              @on-click="changeMenu(row, $event, index)"
              :transfer="true"
            >
              <a href="javascript:void(0)"
                >更多
                <Icon type="ios-arrow-down"></Icon>
              </a>
              <DropdownMenu slot="list">
                <!-- <DropdownItem name="6" v-if="row.is_verify == 0"
                  >详情</DropdownItem
                > -->
                <!-- 后台添加显示 -->
                <!-- <DropdownItem name="1" v-if="[0, 1].includes(row.is_verify)"
                  >编辑</DropdownItem
                > -->
                <DropdownItem name="2" v-if="[0, 1].includes(row.is_verify)"
                  >添加评论</DropdownItem
                >
                <!-- 用户添加显示 -->
                <!-- <DropdownItem name="3" v-if="row.is_verify == 1"
                  >强制下架</DropdownItem
                > -->
                <!-- <DropdownItem name="5" v-if="row.is_verify == 0"
                  >推荐指数</DropdownItem
                > -->
                <!-- 都有 -->
                <DropdownItem name="4">删除</DropdownItem>
              </DropdownMenu>
            </Dropdown>
          </template>
          <!-- <a v-else @click="del(row, '删除内容', index)">删除</a> -->
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
    <contentInfo ref="content"></contentInfo>
  </div>
</template>

<script>
import Setting from '@/setting';
import { mapState } from 'vuex';
import contentInfo from './components/contentInfo';
import {
  allTopicApi,
  communityHeaderApi,
  communityListApi,
  communityStarApi,
  communityStatusApi,
  communityDownApi,
  communityVerifyApi,
  communityCommentApi,
} from '@/api/community';

export default {
  name: 'content',
  components: {
    contentInfo,
  },
  data() {
    return {
      routePre: Setting.routePre,
      headeNum: [],
      topicList: [],
      starList: [
        { id: 1, name: '一星' },
        { id: 2, name: '二星' },
        { id: 3, name: '三星' },
        { id: 4, name: '四星' },
        { id: 5, name: '五星' },
      ],
      tableFrom: {
        page: 1,
        limit: 15,
        topic_id: '',
        star: '',
        content_type: '',
        keyword: '',
        is_verify: '1',
        type: '',
      },
      loading: false,
      columns: [
        {
          title: 'ID',
          key: 'id',
          width: 80,
        },
        {
          title: '封面',
          slot: 'image',
          width: 100,
        },
        {
          title: '内容标题',
          key: 'title',
          minWidth: 150,
        },
        {
          title: '内容作者',
          key: 'author',
          minWidth: 150,
        },
        {
          title: '话题',
          slot: 'topicName',
          minWidth: 150,
        },
        {
          title: '内容类型',
          slot: 'content_type',
          minWidth: 100,
        },
        // {
        //   title: '推荐星级',
        //   slot: 'star',
        //   minWidth: 200,
        // },
        {
          title: '浏览量',
          key: 'play_num',
          minWidth: 100,
        },
        {
          title: '点赞数',
          key: 'like_num',
          minWidth: 100,
        },
        {
          title: '评论数',
          key: 'comment_num',
          minWidth: 100,
        },
        {
          title: '分享数',
          key: 'share_num',
          minWidth: 100,
        },
        {
          title: '关联商品',
          key: 'product_sum',
          minWidth: 100,
        },
        {
          title: '是否展示',
          slot: 'status',
          minWidth: 100,
        },
        {
          title: '发布时间',
          key: 'add_time',
          minWidth: 150,
        },
        {
          title: '操作',
          slot: 'action',
          fixed: 'right',
          align: 'center',
          width: 200,
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
      formTurn: {
        refusal: '',
      },
      formVisible: false,
    };
  },
  computed: {
    ...mapState('store/layout', ['isMobile']),
    labelWidth() {
      return this.isMobile ? undefined : 80;
    },
    labelPosition() {
      return this.isMobile ? 'top' : 'right';
    },
  },
  created() {
    this.allTopicList();
    this.communityHeader();
    this.getList();
  },
  methods: {
    // 用户发布内容审核
    verify(row, type) {
      let data = { is_verify: type };
      if (type == -1) {
        this.$set(data, 'refusal', this.formTurn.refusal);
      }
      communityVerifyApi(row.id, data)
        .then((res) => {
          this.$Message.success(res.msg);
          this.communityHeader();
          this.getList();
          if (type == -1) {
            this.$refs['poptip_' + row.id].visible = false;
          }
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    popCancel(id) {
      this.$refs['poptip_' + id].visible = false;
    },
    // 话题列表；
    allTopicList() {
      allTopicApi()
        .then((res) => {
          this.topicList = res.data;
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    // 社区内容顶部header；
    communityHeader() {
      communityHeaderApi(this.tableFrom)
        .then((res) => {
          this.headeNum = res.data;
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    // 列表
    getList() {
      this.loading = true;
      communityListApi(this.tableFrom)
        .then((res) => {
          let data = res.data;
          this.tableList = data.list;
          this.total = res.data.count;
          this.loading = false;
        })
        .catch((err) => {
          this.loading = false;
          this.$Message.error(err.msg);
        });
    },
    // 表格搜索
    tableSearchs() {
      this.tableFrom.page = 1;
      this.communityHeader();
      this.getList();
    },
    reset() {
      this.tableFrom.topic_id = '';
      this.tableFrom.star = '';
      this.tableFrom.content_type = '';
      this.tableFrom.type = '';
      this.tableFrom.keyword = '';
      this.tableFrom.is_verify = '1';
      this.tableFrom.page = 1;
      this.communityHeader();
      this.getList();
    },
    // 详情
    info(row) {
      this.$refs.content.modals = true;
      this.$refs.content.getInfo(row.id);
      this.$refs.content.activeName = 'detail';
      this.$refs.content.replyForm.community_id = row.id;
    },
    // 编辑
    edit(row) {
      this.$router.push({
        path: `${this.routePre}/marketing/community/addContent/${row.id}`,
      });
    },
    // 推荐指数
    star(row) {
      this.$modalForm(communityStarApi(row.id)).then(() => this.getList());
    },
    changeMenu(row, name, index) {
      switch (name) {
        // case '1':
        //   this.$router.push({
        //     path: '/admin/content/community/addContent/' + row.id,
        //   });
        //   break;
        case '2':
          this.$modalForm(communityCommentApi(row.id)).then(() =>
            this.getList()
          );
          break;
        // case '3':
        //   this.$modalForm(communityDownApi(row.id)).then(() => {
        //     this.communityHeader();
        //     this.getList();
        //   });
        //   break;
        case '4':
          this.del(row, '删除内容', index);
          break;
        // case '5':
        //   this.star(row);
        //   break;
        // case '6':
        //   this.info(row);
        //   break;
      }
    },
    // 删除
    del(row, tit, num) {
      let delfromData = {
        title: tit,
        num: num,
        url: `/community/community/del/${row.id}`,
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
          this.communityHeader();
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
    // 修改是否展示
    onchangeStatus(row) {
      let data = {
        id: row.id,
        status: row.status,
      };
      communityStatusApi(data)
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

.obj-contain {
  object-fit: contain;
}
</style>
