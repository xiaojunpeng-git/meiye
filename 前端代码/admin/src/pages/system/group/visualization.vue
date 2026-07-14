<template>
  <!-- 装修-页面数据 -->
  <div>
    <div class="i-layout-page-header">
      <PageHeader
          class="product_tabs"
          :title="$route.meta.title"
          hidden-breadcrumb
          :style="'padding-right:'+(menuCollapse?105:20)+'px'"
      >
        <div slot="title">
          <div class="float-l">
            <span class="mr20">开屏广告</span>
          </div>
        </div>
      </PageHeader>
    </div>
    <Card :bordered="false" dis-hover class="ivu-mt">
      <Row class="ivu-mt box-wrapper">
        <Col>
          <div class="iframe" :bordered="false">
            <div class="nofonts" v-if="tabList.list == ''">暂无照片，请添加~</div>
            <swiper :options="swiperOption" class="swiperimg on">
              <swiper-slide class="swiperimg on" v-for="(item,indexa) in tabList.list" :key="indexa + 'a'">
                <img :src="item.img">
              </swiper-slide>
            </swiper>
          </div>
        </Col>
        <Col>
          <div :class=" name !='admin_login_slide' ?'content':'contents'">
            <div class="right-box">
              <div class="hot_imgs">
                <div class="title">引导页设置</div>
                <div class="title-text">建议尺寸：750 * 1334px，拖拽图片可调整图片顺序哦，最多添加五张</div>
                <div class="list-box">
                  <div>
                    <Form :model="formItem" :label-width="80">
                      <FormItem label="开屏广告:">
                        <i-switch v-model="formItem.status" :true-value="1" :false-value='0'
                                  size="large">
                          <span slot="open">开启</span>
                          <span slot="close">关闭</span>
                        </i-switch>
                      </FormItem>
                      <FormItem label="广告时间:">
                        <InputNumber :min="0" v-model="formItem.time" placeholder="请输入开屏广告时间"
                                     style="width: 150px"/>
                        （单位：秒）
                      </FormItem>
                      <FormItem label="展示间隔:">
                        <InputNumber :min="0" v-model="formItem.interval_time" placeholder="请输入展示间隔时间"
                                     style="width: 150px"/>
                        （单位：小时）
                      </FormItem>
                    </Form>
                  </div>
                  <draggable class="dragArea list-group" :list="tabList.list" group="peoples" handle=".move-icon">
                    <div class="item" v-for="(item,index) in tabList.list" :key="index">
                      <div class="move-icon">
                        <span class="iconfont icondrag2"></span>
                      </div>
                      <div class="img-box" @click="modalPicTap('单选',index)">
                        <img :src="item.img" alt="" v-if="item.img">
                        <div class="upload-box" v-else>
                          <Icon type="ios-camera-outline" size="36"/>
                        </div>
                        <div class="delect-btn" @click.stop="bindDelete(item,index)">
                          <Icon type="md-close-circle" size="26"/>
                        </div>
                      </div>
                      <div class="info">
                        <div class="info-item">
                          <span>图片名称：</span>
                          <div class="input-box">
                            <Input v-model="item.comment" placeholder="请填写名称"/>
                          </div>
                        </div>
                        <div class="info-item">
                          <span>链接地址：</span>
                          <div class="input-box">
                            <Input v-model="item.link" icon="ios-link" @on-click="link(index)" placeholder="选择链接"/>
                          </div>
                        </div>
                      </div>
                    </div>
                  </draggable>
                  <div>
                    <Modal v-model="modalPic" width="960px" scrollable footer-hide closable
                           title='上传商品图'
                           :mask-closable="false" :z-index="999">
                      <uploadPictures
                          :isChoice="isChoice"
                          @getPic="getPic"
                          :gridBtn="gridBtn"
                          :gridPic="gridPic"
                          v-if="modalPic"></uploadPictures>
                    </Modal>
                  </div>
                </div>
                <template>
                  <div class="add-btn">
                    <Button ghost class="btn-add" @click="addBox">添加图片</Button>
                  </div>
                </template>
              </div>
            </div>
          </div>
        </Col>
      </Row>
    </Card>
    <Card :bordered="false" dis-hover class="fixed-card"
          :style="{left: `${!menuCollapse?'236px':isMobile?'0':'60px'}`}">
      <div class="acea-row row-center-wrapper">
        <Button class="bnt" type="primary" @click="save" :loading="loadingExist">保存</Button>
      </div>
    </Card>
    <linkaddress ref="linkaddres" @linkUrl="linkUrl"></linkaddress>
  </div>
