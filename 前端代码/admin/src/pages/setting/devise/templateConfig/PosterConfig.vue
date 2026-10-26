<template>
  <div class="main">
    <div class="main-header bg-fff">
      <div class="main-title">
        <span>广告位</span>
      </div>
    </div>
    <div class="main-content bg-fff">
      <div class="title">页面设置</div>
      <div class="form">
        <div class="form-item">
          <div class="form-label">是否显示</div>
          <div class="form-value">
            <RadioGroup
              v-model="posterData.is_show"
            >
              <Radio :label="1">显示</Radio>
              <Radio :label="0">隐藏</Radio>
            </RadioGroup>
          </div>
        </div>
        <div class="form-item">
          <div class="list-box">
            <draggable
              class="dragArea list-group"
              :list="posterData.list"
              group="peoples"
              handle=".move-icon"
            >
              <div
                class="item"
                v-for="(item, index) in posterData.list"
                :key="index"
              >
                <div class="delect-btn" @click.stop="bindDelete(item, index)">
                  <span class="iconfont-diy icondel_1"></span>
                </div>
                <div class="move-icon">
                  <span class="iconfont-diy icondrag"></span>
                </div>
                <div class="img-box" @click="modalPicTap('单选', index)">
                  <img :src="item.pic" alt="" v-if="item.pic" />
                  <div class="upload-box" v-else>
                    <Icon
                      type="ios-camera-outline"
                      size="36"
                      show-word-limit
                      :maxLength="6"
                    />
                  </div>
                </div>
                <div class="info">
                  <span class="span">
                    <Input v-model="item.name" placeholder="请输入标题" />
                  </span>
                  <div class="input-box mt10">
                    <Input
                      icon="ios-link"
                      v-model="item.url"
					  @on-click="getLink(index)"
                      placeholder="请选择链接"
                    />
                  </div>
                </div>
              </div>
            </draggable>
            <div v-if="posterData.list">
              <div
                class="add-btn"
                @click="addHotTxt"
                v-if="posterData.list.length < 10"
              >
                <Button class="btn" type="primary" ghost>
                  <span class="iconfont iconjiahao"></span>添加板块
                </Button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <Modal
      v-model="modalPic"
      width="960px"
      scrollable
      footer-hide
      closable
      title="上传商品图"
      :mask-closable="false"
      :z-index="999"
    >
      <uploadPictures
        :isChoice="isChoice"
        @getPic="getPic"
        :gridBtn="gridBtn"
        :gridPic="gridPic"
      ></uploadPictures>
    </Modal>
    <linkaddress ref="linkaddres" @linkUrl="linkUrl"></linkaddress>
  </div>
</template>

<script>
import draggable from "vuedraggable";
import uploadPictures from "@/components/uploadPictures";
import linkaddress from "@/components/linkaddress";
export default {
  name: "",
  components: {
    uploadPictures,
    linkaddress,
    draggable,
  },
  data() {
    return {
      styleList: [
        {
          pic: require("@/assets/images/order-static-style-1.png"),
        },
        {
          pic: require("@/assets/images/order-static-style-2.png"),
        },
      ],
      modalPic: false,
      isChoice: "单选",
      gridBtn: {
        xl: 4,
        lg: 8,
        md: 8,
        sm: 8,
        xs: 8,
      },
      gridPic: {
        xl: 6,
        lg: 8,
        md: 12,
        sm: 12,
        xs: 12,
      },
      activeIndex: 0,
    };
  },
  computed: {
    posterData(val) {
      return this.$store.state.admin.userTemplateConfig.poster;
    },
  },

  created() {},
  mounted() {},
  methods: {
    // 点击图文封面
    modalPicTap(title) {
      this.modalPic = true;
    },
    getLink() {
      this.$refs.linkaddres.modals = true;
    },
    linkUrl(e) {
      this.posterData.list[this.activeIndex].url = e;
    },
    addHotTxt() {
      this.posterData.list.push({
        pic: "",
        name: "",
        url: "",
      });
    },
    // 点击图文封面
    modalPicTap(title, index) {
      this.activeIndex = index;
      this.modalPic = true;
    },
    getLink(index) {
      this.activeIndex = index;
      this.$refs.linkaddres.modals = true;
    },
    // 获取图片信息
    getPic(pc) {
      this.$nextTick(() => {
        this.posterData.list[this.activeIndex].pic = pc.att_dir;
        this.modalPic = false;
      });
    },
    // 删除
    bindDelete(item, index) {
      this.posterData.list.splice(index, 1);
    },
  },
};
</script>
<style lang="stylus" scoped>
.bg-fff {
  background-color: #fff;
}

.main {
  .main-header {
    font-size: 16px;
    padding: 20px 15px;
    margin: 6px 0 0;
    font-weight: 500;
    color: #333333;
    line-height: 16px;
  }

  .main-content {
    padding: 20px 15px;
    margin-top: 6px;

    .title {
      font-size: 14px;
      font-weight: 400;
      color: #333333;
      line-height: 14px;
      margin-bottom: 20px;
    }

    .form {
      .form-item:last-child {
        margin-bottom: 0;
      }

      .form-item {
        display: flex;
        font-size: 12px;
        font-weight: 400;
        line-height: 12px;
        margin-bottom: 30px;

        .form-label {
          color: #999999;
          line-height: 17px;
          margin-right: 46px;
          white-space: nowrap;
        }

        .form-value {
          color: #666666;

          /deep/ .ivu-radio-wrapper {
            margin-right: 43px;
          }

          /deep/ .ivu-checkbox-wrapper {
            margin-bottom: 22px;
            width: 84px;
            font-size: 12px;
          }

          .box {
            width: 60px;
            height: 60px;

            img {
              width: 100%;
              height: 100%;
            }
          }

          .tip {
            color: #999;
            margin-left: 20px;
          }

          .upload-box {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            width: 100%;
            height: 100%;
            background: #fff;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            border: 1px solid #EEEEEE;
            color: #BFBFBF;
          }
        }
      }
    }
  }
}

.list-box {
  width: 100%;

  .item {
    position: relative;
    display: flex;
    background: #F9F9F9;
    align-items: center;
    padding: 16px 20px 16px 0;
    margin-bottom: 16px;
    border-radius: 3px;

    .delect-btn {
      position: absolute;
      right: -13px;
      top: -16px;
	  line-height: 1.5;

      .iconfont-diy {
        font-size: 25px;
        color: #ccc;
      }
    }

    .move-icon {
      display: flex;
      align-items: center;
      justify-content: center;
      width: 30px;
      cursor: move;
    }

    .img-box {
      position: relative;
      width: 64px;
      height: 64px;
      display: flex;
      align-items: center;
      justify-content: center;
	  background-color: #fff;

      img {
        width: 100%;
        height: 100%;
        border-radius: 3px;
      }
    }

    .info {
      flex: 1;
      margin-left: 22px;

      .info-item {
        display: flex;
        align-items: center;
        margin-bottom: 10px;

        &:nth-last-child(1) {
          margin-bottom: 0;
        }

        .span {
          width: 40px;
          font-size: 12px;
          color: #999;
        }

        .input-box {
          flex: 1;
        }
      }
    }
  }
}

.add-btn {
  margin-top: 10px;

  .btn {
    width: 100%;
    height: 36px;
    border-color: #EEEEEE;
    color: #666666;

    .iconfont {
      font-size: 11px;
      margin-right: 5px;
    }
  }
}
</style>