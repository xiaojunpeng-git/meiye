<template>
  <div class="main">
    <div class="main-header bg-fff">
      <div class="main-title">
        <span>平台管理</span>
      </div>
    </div>
    <div class="main-content bg-fff">
      <div class="title">标题设置</div>
      <div class="form">
        <div class="form-item flex-y-center">
          <div class="form-label">标题名称</div>
          <div class="form-value">
            <Input v-model="merMenuData.title" placeholder="请输入标题名称" />
          </div>
        </div>
      </div>
    </div>
    <div class="main-content bg-fff">
      <div class="title">页面设置</div>
      <div class="form">
        <div class="form-item">
          <div class="form-label">是否显示</div>
          <div class="form-value">
            <RadioGroup
              v-model="merMenuData.is_show"
            >
              <Radio :label="1">显示</Radio>
              <Radio :label="0">隐藏</Radio>
            </RadioGroup>
          </div>
        </div>
        <div class="form-item flex-y-center">
          <div class="form-label">选择风格</div>
          <div class="form-value">
            <Button type="primary" class="mr20" @click="changeStyleModal = true"
              >修改风格</Button
            >
            <span>风格{{ merMenuData.style }}</span>
          </div>
        </div>
      </div>
    </div>
    <div class="main-content bg-fff">
      <div class="title">模块信息</div>
      <Alert class="mb-20" show-icon>此模块需要相应模块权限才会显示</Alert>
      <div class="tips">点击下方选项卡可进行编辑；鼠标拖拽版块可调整顺序</div>
      <div class="form">
        <div class="list-box">
          <draggable
            class="dragArea list-group"
            :list="merMenuData.list"
            group="peoples"
            handle=".move-icon"
          >
            <div
              class="item"
              v-for="(item, index) in merMenuData.list"
              :key="index"
            >
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
              </div>
            </div>
          </draggable>
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
    <Modal
      v-model="changeStyleModal"
      width="900px"
      scrollable
      closable
      title="风格选择器"
      :mask-closable="false"
      :z-index="999"
      @on-ok="saveStyle"
    >
      <div class="pic-box">
        <div
          class="pic-item cup"
          v-for="(item, index) in styleList"
          :key="index"
          @click="selectStyle(index)"
        >
          <div class="pic" :class="{ 'is-active': menuStyle == index + 1 }">
            <img :src="item.pic" alt="" />
          </div>
          <div class="text">风格{{ index + 1 }}</div>
        </div>
      </div>
    </Modal>
    <linkaddress ref="linkaddres" @linkUrl="linkUrl"></linkaddress>
  </div>
</template>

<script>
import draggable from "vuedraggable";
import uploadPictures from "@/components/uploadPictures";
import propertyList from "@/plugins/propertyList";
import linkaddress from "@/components/linkaddress";
export default {
  name: "",
  components: {
    draggable,
    uploadPictures,
    linkaddress,
  },
  data() {
    return {
      styleList: [
        {
          pic: require("@/assets/images/mer-menu-admin-style-1.png"),
        },
        {
          pic: require("@/assets/images/mer-menu-admin-style-2.png"),
        },
        {
          pic: require("@/assets/images/mer-menu-admin-style-3.png"),
        },
      ],
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
      modalPic: false,
      changeStyleModal: false,
      isChoice: "单选",
      menuStyle: 1, // 默认选中风格
      activeIndex: 0,
      indexLast: 0,
    };
  },
  computed: {
    merMenuData(val) {
      return this.$store.state.admin.userTemplateConfig.merMenu;
    },
  },

  created() {},
  mounted() {
    this.menuStyle = this.$store.state.admin.userTemplateConfig.merMenu.style;
  },
  methods: {
    linkUrl(e) {
      this.merMenuData.list[this.activeIndex].url = e;
    },
    addHotTxt() {
      this.merMenuData.list.push({
        pic: "",
        name: "",
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
        this.merMenuData.list[this.activeIndex].pic = pc.att_dir;
        this.modalPic = false;
      });
    },
    selectStyle(index) {
      this.menuStyle = index + 1;
    },
    saveStyle() {
      this.merMenuData.style = this.menuStyle;
    },
    // 删除
    bindDelete(item, index) {
      this.merMenuData.list.splice(index, 1);
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

    .tips {
      font-size: 12px;
      font-weight: 400;
      color: #BBBBBB;
      margin-bottom: 15px;
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
            margin-bottom: 10px;

            img {
              width: 100%;
              height: 100%;
            }
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

.pic-box {
  display: flex;
  flex-wrap: wrap;
  margin: 0 -6px;

  .pic-item .is-active {
    border: 1px solid #1890FF;

    img {
      transform: scale(1.05);
    }
  }

  .pic {
    width: 276px;
    height: 218px;
    background: #F5F5F5;
    border-radius: 4px;
    border: 1px solid #DDDDDD;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 6px;
    overflow: hidden;

    img {
      width: 216px;
      transition: all 0.7s;
      -webkit-user-drag: none;
    }

    img:hover {
      transform: scale(1.05);
    }
  }

  .text {
    text-align: center;
  }
}

.list-box {
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