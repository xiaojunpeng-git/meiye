<template>
  <div class="cube-slides-editor" v-if="region">
    <draggable :value="frames" handle=".cube-move" @input="saveFrames">
      <div class="cube-frame" v-for="(frame, index) in frames" :key="index">
        <Icon class="cube-move" type="md-reorder" title="拖动排序" />
        <button class="cube-picture" type="button" @click="chooseImage(index)" :title="'选择图片 ' + (index + 1)">
          <img v-if="frame.image" :src="frame.image" alt="" />
          <Icon v-else type="ios-add" size="28" />
        </button>
        <div class="cube-fields">
          <Input :value="frame.link" placeholder="请输入链接" icon="ios-link"
            @input="updateFrame(index, 'link', $event)" @on-click="chooseLink(index)" />
          <Select :value="frame.objectFit" @on-change="updateFrame(index, 'objectFit', $event)">
            <Option value="cover">填充裁剪</Option><Option value="contain">完整显示</Option><Option value="fill">拉伸填充</Option>
          </Select>
        </div>
        <Button type="text" icon="ios-trash-outline" title="删除图片" @click="removeFrame(index)" />
      </div>
    </draggable>
    <Button long icon="md-add" @click="addFrame">添加图片</Button>
    <Modal v-model="modalPic" title="选择图片" width="960px" footer-hide :mask-closable="false">
      <uploadPictures v-if="modalPic" isChoice="单选" :gridBtn="gridBtn" :gridPic="gridPic" @getPic="receiveImage" />
    </Modal>
    <linkaddress ref="links" @linkUrl="receiveLink" />
  </div>
</template>

<script>
import draggable from 'vuedraggable';
import uploadPictures from '@/components/uploadPictures';
import linkaddress from '@/components/linkaddress';
import { cubeSlides, withCubeSlides } from '../../../../shared/diyCubeSlides';
export default {
  name: 'c_cube_slides',
  components: { draggable, uploadPictures, linkaddress },
  props: { configObj: { type: Object, required: true } },
  data() {
    return { modalPic: false, editingIndex: 0, editingRegion: null,
      gridBtn: { xl: 4, lg: 8, md: 8, sm: 8, xs: 8 }, gridPic: { xl: 6, lg: 8, md: 12, sm: 12, xs: 12 } };
  },
  computed: {
    regionIndex() { return this.configObj.picStyle ? this.configObj.picStyle.tabVal : 0; },
    region() { return this.configObj.picStyle && this.configObj.picStyle.picList[this.regionIndex]; },
    frames() { return cubeSlides(this.region, true); }
  },
  watch: {
    // An asynchronous image/link picker must never write into a newly selected region.
    regionIndex() { this.closePickers(); },
    'configObj.styleConfig.tabVal'() { this.closePickers(); }
  },
  methods: {
    closePickers() {
      this.modalPic = false;
      this.editingRegion = null;
      if (this.$refs.links) this.$refs.links.modals = false;
    },
    saveFrames(frames) {
      if (!this.region) return;
      const next = withCubeSlides(this.region, frames);
      this.$set(this.configObj.picStyle.picList, this.regionIndex, next);
      // Custom-grid geometry remains unchanged; keep its legacy thumbnail projection current.
      const doc = (this.configObj.picStyle.docPicList || [])[this.regionIndex];
      if (doc) { this.$set(doc, 'img', next.image); this.$set(doc, 'link', next.link); }
    },
    updateFrame(index, field, value) {
      const frames = this.frames;
      if (!frames[index]) return;
      frames[index][field] = value;
      this.saveFrames(frames);
    },
    addFrame() { this.saveFrames(this.frames.concat({ image: '', link: '', objectFit: 'cover' })); },
    removeFrame(index) { const frames = this.frames; frames.splice(index, 1); this.saveFrames(frames); },
    chooseImage(index) { this.editingIndex = index; this.editingRegion = this.region; this.modalPic = true; },
    receiveImage(image) {
      if (this.editingRegion === this.region) this.updateFrame(this.editingIndex, 'image', image.att_dir);
      this.modalPic = false;
    },
    chooseLink(index) { this.editingIndex = index; this.editingRegion = this.region; this.$refs.links.modals = true; },
    receiveLink(link) {
      if (this.editingRegion === this.region) this.updateFrame(this.editingIndex, 'link', link);
    }
  }
};
</script>

<style scoped>
.cube-slides-editor { margin: 0 15px 20px; }
.cube-frame { display: flex; align-items: center; gap: 8px; padding: 12px 4px; border-bottom: 1px solid #eee; min-width: 0; }
.cube-move { cursor: move; flex-shrink: 0; }
.cube-picture { width: 56px; height: 56px; padding: 0; flex-shrink: 0; border: 1px solid #ddd; background: #fff; cursor: pointer; }
.cube-picture img { width: 100%; height: 100%; object-fit: cover; }
.cube-fields { flex: 1; min-width: 0; display: grid; gap: 6px; }
.cube-slides-editor > button { margin-top: 12px; }
</style>
