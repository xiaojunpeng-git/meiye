import Vue from 'vue';
import uploadImg from './uploadImg.vue';
let UploadImgConstructor = Vue.extend(uploadImg);
let instance;
let modalAll = window.top.document.querySelectorAll('.ivu-modal-wrap');
let zIndexAll = [1000];

const UploadImg = function (options) {
  options = options || {};
  instance = new UploadImgConstructor({
    data: options
  });
  instance.$mount();
  for (let i = 0; i < modalAll.length; i++) {
    if (modalAll[i].classList.contains('ivu-modal-hidden')) {
      continue;
    }
    zIndexAll.push(parseInt(modalAll[i].style.zIndex));
  }
  zIndexAll.sort((a, b) => {
    return b - a;
  });
  window.top.document.body.appendChild(instance.$el);
  instance.visible = true;
  instance.zIndex = zIndexAll[0]++;
  return instance;
};

const install = function (Vue, options) {
  Vue.prototype.$uploadImg = UploadImg;
};

export default {
  install
};