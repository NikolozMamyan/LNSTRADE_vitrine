const videoSource = (source) => {
  let url;
  try { url = new URL(source); } catch { return null; }
  if (url.protocol !== 'https:' || url.username || url.password) return null;
  const host = url.hostname;
  const path = url.pathname;
  if (['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com', 'youtu.be'].includes(host)) {
    const id = host === 'youtu.be' ? path.replace(/^\/+|\/+$/g, '') : url.searchParams.get('v') || path.replace(/^\/(embed|shorts)\//, '');
    if (/^[A-Za-z0-9_-]{11}$/.test(id)) return { type: 'youtube', id, url: `https://www.youtube-nocookie.com/embed/${id}?playsinline=1` };
  }
  const vimeo = path.match(/^\/(?:video\/)?([0-9]+)$/);
  if (['vimeo.com', 'www.vimeo.com', 'player.vimeo.com'].includes(host) && vimeo) return { type: 'embed', url: `https://player.vimeo.com/video/${vimeo[1]}` };
  const wistia = path.match(/^\/(?:medias|embed\/iframe)\/([a-z0-9]+)$/);
  if ((host === 'fast.wistia.net' || host.endsWith('.wistia.com')) && wistia) return { type: 'embed', url: `https://fast.wistia.net/embed/iframe/${wistia[1]}` };
  if (/\.(mp4|webm)$/i.test(path)) return { type: 'file', url: url.href };
  return null;
};

export const createVideoPlayer = (source, label = 'Vidéo du catalogue') => {
  const video = videoSource(source);
  if (!video) return null;
  const player = document.createElement(video.type === 'file' ? 'video' : 'iframe');
  player.src = video.url;
  if (video.type === 'file') {
    player.controls = true;
    player.playsInline = true;
    player.preload = 'metadata';
    player.setAttribute('aria-label', label);
  } else {
    player.title = label;
    player.allowFullscreen = true;
    player.allow = 'encrypted-media; fullscreen; picture-in-picture';
    player.referrerPolicy = 'strict-origin-when-cross-origin';
    player.loading = 'lazy';
  }
  return player;
};

export const createVideoPreview = (source, label) => {
  const video = videoSource(source);
  const preview = document.createElement('div');
  preview.className = 'catalog-editor-video-preview';
  if (!video) {
    preview.textContent = 'Ajoutez une adresse vidéo valide';
    return preview;
  }
  let media;
  if (video.type === 'youtube') {
    media = document.createElement('img');
    media.src = `https://i.ytimg.com/vi/${video.id}/hqdefault.jpg`;
    media.alt = label || 'Aperçu vidéo';
    media.draggable = false;
  } else {
    media = createVideoPlayer(source, label);
    if (video.type === 'file') { media.controls = false; media.muted = true; }
    else media.tabIndex = -1;
  }
  const play = document.createElement('span');
  play.className = 'catalog-editor-video-play';
  play.textContent = '▶';
  play.setAttribute('aria-hidden', 'true');
  preview.append(media, play);
  return preview;
};
