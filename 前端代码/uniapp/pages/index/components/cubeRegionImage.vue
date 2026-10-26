<template>
  <view class="cube-region-image" :style="{ borderRadius }">
    <easy-loadimage :mode="mode" :width="width" :height="height" :image-src="firstImage"
      :borderRadius="borderRadius" :style="{ visibility: slides.length > 1 ? 'hidden' : 'visible' }" />
    <swiper v-if="slides.length > 1" class="cube-region-swiper" :autoplay="true" :circular="true" :interval="3000" :duration="350">
      <swiper-item v-for="(slide, index) in slides" :key="index" @click.stop="navigate(slide)">
        <easy-loadimage class="cube-region-frame" :image-src="slide.image" :mode="slideMode(slide)"
          width="100%" height="100%" :borderRadius="borderRadius" />
      </swiper-item>
    </swiper>
  </view>
</template>

<script>
import { cubeSlides } from '../../../../shared/diyCubeSlides';
export default {
  name: 'cubeRegionImage',
  props: {
    region: { type: Object, default: () => ({}) }, mode: { type: String, default: 'aspectFill' },
    width: { type: String, default: '100%' }, height: { type: String, default: 'auto' },
    borderRadius: { type: String, default: '0' }
  },
  computed: {
    slides() { return cubeSlides(this.region); },
    firstImage() { return this.slides.length ? this.slides[0].image : ''; }
  },
  methods: {
    // The first image anchors the existing layout. Later frames only fill that box.
    slideMode(slide) { return { cover: 'aspectFill', contain: 'aspectFit', fill: 'scaleToFill' }[slide.objectFit]; },
    navigate(slide) { this.$emit('navigate', slide); }
  }
};
</script>

<style scoped>
.cube-region-image { position: relative; width: 100%; height: 100%; min-width: 0; overflow: hidden; }
.cube-region-swiper { position: absolute; top: 0; left: 0; width: 100%; height: 100%; }
.cube-region-frame { display: block; width: 100%; height: 100%; }
</style>
