import Vue from 'vue';
import Main from './main.vue';

const UploadImgConstructor = Vue.extend(Main);
let instance;
const zIndexList = [];
const modalList = window.top.document.querySelectorAll('.ivu-modal-wrap');

const UploadImg = function(options) {
  options = options || {};
  instance = new UploadImgConstructor({
    data: options
  });
  instance.$mount();
  for (let index = 0, len = modalList.length; index < len; index++) {
    if (modalList[index].classList.contains('ivu-modal-hidden')) {
      continue;
    }
    zIndexList.push(parseInt(modalList[index].style.zIndex));
  }
  zIndexList.sort((a, b) => {
    return b - a;
  });
  window.top.document.body.appendChild(instance.$el);
  instance.visible = true;
  instance.zIndex = zIndexList[0]++;
  return instance;
};

export default UploadImg;