</template>

<script>
import Setting from "@/setting";
import {diyGetInfo, diySave, getColorChange} from "@/api/diy";
import {mapState} from "vuex";
import editFrom from "@/components/from/from";
import {
  groupAllApi,
  groupDataListApi,
  groupSaveApi,
  groupDataAddApi,
  groupDataHeaderApi,
  groupDataEditApi,
  groupDataSetApi,
  getAgreement,
  setAgreement,
  openAdvSave,
  getOpenAdv,
} from "@/api/system";
import draggable from "vuedraggable";
import uploadPictures from "@/components/uploadPictures";
import linkaddress from "@/components/linkaddress";

export default {
  name: "list",
  components: {editFrom, draggable, uploadPictures, linkaddress},
  computed: {
    labelWidth() {
      return this.isMobile ? undefined : 120;
    },
    labelPosition() {
      return this.isMobile ? "top" : "right";
    },
    ...mapState("admin/layout", ["menuCollapse", "isMobile"]),
  },
  data() {
    return {
      formValidate: {
        content: "",
      },
      ruleValidate: {},
      agreementType: 0, //判断的隐私协议
      bgimg: 0,
      columns1: [],
      bgCol: "",
      name: "routine_home_bast_banner",
      grid: {
        xl: 7,
        lg: 7,
        md: 12,
        sm: 24,
        xs: 24,
      },
      loading: false,
      sginList: [],
      swiperOption: {
        //显示分页
        pagination: {
          el: ".swiper-pagination",
        },
        //设置点击箭头
        navigation: {
          nextEl: ".swiper-button-next",
          prevEl: ".swiper-button-prev",
        },
        //自动轮播
        autoplay: {
          delay: 2000,
          //当用户滑动图片后继续自动轮播
          disableOnInteraction: false,
        },
        //开启循环模式
        loop: false,
      },
      url: "",
      BaseURL: Setting.apiBaseURL.replace(/adminapi/, ""),
      pageId: 0,
      theme3: "light",
      tabList: {
        list:[]
      },
      lastObj: {
        add_time: "",
        comment: "",
        gid: "",
        id: "",
        img: "",
        link: "",
        sort: "",
        status: 1,
      },
      isChoice: "单选",
      modalPic: false,
      gridPic: {
        xl: 6,
        lg: 8,
        md: 12,
        sm: 12,
        xs: 12,
      },
      gridBtn: {
        xl: 4,
        lg: 8,
        md: 8,
        sm: 8,
        xs: 8,
      },
      groupAll: [],
      activeIndex: 0,
      sortName: null,
      activeIndexs: 0,
      cmsList: [],
      loadingExist: false,
      formItem: {
        time: 0,
        interval_time:0,
        type: "pic",
        status: 1,
        value: [],
        video_link: "",
      },
    };
  },
  mounted() {
    this.info();
    this.url = this.BaseURL + "pages/columnGoods/HotNewGoods/index?type=1&name=精品推荐";
  },
  methods: {
    getEditorContent(data) {
      this.formValidate.content = data;
    },
    linkUrl(e) {
      this.tabList.list[this.activeIndexs].link = e;
    },
    info(){
      getOpenAdv().then((res) => {
        res.data.status = parseInt(res.data.status);
        this.formItem = res.data;
        this.tabList.list = res.data.value;
      });
    },
    addBox() {
      if (this.tabList.list.length == 5) {
        this.$Message.warning("最多添加五张呦");
      } else {
        this.$nextTick(()=>{
          this.tabList.list.push(JSON.parse(JSON.stringify(this.lastObj)));
        })
      }
    },
    // 删除
    bindDelete(item, index) {
      this.tabList.list.splice(index, 1);
    },
    // 点击图文封面
    modalPicTap(title, index) {
      this.activeIndex = index;
      this.modalPic = true;
    },
    // 获取图片信息
    getPic(pc) {
      this.$nextTick(() => {
        this.tabList.list[this.activeIndex].img = pc.att_dir;
        this.modalPic = false;
      });
    },
    save() {
      this.formItem.value = this.tabList.list;
      openAdvSave(this.formItem).then((res) => {
        this.loadingExist = false;
        this.$Message.success(res.msg);
      })
          .catch((err) => {
            this.loadingExist = false;
            this.$Message.error(err.msg);
          });
    },
    link(index) {
      this.activeIndexs = index;
      this.$refs.linkaddres.modals = true;
    },
  },
};
</script>

