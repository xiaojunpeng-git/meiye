
<template>
  <div class="acea-row">
    <div class="card_box">
      <div class="img_box acea-row row-middle">
        <img class="img" src="@/assets/images/cardtopbar.png" />
      </div>
      <div
        class="card_wrap"
        :style="{
          backgroundColor:
            baseInfo.card_cover == 2 ? baseInfo.card_cover_color : '',
          backgroundImage:
            baseInfo.card_cover == 1
              ? `url(${
                  baseInfo.card_cover_image ||
                  require('@/assets/images/card_cover_image.png')
                })`
              : '',
        }"
      >
        <div
          :class="{ cover: baseInfo.card_cover == 1 }"
          class="card acea-row row-middle pl-14 pr-10 text--w111-fff"
        >
          <div></div>
          <div class="flex-1">
            <div>商品名称展示</div>
            <div class="mt-8 fs-10">有效期：XXX</div>
          </div>
        </div>
      </div>
    </div>
    <FormItem label="卡片封面：" class="flex-1">
      <RadioGroup :value="baseInfo.card_cover" @on-change="coverChange">
        <Radio :label="1">
          <span>图片</span>
        </Radio>
        <Radio :label="2">
          <span>颜色</span>
        </Radio>
      </RadioGroup>
      <div class="selst">
        <div v-if="baseInfo.card_cover == 1">
          <div class="acea-row row-middle">
            <div v-if="baseInfo.card_cover_image" class="pictrue">
              <img v-lazy="baseInfo.card_cover_image" />
              <Button
                shape="circle"
                icon="md-close"
                class="btndel"
                @click.native="deleteImage"
              ></Button>
            </div>
            <div
              v-else
              class="upLoad acea-row row-center-wrapper"
              @click="modalPicTap('dan', 0)"
            >
              <Icon type="ios-camera-outline" size="26" />
            </div>
            <div class="tips ml-16">建议尺寸：700 * 360px</div>
          </div>
        </div>
        <div v-if="baseInfo.card_cover == 2 && colorList.length">
          <Poptip placement="bottom">
            <div
              class="color_input acea-row row-between row-middle pl-8 pr-8 rd-4px"
            >
              <div
                class="input_color w-18 h-18 rd-2px"
                :style="{
                  backgroundColor:
                    `${baseInfo.card_cover_color}` || `${colorList[0].value}`,
                }"
              ></div>
              <Icon type="ios-arrow-down" size="14" color="#808695" />
            </div>
            <div slot="content" class="color_box">
              <div
                class="color_link"
                :style="{ backgroundColor: `${item.value}` }"
                v-for="(item, index) in colorList"
                :key="index"
                @click.stop="colorChange(item.value)"
              ></div>
            </div>
          </Poptip>
        </div>
      </div>
    </FormItem>
  </div>
</template>

<script>
import { wechatCard } from '@/api/product';
export default {
  props: {
    baseInfo: {
      type: Object,
      default: () => ({}),
    },
  },
  data() {
    return {
      colorList: [],
    };
  },
  mounted() {
    this.getWechatCard();
  },
  methods: {
    getWechatCard() {
      wechatCard().then((res) => {
        this.colorList = res.data.color;
      });
    },
    // 选择的颜色
    colorChange(value) {
      this.$emit('colorChange', value);
    },
    modalPicTap() {
      this.$emit('modalPicTap', 'dan', 'card_cover_image');
    },
    coverChange(value) {
      this.$emit('coverChange', value);
      if (value == 2 && !this.baseInfo.card_cover_color) {
        this.colorChange(this.colorList[0].value);
      }
    },
    deleteImage() {
      this.$emit('deleteImage');
    },
  },
};
</script>

<style lang="less" scoped>
.card_box {
  width: 320px;
  height: 480px;
  border: 1px solid #e7e7eb;
  .img_box {
    width: 320px;
    height: 68px;
  }
  .img {
    width: 320px;
  }
  .card_wrap {
    width: 303px;
    height: 88px;
    border-radius: 7px;
    margin: 9px auto;
    background-position: center;
    background-repeat: no-repeat;
    background-size: cover;
  }
  .card {
    width: 303px;
    height: 88px;
    border-radius: 7px;
  }
  .cover {
    background-color: rgba(0, 0, 0, 0.3);
  }
}
.selst {
  margin-top: 20px;
  .color_input {
    width: 100px;
    height: 32px;
    background-color: #ffffff;
    border: 1px solid #dcdee2;
    cursor: pointer;
    .input_color {
      background-color: #0000ff;
    }
  }
}
.color_box {
  width: 140px;
  padding: 5px;
  display: flex;
  flex-wrap: wrap;
  align-content: space-around;
  justify-content: space-between;
  border: 1px solid #d9dadc;
  .color_link {
    width: 22px;
    height: 22px;
    margin: 2px 0px;
    background-color: #0000ff;
  }
}
.upLoad {
  width: 58px;
  height: 58px;
  line-height: 58px;
  border: 1px dotted rgba(0, 0, 0, 0.1);
  border-radius: 4px;
  background: rgba(0, 0, 0, 0.02);
  cursor: pointer;
}
.pictrue {
  width: 60px;
  height: 60px;
  border: 1px dotted rgba(0, 0, 0, 0.1);
  border-radius: 4px;
  margin-right: 15px;
  margin-bottom: 10px;
  display: inline-block;
  position: relative;
  cursor: pointer;
  img {
    width: 100%;
    height: 100%;
    border-radius: 4px;
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
.tips {
  font-size: 12px;
  font-weight: 400;
  color: #999999;
}
</style>