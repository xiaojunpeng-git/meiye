<template>
  <!-- 装修-店铺装修 -->
  <div>
    <Card :bordered="false" dis-hover :padding="0">
      <div class="px-20">
        <div class="new_tab">
          <Tabs v-model="currentTab">
            <TabPane label="店铺首页" name="0" />
            <TabPane label="专题页" name="1" />
          </Tabs>
        </div>
        <div class="acea-row" v-show="currentTab == 0">
          <div class="iframe-box">
            <iframe
              class="iframe"
              :src="BaseURL + 'pages/index/index'"
              frameborder="0"
              ref="iframe"
            ></iframe>
            <div class="mask"></div>
          </div>
          <div class="right-box">
            <Button type="primary" @click="indexDiy">首页装修</Button>
            <div class="mt-20">
              <div class="card-cell flex-between-center">
                <div>
                  <div class="title">微信小程序</div>
                  <div class="desc">扫描右侧二维码查看</div>
                </div>
                <img :src="qrcodeImg" v-show="qrcodeImg" />
                <div style="width:98px;height: 98px;" v-show="!qrcodeImg"></div>
              </div>
              <div class="card-cell flex-between-center mt-20">
                <div>
                  <div class="title">微信公众号</div>
                  <div class="desc">扫描右侧二维码查看</div>
                </div>
                <div ref="qrCodeUrl"></div>
              </div>
            </div>
          </div>
        </div>
        <div v-show="currentTab == 1">
          <div>
            <Button type="primary" @click="add">添加模板</Button>
          </div>
          <div>
            <Table
              :columns="columns1"
              :data="list"
              ref="table"
              class="mt25"
              :loading="loading"
              highlight-row
              no-userFrom-text="暂无数据"
              no-filtered-userFrom-text="暂无筛选结果"
            >
              <template slot-scope="{ row }" slot="name">
                <div class="flex-y-center">
                  <span class="pr-10">{{ row.name }} </span>
                  <span class="table-tag" v-show="row.status == 1">首页</span>
                </div>
              </template>
              <template slot-scope="{ row, index }" slot="action">
                <a @click="edit(row)" v-if="row.status || row.is_diy">设计</a>
                <div
                  v-if="row.id != 1 && row.is_diy"
                  style="display: inline-block"
                >
                  <Divider type="vertical" v-if="row.status || row.is_diy" />
                  <a @click="del(row, index)">删除</a>
                </div>
                <div style="display: inline-block" v-if="row.status != 1">
                  <Divider type="vertical" v-if="row.status || row.is_diy" />
                  <a @click="setStatus(row, index)">设为首页</a>
                </div>
                <div
                  v-if="row.status || row.is_diy"
                  style="display: inline-block"
                >
                  <Divider type="vertical" />
                  <a class="copy-data" @click="preview(row)">预览</a>
                </div>
                <div style="display: inline-block" v-if="!row.is_diy">
                  <Divider type="vertical" />
                  <a @click="recovery(row, index)">恢复初始设置</a>
                  <Divider type="vertical" />
                  <a @click="del(row, index)">删除</a>
                </div>
              </template>
            </Table>
            <div class="acea-row row-right page">
              <Page
                :total="total"
                :current="diyFrom.page"
                show-elevator
                show-total
                @on-change="pageChange"
                :page-size="diyFrom.limit"
              />
            </div>
          </div>
        </div>
        <div class="h-100"></div>
      </div>
    </Card>
    <Modal v-model="modal" title="预览" footer-hide>
      <div>
        <div v-viewer class="acea-row row-around code">
          <div class="acea-row row-column-around row-between-wrapper">
            <div class="qrCodeUrlProview" ref="qrCodeUrlProview"></div>
            <span class="mt10">公众号二维码</span>
          </div>
          <div class="acea-row row-column-around row-between-wrapper">
            <div class="QRpic">
              <img v-lazy="qrcodeImg" style="width: 98px;height: 98px;" />
            </div>
            <span class="mt10">小程序二维码</span>
          </div>
        </div>
      </div>
    </Modal>
  </div>
</template>

<script>
import Setting from "@/setting";
import ClipboardJS from "clipboard";
import {
  diyList,
  diyDel,
  setStatus,
  recovery,
  getRoutineCode,
} from "@/api/diy";
import { mapState } from "vuex";
import QRCode from "qrcodejs2";
import goodClass from "./goodClass";
import newGoods from "./newGoods";
import users from "./users";

