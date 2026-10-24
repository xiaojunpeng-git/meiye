#!/bin/sh
# 只补齐成长媒体依赖；保留包清单与源码签名证据，不升级已有业务服务或改客户配置。
set -eu
release_dir=${1:?release backup directory required}
case "$release_dir" in /www/backups/rh/20261023-growth-*|/www/backups/008/20261023-growth-*) ;; *) exit 2;; esac
cd "$release_dir/dependencies"
rpm -qa | sort > packages-before.txt
yum -y install libreoffice-headless libreoffice-writer libreoffice-impress poppler-utils wqy-zenhei-fonts > packages-install.log 2>&1
rpm -qa | sort > packages-after.txt
if ! command -v ffprobe >/dev/null 2>&1; then
  mkdir -p gnupg
  chmod 700 gnupg
  gpg --homedir "$PWD/gnupg" --batch --import ffmpeg-devel.asc > signature.log 2>&1
  gpg --homedir "$PWD/gnupg" --with-colons --fingerprint FCF986EA15E6E293A5644F10B4322F04D67658D8 | grep -q 'FCF986EA15E6E293A5644F10B4322F04D67658D8'
  gpg --homedir "$PWD/gnupg" --batch --verify ffmpeg-4.4.8.tar.xz.asc ffmpeg-4.4.8.tar.xz >> signature.log 2>&1
  tar -xf ffmpeg-4.4.8.tar.xz
  cd ffmpeg-4.4.8
  ./configure --prefix=/opt/mohe-growth-media --disable-everything --disable-programs --enable-ffprobe --enable-demuxer=mov,matroska,avi --enable-protocol=file --disable-doc --disable-network --disable-x86asm --disable-autodetect > ../configure.log 2>&1
  make -j2 ffprobe > ../compile.log 2>&1
  install -d /opt/mohe-growth-media/bin
  install -m 0755 ffprobe /opt/mohe-growth-media/bin/ffprobe
  test ! -e /usr/local/bin/ffprobe
  ln -s /opt/mohe-growth-media/bin/ffprobe /usr/local/bin/ffprobe
  cd ..
fi
command -v soffice
command -v pdfinfo
command -v ffprobe
soffice --headless --version
ffprobe -version | head -1
