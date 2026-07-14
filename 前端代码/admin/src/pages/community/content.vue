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
          <FormItem label="内容来源：">
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
          </FormItem>
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
      <router-link :to="`${roterPre}/community/addContent`">
        <Button type="primary" v-auth="['admin-community-addcontent']"
          >添加内容</Button
        >
      </router-link>
	  <Tooltip
	    content="本页至少选中一项"
	    :disabled="!!checkUidList.length && isAll == 0"
	  >
	    <Button
	      class="bnt ml15"
	      :disabled="!checkUidList.length && isAll == 0"
		  v-if="tableFrom.is_verify == 0"
	      @click="batchVerify"
	      >批量审核</Button
	    >
	  </Tooltip>
	  <vxe-table
	      ref="xTable"
	      class="mt25"
	      :loading="loading"
	      row-id="id"
	      :checkbox-config="{reserve: true}"
	      @checkbox-all="checkboxAll"
	      @checkbox-change="checkboxItem"
	      :data="tableList">
	    <vxe-column type="" width="0" v-if="tableFrom.is_verify == 0"></vxe-column>
	    <vxe-column type="checkbox" width="100" v-if="tableFrom.is_verify == 0">
	      <template #header>
	        <div>
	          <Dropdown transfer @on-click="allPages">
	            <a href="javascript:void(0)" class="acea-row row-middle">
	              <span>全选({{isAll==1?(total-checkUidList.length):checkUidList.length}})</span>
	              <Icon type="ios-arrow-down"></Icon>
	            </a>
	            <template #list>
	              <DropdownMenu>
	                <DropdownItem name="0">当前页</DropdownItem>
	                <DropdownItem name="1">所有页</DropdownItem>
	              </DropdownMenu>
	            </template>
	          </Dropdown>
	        </div>
	      </template>
	    </vxe-column>
		<vxe-column field="id" title="ID" width="80"></vxe-column>
	    <vxe-column field="image" title="封面" min-width="120">
			<template v-slot="{ row }">
				<viewer>
				  <div class="tabBox_img">
				    <img v-lazy="row.image" class="obj-contain" />
				  </div>
				</viewer>
			</template>
	    </vxe-column>
		<vxe-column field="title" title="内容标题" width="150"></vxe-column>
		<vxe-column field="author" title="内容来源" width="150"></vxe-column>
		<vxe-column field="topicName" title="话题" width="150">
			<template v-slot="{ row }">
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
		</vxe-column>
		<vxe-column field="content_type" title="内容类型" width="100">
			<template v-slot="{ row }">
				<div>{{ row.content_type == 1 ? "图文" : "短视频" }}</div>
			</template>
		</vxe-column>
		<vxe-column field="star" title="推荐星级" width="200">
			<template v-slot="{ row }">
				<div>
				  <Rate disabled v-model="row.star" />
				</div>
			</template>
		</vxe-column>
		<vxe-column field="play_num" title="浏览量" width="100"></vxe-column>
		<vxe-column field="like_num" title="点赞数" width="100"></vxe-column>
		<vxe-column field="comment_num" title="评论数" width="100"></vxe-column>
		<vxe-column field="share_num" title="分享数" width="100"></vxe-column>
		<vxe-column field="product_sum" title="关联商品" width="100"></vxe-column>
		<vxe-column field="status" title="是否展示" width="100">
			<template v-slot="{ row }">
				<i-switch
				  :disabled="isAgentAdmin"
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
		</vxe-column>
		<vxe-column field="add_time" title="发布时间" width="150"></vxe-column>
	    <vxe-column field="action" title="操作" width="200" fixed="right">
	      <template #default="{ row, rowIndex }">
			  <a
			    v-auth="['admin-community-content-star']"
			    @click="star(row)"
			    v-show="row.is_verify == 1"
			    >推荐指数</a
			  >
			  <Divider
			    v-auth="['admin-community-content-star']"
			    type="vertical"
			    v-show="row.is_verify == 1"
			  />
			  <a
			    v-auth="['admin-community-content-verify_agree']"
			    @click="verify(row, 1)"
			    v-show="row.is_verify == 0"
			    >通过</a
			  >
			  <Divider
			    v-auth="['admin-community-content-verify_agree']"
			    type="vertical"
			    v-show="row.is_verify == 0"
			  />
			  <a @click="info(row)" v-if="row.is_verify != 0">详情</a>
			  <Poptip
			    v-auth="['admin-community-content-verify_refuse']"
			    :ref="'poptip_' + row.id"
			    transfer
			    placement="left"
			    width="400"
			    @on-popper-show="onPopperShow"
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
			  </Poptip>
			  <Divider
			    type="vertical"
			    v-auth="['admin-community-content-verify_refuse']"
			  />
			  <template v-if="['0', '1'].includes(tableFrom.is_verify)">
			    <Dropdown
			      v-auth="[
			        'admin-community-editcontent',
			        'admin-community-content-add_reply',
			        'admin-community-content-verify_remove',
			        'admin-community-content-star',
			        'admin-community-content-delete',
			        '',
			      ]"
			      @on-click="changeMenu(row, $event, rowIndex)"
			      :transfer="true"
			    >
			      <a href="javascript:void(0)">更多<Icon type="ios-arrow-down"></Icon></a>
			      <DropdownMenu slot="list">
			        <DropdownItem name="6" v-if="row.is_verify == 0"
			          >详情</DropdownItem
			        >
			        <!-- 后台添加显示 -->
			        <DropdownItem
			          v-auth="['admin-community-editcontent']"
			          name="1"
			          v-if="[0, 1].includes(row.is_verify)"
			          >编辑</DropdownItem
			        >
			        <DropdownItem
			          v-auth="['admin-community-content-add_reply']"
			          name="2"
			          v-if="[0, 1].includes(row.is_verify)"
			          >添加评论</DropdownItem
			        >
			        <!-- 用户添加显示 -->
			        <DropdownItem
			          v-auth="['admin-community-content-verify_remove']"
			          name="3"
			          v-if="row.is_verify == 1"
			          >强制下架</DropdownItem
			        >
			        <DropdownItem
			          v-auth="['admin-community-content-star']"
			          name="5"
			          v-if="row.is_verify == 0"
			          >推荐指数</DropdownItem
			        >
			        <!-- 都有 -->
			        <DropdownItem
			          v-auth="['admin-community-content-delete']"
			          name="4"
			          >删除</DropdownItem
			        >
			      </DropdownMenu>
			    </Dropdown>
			  </template>
			  <a
			    v-auth="['admin-community-content-delete']"
			    v-else
			    @click="del(row, '删除内容', rowIndex)"
			    >删除</a
			  >
		  </template>
	    </vxe-column>
	  </vxe-table>
      <div class="acea-row row-right page">
        <Page
            :total="total"
            :current="tableFrom.page"
            show-elevator
            show-total
            @on-change="pageChange"
            :page-size="tableFrom.limit"
            @on-page-size-change="pageChange"
            show-sizer
        />
      </div>
    </Card>
	<verifyForm ref="verifyForm" @submitSuccess='submitSuccess'></verifyForm>
    <contentInfo ref="content"></contentInfo>
  </div>