<style scoped lang="stylus">
.ml14 {
  margin-left: 14px
}

.btn-add {
  width: 100px;
  height: 35px;
  background-color: #1890FF;
  color: #FFFFFF;
}

/deep/ .ivu-menu-vertical .ivu-menu-item-group-title {
  display: none;
}

/deep/ .ivu-menu-vertical.ivu-menu-light:after {
  display: none;
}

/deep/ .ivu-form-item-content {
  margin-left: 0px !important;
}

.nofont {
  text-align: center;
  line-height: 123px;
}

.nofonts {
  text-align: center;
  line-height: 125px;
}

.save {
  width: 100%;
  margin: 0 auto;
  text-align: center;
  background-color: #FFF;
  bottom: 0;
  padding: 16px;
  border-top: 3px solid #f5f7f9;
}

.form {
  .goodsTitle {
    margin-bottom: 25px;
  }

  .goodsTitle ~ .goodsTitle {
    margin-top: 20px;
  }

  .goodsTitle .title {
    border-bottom: 2px solid #1890ff;
    padding: 0 8px 12px 5px;
    color: #000;
    font-size: 14px;
  }

  .goodsTitle .icons {
    font-size: 15px;
    margin-right: 8px;
    color: #999;
  }

  .add {
    font-size: 12px;
    color: #1890ff;
    padding: 0 12px;
    cursor: pointer;
  }

  .radio {
    margin-right: 20px;
  }

  .upLoad {
    width: 58px;
    height: 58px;
    line-height: 58px;
    border: 1px dotted rgba(0, 0, 0, 0.1);
    border-radius: 4px;
    background: rgba(0, 0, 0, 0.02);
  }

  .iconfont {
    color: #898989;
  }

  .pictrue {
    width: 60px;
    height: 60px;
    border: 1px dotted rgba(0, 0, 0, 0.1);
    margin-right: 10px;
  }

  .pictrue img {
    width: 100%;
    height: 100%;
  }
}

.agreement-box {
  width: 310px;
  height: 550px;
  border-radius: 10px;
  background: rgba(0, 0, 0, 0);
  border: 1px solid #EEEEEE;
  opacity: 1;
  position: relative;

  .template {
    position: absolute;
    width: 100%;
    height: 100%;
    top: 0;
    left: 0;
    border-radius: 10px;
    background-color: #817e81;
  }

  .htmls_box {
    font-size: 12px;
    width: 259px;
    height: 430px;
    border-radius: 10px;
    background-color: #fff;
    position: absolute;
    top: 58px;
    left: 26px;

    .htmls_top {
      position: absolute;
      top: 8px;
      left: 0;
      height: 34px;
      text-align: center;
      width: 100%;
      line-height: 35px;
      font-weight: 600;
      font-size: 20px;
    }

    .htmls_font {
      position: absolute;
      bottom: 0;
      left: 0;
      padding: 15px 15px;
      text-align: center;
      width: 100%;

      div {
        height: 35px;
        line-height: 35px;
        border-radius: 20px;
      }

      .ok {
        background-color: #f33316;
        color: #FFFFFF;
      }
    }

    .htmls {
      position: absolute;
      background-color: #fff;
      top: 50px;
      left: 0;
      width: 259px;
      height: 281px;
      border-radius: 4px;
      overflow: auto;
      padding: 5px 15px;
    }

    .htmls::-webkit-scrollbar {
      display: none;
    }
  }
}

.Bbox {
  width: 495px;
  display: flex;
  flex-wrap: wrap;
}

.item {
  margin-right: 15px;
  border: 1px dashed #dbdbdb;
  padding-bottom: 10px;
  padding-right: 15px;
  padding-top: 20px;
}

.items {
  margin-right: 15px;
  border: 1px dashed #dbdbdb;
  padding-bottom: 10px;
  padding-top: 15px;
  position: relative;
  display: flex;
  margin-top: 20px;

  .move-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 80px;
    cursor: move;
    color: #D8D8D8;
  }

  .img-box {
    position: relative;
    width: 80px;
    height: 80px;

    img {
      width: 100%;
      height: 100%;
    }
  }

  .info {
    flex: 1;
    margin-left: 22px;

    .info-item {
      display: flex;
      align-items: center;
      margin-bottom: 10px;

      span {
        // width 40px
        font-size: 13px;

        .input-box {
          flex: 1;
        }
      }
    }
  }

  .delect-btn {
    position: absolute;
    right: -12px;
    top: -12px;
    color: #999999;

    .iconfont {
      font-size: 28px;
      color: #999;
    }
  }
}

