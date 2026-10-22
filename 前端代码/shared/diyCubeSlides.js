// The legacy image/link remain a first-frame projection; slides own multi-image content.
// Reading old decoration JSON never mutates it or invents a second save source.
export function cubeSlides(region, includeEmpty = false) {
  const value = region || {};
  const source = Array.isArray(value.slides) ? value.slides : [{
    image: value.image || value.img || '', link: value.link || '', objectFit: value.objectFit || 'cover'
  }];
  return source.filter(item => item && (includeEmpty || item.image)).map(item => ({
    image: typeof item.image === 'string' ? item.image : '',
    link: typeof item.link === 'string' ? item.link : '',
    objectFit: ['cover', 'contain', 'fill'].includes(item.objectFit) ? item.objectFit : 'cover'
  }));
}

export function withCubeSlides(region, slides) {
  const frames = cubeSlides({ slides }, true);
  const first = frames.find(frame => frame.image) || frames[0] || { image: '', link: '', objectFit: 'cover' };
  return Object.assign({}, region, first, { slides: frames });
}