</template>

<script>
import Setting from "@/setting";
import { mapState } from "vuex";
import verifyForm from "./components/verifyForm";
import contentInfo from "./components/contentInfo";
import {
  allTopicApi,
  communityHeaderApi,
  communityListApi,
  communityStarApi,
  communityStatusApi,
  communityDownApi,
  communityVerifyApi,
  communityCommentApi,
} from "@/api/community";

export default {
  name: "content",
  components: {
	verifyForm,
    contentInfo,
  },
  data() {
    return {
      roterPre: Setting.roterPre,
      headeNum: [],
      topicList: [],
      starList: [
        { id: 1, name: "一星" },
        { id: 2, name: "二星" },
        { id: 3, name: "三星" },
        { id: 4, name: "四星" },
        { id: 5, name: "五星" },
      ],
      tableFrom: {
        page: 1,
        limit: 15,
        topic_id: "",
        star: "",
        content_type: "",
        keyword: "",
        is_verify: "1",
        type: "",
      },
      loading: false,
      columns: [
        {
          title: "ID",
          key: "id",
          width: 80,
        },
        {
          title: "封面",
          slot: "image",
          width: 100,
        },
        {
          title: "内容标题",
          key: "title",
          minWidth: 150,
        },
        {
          title: "内容来源",
          key: "author",
          minWidth: 150,
        },
        {
          title: "话题",
          slot: "topicName",
          minWidth: 150,
        },
        {
          title: "内容类型",
          slot: "content_type",
          minWidth: 100,
        },
        {
          title: "推荐星级",
          slot: "star",
          minWidth: 200,
        },
        {
          title: "浏览量",
          key: "play_num",
          minWidth: 100,
        },
        {
          title: "点赞数",
          key: "like_num",
          minWidth: 100,
        },
        {
          title: "评论数",
          key: "comment_num",
          minWidth: 100,
        },
        {
          title: "分享数",
          key: "share_num",
          minWidth: 100,
        },
        {
          title: "关联商品",
          key: "product_sum",
          minWidth: 100,
        },
        {
          title: "是否展示",
          slot: "status",
          minWidth: 100,
        },
        {
          title: "发布时间",
          key: "add_time",
          minWidth: 150,
        },
        {
          title: "操作",
          slot: "action",
          fixed: "right",
          align: "center",
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
        refusal: "",
      },
      formVisible: false,
      isAgentAdmin: this.__isAgentPath(),
	  isAll: 0,
	  isCheckBox: false,
	  checkUidList: []
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
	this.tableFrom.is_verify = this.$route.query.is_verify || "1";
    this.allTopicList();
    this.communityHeader();
    this.getList();
  },
  methods: {
	batchVerify(){
		this.$refs.verifyForm.verifyFormShow = true;
		this.$refs.verifyForm.verifyForm.where = this.tableFrom;
		this.$refs.verifyForm.verifyForm.all = this.isAll;
		this.$refs.verifyForm.verifyForm.ids = this.checkUidList.join(',');
	},
	submitSuccess(){
		this.getList();
		this.communityHeader();
		this.allReset();
	},
	allReset() {
	  this.isAll = 0;
	  this.isCheckBox = false;
	  this.$refs.xTable.setAllCheckboxRow(false);
	  this.checkUidList = [];
	},
	checkboxItem(e) {
	  let id = parseInt(e.row.id);
	  let index = this.checkUidList.indexOf(id);
	  if (index !== -1) {
	    this.checkUidList = this.checkUidList.filter((item) => item !== id);
	  } else {
	    this.checkUidList.push(id);
	  }
	},
	checkboxAll() {
	  // 获取选中当前值
	  let obj2 = this.$refs.xTable.getCheckboxRecords(true);
	  // 获取之前选中值
	  let obj = this.$refs.xTable.getCheckboxReserveRecords(true);
	  if (
	    this.isAll == 0 &&
	    this.checkUidList.length <= obj.length &&
	    !this.isCheckBox
	  ) {
	    obj = [];
	  }
	  obj = obj.concat(obj2);
	  let ids = [];
	  obj.forEach((item) => {
	    ids.push(parseInt(item.id));
	  });
	  this.checkUidList = ids;
	  if (!obj2.length) {
	    this.isCheckBox = false;
	  }
	},
	allPages(e) {
	  this.isAll = e;
	  if (e == 0) {
	    this.$refs.xTable.toggleAllCheckboxRow();
	  } else {
	    if (!this.isCheckBox) {
	      this.$refs.xTable.setAllCheckboxRow(true);
	      this.isCheckBox = true;
	      this.isAll = 1;
	    } else {
	      this.$refs.xTable.setAllCheckboxRow(false);
	      this.isCheckBox = false;
	      this.isAll = 0;
	    }
	    this.checkUidList = [];
	  }
	},
    // 用户发布内容审核
    verify(row, type) {
      let data = { is_verify: type };
      if (type == -1) {
        this.$set(data, "refusal", this.formTurn.refusal);
      }
      communityVerifyApi(row.id, data)
        .then((res) => {
          this.$Message.success(res.msg);
          this.communityHeader();
          this.getList();
		  this.allReset();
          if (type == -1) {
            this.$refs["poptip_" + row.id].visible = false;
          }
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    popCancel(id) {
      this.$refs["poptip_" + id].visible = false;
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
		  this.allReset();
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
		  this.$nextTick(function () {
		    if (this.isAll == 1) {
		      if (this.isCheckBox) {
		        let flag = false;
		        data.list.forEach((item) => {
		          this.checkUidList.forEach((j) => {
		            if (item.id == j) {
		              flag = true;
		            }
		          });
		        });
		        if (!flag) {
		          this.$refs.xTable.setAllCheckboxRow(true);
		        }
		      } else {
		        this.$refs.xTable.setAllCheckboxRow(false);
		      }
		    } else {
		      let obj = this.$refs.xTable.getCheckboxReserveRecords(true);
		      if (
		        !this.checkUidList.length ||
		        this.checkUidList.length <= obj.length
		      ) {
		        this.$refs.xTable.setAllCheckboxRow(false);
		      }
		    }
		  });
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
	  this.allReset();
    },
    reset() {
      this.tableFrom.topic_id = "";
      this.tableFrom.star = "";
      this.tableFrom.content_type = "";
      this.tableFrom.type = "";
      this.tableFrom.keyword = "";
      this.tableFrom.is_verify = "1";
      this.tableFrom.page = 1;
      this.communityHeader();
      this.getList();
	  this.allReset();
    },
    // 详情
    info(row) {
      this.$refs.content.modals = true;
      this.$refs.content.getInfo(row.id);
      this.$refs.content.activeName = "detail";
      this.$refs.content.replyForm.community_id = row.id;
    },
    // 推荐指数
    star(row) {
      this.$modalForm(communityStarApi(row.id)).then(() => {
		  this.getList()
		  this.allReset();
	  });
    },
    changeMenu(row, name, index) {
      switch (name) {
        case "1":
          this.$router.push({
            path: `${this.roterPre}/community/addContent/${row.id}`,
          });
          break;
        case "2":
          this.$modalForm(communityCommentApi(row.id)).then(() =>{
			this.getList()
			this.allReset();
		  });
          break;
        case "3":
          this.$modalForm(communityDownApi(row.id)).then(() => {
            this.communityHeader();
            this.getList();
			this.allReset();
          });
          break;
        case "4":
          this.del(row, "删除内容", index);
          break;
        case "5":
          this.star(row);
          break;
        case "6":
          this.info(row);
          break;
      }
    },
    // 删除
    del(row, tit, num) {
      let delfromData = {
        title: tit,
        num: num,
        url: `/community/community/del/${row.id}`,
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
		  this.allReset();
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    onPopperShow() {
      this.formTurn.refusal = "";
    },
  },
};
</script>

<style scoped lang="stylus">
/deep/.vxe-table--render-default{
	font-size: 12px;
}
.line2 {
  max-height: 45px;
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