.table {
  color: #515a6e;
  font-size: 14px;
  background-color: #fff;
  box-sizing: border-box;
  margin: 0 auto;
  margin-left: 20px;
}

.contents {
  width: 150px;

  .right-box {
    margin-left: 40px;
  }

  .title-text {
    width: 500px;
  }
}

.pciframe {
  margin-left: 20px;
  width: 430px;
  height: 280px;
  background: #FFFFFF;
  border: 1px solid #EEEEEE;
  border-radius: 13px;

  img {
    width: 100%;
    height: 100%;
  }

  .pciframe-box {
    background: rgba(0, 0, 0, 0);
    // border: 1px solid #EEEEEE;
    border-radius: 4px;
  }

  .pcmoddile_goods {
    position: absolute;
    top: 69px;
    width: 171px;
    height: 140px;
    border-top-left-radius: 2px;
    border-bottom-left-radius: 2px;
    left: 65px;
    background-color: #fff;
  }

  .pcswiperimg_goods {
    height: 140px;
    background-color: #f5f5f5;

    img {
      width: 100%;
      height: 100%;
    }
  }
}

.link {
  display: inline-block;
  width: 100%;
  height: 32px;
  line-height: 1.5;
  padding: 4px 7px;
  border: 1px solid #dcdee2;
  border-radius: 4px;
  background-color: #fff;
  position: relative;
  cursor: text;
  transition: border 0.2s ease-in-out, background 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
  font-size: 13px;
  font-family: PingFangSC-Regular;
  line-height: 22px;
  color: rgba(0, 0, 0, 0.25);
  opacity: 1;
  cursor: pointer;

  .you {
    color: #999999;
    float: right;
    margin-right: 11px;
  }
}

.swiperimg {
  width: 310px;
  height: 115px;
  border-top-left-radius: 10px;
  border-top-right-radius: 10px;

  &.on {
    height 100%;
  }

  img {
    width: 100%;
    height: 100%;
  }
}

.swiperimg_goods {
  width: 284px;
  height: 124px;
  border-radius: 4px;
  line-height: 99px;
  text-align: center;
  background-color: #f5f5f5;

  img {
    width: 100%;
    height: 100%;
  }
}

.title {
  padding: 0 0 13px 0;
  font-weight: bold;
  font-size: 15px;
  border-left: 2px solid #1890FF;
  height: 23px;
  padding-left: 10px;
}

.title-text {
  padding: 0 0 0px 16px;
  color: #999;
  font-size: 12px;
  margin-top: 10px;
}

.content {
  // width 510px;

  .right-box {
    margin-left: 40px;
  }
}

.box {
  border-top: 3px solid #f5f7f9;
  padding: 10px;
  padding-top: 25px;
  width: 100%;

  .save {
    background-color: #1890FF;
    color: #FFFFFF;
    width: 71px;
    height: 30px;
    margin: 0 auto;
    text-align: center;
    line-height: 30px;
    cursor: pointer;
  }
}

.iframe {
  margin-left: 20px;
  position: relative;
  width: 310px;
  height: 550px;
  background: #FFFFFF;
  border: 1px solid #EEEEEE;
  opacity: 1;
  border-radius: 10px;
}

.moddile {
  position: absolute;
  width: 310px;
  height: 550px;
  top: 0px;
  opacity: 0;
  left: 0px;
  border-radius: 4px;
}

.moddile_box {
  position: absolute;
  top: 0px;
  width: 310px;
  height: 115px;
  border-top-left-radius: 4px;
  border-top-right-radius: 4px;
  left: 0px;
  background-color: #f5f5f5;
}

.moddile_goods {
  position: absolute;
  top: 18px;
  width: 284px;
  height: 124px;
  /* border-top-left-radius: 10px; */
  /* border-top-right-radius: 10px; */
  border-radius: 5px;
  left: 13px;
  line-height: 99px;
  text-align: center;
  background-color: #f5f5f5;
}

.combination_height {
  /*height: 136px;*/
}

.iframe-box {
  width: 310px;
  height: 550px;
  border-radius: 10px;
  // margin: 30px 15px 0px 15px
  background: rgba(0, 0, 0, 0);
  border: 1px solid #EEEEEE;
  opacity: 1;

  img {
    width: 100%;
    height: 100%;
  }
}

.left-wrapper {
  background: #fff;
  border-right: 1px solid #dcdee2;
}