export default {
  name: "devise_list",
  computed: {
    ...mapState("admin/layout", ["menuCollapse", "isMobile"]),
  },
  components: {
    goodClass,
    newGoods,
    users,
  },
  data() {
    return {
	  roterPre: Setting.roterPre,
      grid: {
        sm: 10,
        md: 12,
        lg: 19,
      },
      loading: false,
      currentTab: '0',
      columns1: [
        {
          title: "ID",
          key: "id",
          width: 80,
        },
        {
          title: "模板名称",
          slot: "name",
          minWidth: 100,
        },
        {
          title: "添加时间",
          key: "add_time",
          minWidth: 100,
        },
        {
          title: "更新时间",
          key: "update_time",
          minWidth: 100,
        },
        {
          title: "操作",
          slot: "action",
          width: 200,
        },
      ],
      list: [],
      imgUrl: "",
      modal: false,
      BaseURL: Setting.apiBaseURL.replace(/adminapi/, ""),
      cardShow: 0,
      loadingExist: false,
      isDiy: 1,
      qrcodeImg: "",
      diyFrom: {
        type: 1,
        page: 1,
        limit: 10,
      },
      total: 0,
    };
  },
  mounted() {
    if(this.$route.query.status == 0){
      this.currentTab = '1';
    }
    this.getList();
  },
  methods: {
    getChildData(e) {
      this.loadingExist = e;
    },
    submit() {
      switch (this.cardShow) {
        case 1:
          this.$refs.category.onSubmit();
          break;
        case 2:
          this.$refs.newGoods.onSubmit();
          break;
        case 3:
          this.$refs.users.onSubmit();
          break;
      }
    },
    reast() {
      if (this.cardShow == 1) {
        this.$refs.category.onSubmit(1);
      }
    },
    bindMenuItem(index) {
      this.cardShow = index;
    },
    onCopy() {
      this.$Message.success("复制预览链接成功");
    },
    onError() {
      this.$Message.error("复制预览链接失败");
    },
    //生成二维码
    creatQrCode(id, status) {
      this.$refs.qrCodeUrl.innerHTML = "";
      let url = "";
      if (status) {
        url = `${this.BaseURL}pages/index/index`;
      } else {
        url = `${this.BaseURL}pages/annex/special/index?id=${id}`;
      }
      var qrcode = new QRCode(this.$refs.qrCodeUrl, {
        text: url, // 需要转换为二维码的内容
        width: 98,
        height: 98,
        colorDark: "#000000",
        colorLight: "#ffffff",
        correctLevel: QRCode.CorrectLevel.H,
      });
    },
    //小程序二维码
    routineCode(id) {
      getRoutineCode(id)
        .then((res) => {
          this.qrcodeImg = res.data.image;
        })
        .catch((err) => {
          this.$Message.error(err);
        });
    },
    preview(row) {
      this.modal = true;
      this.routineCode(row.id);
      this.$refs.qrCodeUrlProview.innerHTML = "";
      let url =  `${this.BaseURL}pages/annex/special/index?id=${row.id}`;
      var qrcode = new QRCode(this.$refs.qrCodeUrlProview, {
        text: url, // 需要转换为二维码的内容
        width: 98,
        height: 98,
        colorDark: "#000000",
        colorLight: "#ffffff",
        correctLevel: QRCode.CorrectLevel.H,
      });
    },
    // 获取列表
    getList() {
      let storage = window.localStorage;
      this.imgUrl = storage.getItem("imgUrl");
      let that = this;
      this.loading = true;
      diyList(this.diyFrom).then((res) => {
        this.loading = false;
        let data = res.data;
        this.list = data.list;
        this.total = data.count;
        // this.preview(data.list[0]);
        this.creatQrCode(data.list[0].id, data.list[0].status);
        this.routineCode(data.list[0].id);
        data.list.forEach(function(e) {
          if (e.status == 1) {
            that.isDiy = e.is_diy;
            let imgUrl = `${that.BaseURL}pages/index/index`;
            storage.setItem("imgUrl", imgUrl);
            that.imgUrl = imgUrl;
          }
        });
      });
    },
    pageChange(status) {
      this.diyFrom.page = status;
      this.getList();
    },
    // 编辑
    edit(row) {
      if (row.is_diy) {
        this.$router.push({
          path: this.roterPre + '/pages/diy',
          query: { id: row.id, name: row.name, status: row.status },
        });
      } else {
        let storage = window.localStorage;
        storage.setItem("pageName", row.template_name);
        this.$store.dispatch("admin/user/getPageName");
		this.$router.push({
		  path: this.roterPre + '/setting/pages/template',
		  query: { id: row.id, name: row.name },
		})
      }
    },
    indexDiy() {
      this.$router.push({
        path: this.roterPre + '/pages/diy',
        query: { id: this.list[0].id, name: this.list[0].template_name },
      });
    },
    // 添加
	add() {
	  this.$router.push({
	    path: this.roterPre + '/pages/diy',
	    query: { id: 0, name: '首页', type: 1 },
	  })
	},
    // 删除
    del(row) {
      let delfromData = {
        title: "删除",
        num: 2000,
        url: "diy/del/" + row.id,
        method: "DELETE",
        data: {
          type: 1,
        },
      };
      this.$modalSure(delfromData)
        .then((res) => {
          this.getList();
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    // 使用模板
    async setStatus(row) {
      this.$Modal.confirm({
        title: "提示",
        content: "<p>是否把该模板设为首页</p>",
        onOk: () => {
          setStatus(row.id, {
            type: 1,
          })
            .then((res) => {
              let that = this;
              if (res.data.status) {
                this.$Message.success(res.data.msg);
                this.$Modal.remove();
                this.getList();
              } else {
                setTimeout((e) => {
                  that.$Modal.confirm({
                    title: "提示",
                    content: "<p>尚未安装模板，请安装后再试！</p>",
                    loading: false
                  });
                }, 500);
              }
            })
            .catch((res) => {
              this.$Modal.remove();
              this.$Message.error(res.msg);
            });
        },
      });
    },
    recovery(row) {
      recovery(row.id).then((res) => {
        this.$Message.success(res.msg);
        this.getList();
      });
    },
  },
};
</script>

<style scoped lang="less">
.px-20{
  padding: 0 20px;
}
/deep/ .ivu-tabs-nav .ivu-tabs-tab {
  padding: 16px !important;
}

.right-box {
  border: 1px solid #eeeeee;
  background-color: #ffffff;
  padding: 20px;
  height: 420px;
  border-radius: 10px;
  position: relative;
  
  &:before {
		content: '';
		position: absolute;
		top: 30px;
		left: -9px;
		width: 18px;
		height: 18px;
		border-left: 1px solid #eeeeee;
    border-bottom: 1px solid #eeeeee;
		background-color: #FFFFFF;
		box-sizing: border-box;
		transform: rotate(45deg);
	}
}
.card-cell {
  width: 588px;
  height: 154px;
  padding: 28px 28px 28px 16px;
  background: #f9f9f9;
  border-radius: 4px;
  .title {
    font-size: 18px;
    color: rgba(0, 0, 0, 0.85);
    font-weight: 500;
    line-height: 25px;
  }
  .desc {
    font-size: 13px;
    color: #999999;
    line-height: 18px;
  }
  img {
    width: 98px;
    height: 98px;
  }
}

.table-tag {
  display: inline-block;
  width: 36px;
  height: 20px;
  border-radius: 2px;
  border: 1px solid #2d8cf0;
  font-size: 12px;
  color: #2d8cf0;
  text-align: center;
  line-height: 18px;
}

.tableDiv {
  width: calc(100% - 350px);
}

.ivu-menu-light.ivu-menu-vertical .ivu-menu-item-active:not(.ivu-menu-submenu) {
  background: #eff6fe !important;
}

.ivu-mt {
  background-color: #fff;
}

.bnt {
  width: 80px !important;
}

.iframe-box {
  width: 375px;
  height: 650px;
  margin-right: 20px;
  border-radius: 12px;
  position: relative;
  border: 1px solid #eeeeee;
  .iframe {
    width: 100%;
    height: 100%;
    border-radius: 12px;
  }
}

.mask {
  position: absolute;
  left: 0;
  width: 100%;
  top: 0;
  height: 100%;
  background-color: rgba(0, 0, 0, 0);
}

/deep/.ivu-menu-vertical .ivu-menu-item,
.ivu-menu-vertical .ivu-menu-submenu-title {
  text-align: center;
}

.fixed-card {
  position: fixed;
  right: 0;
  bottom: 0;
  left: 200px;
  z-index: 99;
  box-shadow: 0 -1px 2px rgb(240, 240, 240);
}

/deep/ .ivu-menu-vertical .ivu-menu-item-group-title {
  display: none;
}

/deep/ .ivu-menu-vertical.ivu-menu-light:after {
  display: none;
}

/deep/ .ivu-menu {
  z-index: 0 !important;
}

.code {
  position: relative;
}

.left-wrapper {
  background: #fff;
  border-right: 1px solid #dcdee2;
  width: 156px;
}

.picCon {
  width: 280px;
  height: 510px;
  background: #ffffff;
  border: 1px solid #eeeeee;
  border-radius: 25px;

  .pictrue {
    width: 250px;
    height: 417px;
    border: 1px solid #eeeeee;
    opacity: 1;
    border-radius: 10px;
    margin: 30px auto 0 auto;
    // img{
    // width 100%;
    // height 100%;
    // border-radius: 10px;
    // }
  }

  .circle {
    width: 36px;
    height: 36px;
    background: #ffffff;
    border: 1px solid #eeeeee;
    border-radius: 50%;
    margin: 13px auto 0 auto;
  }
}
</style>
