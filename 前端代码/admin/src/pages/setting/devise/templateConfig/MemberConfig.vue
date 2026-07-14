<template>
  <div class="main">
    <div class="main-header bg-fff">
      <div class="main-title">
        <span>会员信息</span>
      </div>
    </div>
    <div class="main-content bg-fff">
      <div class="title">页面设置</div>
      <div class="form">
        <div class="form-item flex-y-center">
          <div class="form-label">选择风格</div>
          <div class="form-value">
            <Button type="primary" class="mr20" @click="changeStyleModal = true"
              >修改风格</Button
            >
            <span>风格{{ merData.style }}</span>
          </div>
        </div>
      </div>
    </div>
    <div class="main-content bg-fff">
      <div class="title">会员信息</div>
      <div class="form">
        <div class="form-item">
          <div class="form-label">个人信息</div>
          <div class="form-value">
            <RadioGroup
              v-model="merData.per_show_type"
              :true-value="1"
              :false-value="0"
            >
              <Radio :label="0">手机号</Radio>
              <Radio :label="1">用户ID</Radio>
            </RadioGroup>
          </div>
        </div>
        <div class="form-item" v-if="[1, 2, 5].includes(merData.style)">
          <div class="form-label">资产内容</div>
          <div class="form-value">
            <CheckboxGroup
              v-model="merData.property"
              @on-change="changeProperty"
            >
              <Checkbox
                :label="item.value"
                v-for="(item, index) in propertyList"
                :disabled="
                  !merData.property.includes(item.value) &&
                  merData.property.length >= maxNum
                "
                >{{ item.label }}</Checkbox
              >
            </CheckboxGroup>
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
          <div class="pic" :class="{ 'is-active': mertStyle == index + 1 }">
            <img :src="item.pic" alt="" />
          </div>
          <div class="text">风格{{ index + 1 }}</div>
        </div>
      </div>
    </Modal>
  </div>
</template>

<script>
import uploadPictures from "@/components/uploadPictures";
import propertyList from "@/plugins/propertyList";
export default {
  name: "",
  components: {
    uploadPictures,
  },
  data() {
    return {
      styleList: [
        {
          pic: require("@/assets/images/member-style-1.png"),
        },
        {
          pic: require("@/assets/images/member-style-2.png"),
        },
        {
          pic: require("@/assets/images/member-style-3.png"),
        },
        {
          pic: require("@/assets/images/member-style-4.png"),
        },
        {
          pic: require("@/assets/images/member-style-5.png"),
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
      propertyList: propertyList,
      mertStyle: 1, // 默认选中风格
      maxNum: 5,
    };
  },
  computed: {
    merData(val) {
      return this.$store.state.admin.userTemplateConfig.member;
    },
  },
  watch: {
    merData: {
      handler(nVal, oVal) {
		  this.mertStyle = nVal.style;
		  if (this.mertStyle !== 1) {
		    this.maxNum = 3;
		  } else {
		    this.maxNum = 5;
		  }
      },
      immediate: true,
      deep: true,
    },
  },
  created() {},
  mounted() {},
  methods: {
    // 点击图文封面
    modalPicTap(title) {
      this.modalPic = true;
    },
    // 获取图片信息
    getPic(pc) {
      this.$nextTick(() => {
        console.log(pc);
        this.merData.avatar_url = pc.att_dir;
        this.modalPic = false;
      });
    },
    selectStyle(index) {
      this.mertStyle = index + 1;
	  this.$store.commit("admin/userTemplateConfig/setMemberStyle", index + 1);
      if (this.mertStyle !== 1) {
        this.merData.property = [0, 1, 2];
        this.maxNum = 3;
      } else {
        this.maxNum = 5;
      }
    },
    saveStyle() {
      this.merData.style = this.mertStyle;
    },
    changeProperty(data) {
      console.log(data);
      this.merData.property = data;
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
</style>