.menu-item {
  position: relative;
  display: flex;
  justify-content: space-between;
  word-break: break-all;

  .icon-box {
    z-index: 3;
    position: absolute;
    right: 20px;
    top: 50%;
    transform: translateY(-50%);
    display: none;
  }

  &:hover .icon-box {
    display: block;
  }

  .right-menu {
    z-index: 10;
    position: absolute;
    right: -106px;
    top: -11px;
    width: auto;
    min-width: 121px;
  }
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

.ivu-menu {
  z-index: auto;
}

.icondrag2 {
  font-size: 26px;
  color: #d8d8d8;
}

.hot_imgs {
  margin-bottom: 20px;

  .title {
    font-size: 14px;
  }

  .list-box {
    .item {
      position: relative;
      display: flex;
      margin-top: 20px;

      .move-icon {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 30px;
        height: 80px;
        cursor: move;
        color: #D8D8D8;
      }

      .img-box {
        position: relative;
        width: 80px;
        height: 80px;

        img {
          width: 100%;
          height: 100%;
        }
      }

      .info {
        flex: 1;
        margin-left: 22px;

        .info-item {
          display: flex;
          align-items: center;
          margin-bottom: 10px;

          span {
            // width 40px
            font-size: 13px;
          }

          .input-box {
            flex: 1;
          }
        }
      }

      .delect-btn {
        position: absolute;
        right: -12px;
        top: -12px;
        color: #999999;

        .iconfont {
          font-size: 28px;
          color: #999;
        }
      }
    }
  }

  .add-btn {
    margin-top: 20px;
  }
}

.upload-box {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
  background: #ccc;
}

.iconfont {
  color: #DDDDDD;
  font-size: 28px;
}

.iframe-boxs::-webkit-scrollbar {
  display: none;
}

.sgin_iframe::-webkit-scrollbar {
  display: none;
}

.iframe-boxs {
  width: 310px;
  height: 550px;
  border-radius: 10px;
  background: rgba(0, 0, 0, 0);
  border: 1px solid #EEEEEE;
  opacity: 1;
  overflow: auto;

  .moneyBox {
    background-color: var(--color-theme);
    height: 414px;
    border-radius: 10px;

    .box1 {
      text-align: center;
      color: #FFFFFF;
      padding-bottom: 15px;

      .font1 {
        padding-top: 20px;
        // padding-bottom 15px
        font-size: 12px;
        opacity: 0.6;
      }

      .font2 {
        font-size: 30px;
        font-style: normal;
        opacity: 0.9;
      }
    }

    .moneyBox_content {
      background-color: #FFFFFF;
      height: 317px;
      border-radius: 4px;

      .box2 {
        display: flex;
        justify-content: space-around;
        height: 35px;
        line-height: 35px;
        margin-bottom: 10px;

        div:nth-child(1) {
          font-weight: bold;
          border-bottom: 2px solid var(--color-theme);
        }
      }

      .box3 {
        padding: 0px 10px;
        display: flex;
        justify-content: left;
        flex-wrap: wrap;

        .box3_box {
          width: 90px;
          height: 55px;
          border-radius: 9px;
          background-color: #f4f4f4;
          color: #888;
          margin-bottom: 10px;
          text-align: center;
          padding-top: 3px;
          font-size: 19px;
          margin-right: 3px;
          margin-left: 3px;

          .font {
            font-size: 11px;
            font-style: normal;
          }
        }

        .box3_box:nth-child(1) {
          width: 90px;
          height: 55px;
          border-radius: 9px;
          background-color: var(--color-theme);
          color: #FFFFFF;
          text-align: center;
          padding-top: 3px;
          margin-right: 3px;
          margin-left: 3px;
        }

        .other {
          line-height: 55px;
        }
      }

      .box4 {
        padding: 0px 10px;

        .tips {
          font-size: 14px;
          color: #333333;
          font-weight: 800;
          margin-bottom: 7px;
          margin-top: 10px;
        }

        .tips-samll {
          font-size: 12px;
          color: #333333;
          margin-bottom: 7px;

          p {
            margin: 2px 0px;
          }
        }
      }

      .box5 {
        font-size: 15px;
        width: 225px;
        height: 40px;
        border-radius: 25px;
        margin: 23px auto 0 auto;
        line-height: 40px;
        text-align: center;
        background-color: var(--color-theme);
        color: #FFFFFF;
      }
    }
  }
}

.fixed-card {
  position: fixed;
  right: 0;
  bottom: 0;
  left: 200px;
  z-index: 99;
  box-shadow: 0 -1px 2px rgb(240, 240, 240);
}
</style>