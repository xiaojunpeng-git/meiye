<template>
  <img v-if="slides.length < 2" :src="firstImage" />
  <div v-else class="cube-preview-image">
    <img class="cube-preview-anchor" :src="firstImage" />
    <swiper class="cube-preview-swiper" :options="options" :key="signature">
      <swiper-slide v-for="(slide, index) in slides" :key="index">
        <img :src="slide.image" :style="{ objectFit: slide.objectFit }" />
      </swiper-slide>
    </swiper>
  </div>
</template>

<script>
import { swiper, swiperSlide } from 'vue-awesome-swiper';
import 'swiper/dist/css/swiper.css';
import { cubeSlides } from '../../../../shared/diyCubeSlides';
export default {
  name: 'cubePreviewImage',
  components: { swiper, swiperSlide },
  props: { region: { type: Object, default: () => ({}) } },
  data() { return { options: { loop: true, autoplay: { delay: 3000, disableOnInteraction: false } } }; },
  computed: {
    slides() { return cubeSlides(this.region); },
    firstImage() { return this.slides.length ? this.slides[0].image : ''; },
    // Restart only this region when its images change, not another region's preview.
    signature() { return this.slides.map(frame => frame.image).join('|'); }
  }
};
</script>

<style scoped>
.cube-preview-image { position: relative; min-width: 0; max-width: 100%; overflow: hidden; }
.cube-preview-anchor { width: 100%; height: 100%; visibility: hidden; object-fit: inherit; }
.cube-preview-image > .cube-preview-swiper { position: absolute; top: 0; left: 0; width: 100%; height: 100%; }
.cube-preview-swiper img { display: block; width: 100%; height: 100%; }
</style>
