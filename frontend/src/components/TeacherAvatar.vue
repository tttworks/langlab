<script setup>
/**
 * 虚拟老师形象 —— 形象渲染的唯一出口。
 *
 * 为什么要抽成组件：原来 SVG 骨架硬编码在 VoiceTeacher.vue 里，只能「换配色」，
 * 换不了「换一种形象」。抽出后新增形象类型只需在这里加一个分支，
 * 角色数据里用 `kind` 指定即可（见 data/teacherCharacters.js）。
 *
 * 支持的 kind：
 *   'svg'   —— 内置矢量形象（默认）。零素材、零版权风险、任意配色，随仓库走。
 *   'image' —— 图片形象（写实 / 插画 / 任意来源）。一张 idle 就能用，
 *              缺哪个状态就回退到 idle，再缺回退到第一张；状态靠 CSS 动效表达。
 *   'video' —— 动态形象（有肢体动作）。每个状态一段**无缝循环的静音短视频**，
 *              切换状态就切视频。这是「像数字人那样会动」的最务实做法：
 *              不引入 Live2D 运行时（授权 + 美术分层成本），任何来源的动态素材都能接。
 *              缺状态同样回退到 idle；`images.idle` 当海报图（也是 chip 缩略图）。
 * ⚠️ 视频必须是 **muted + loop + playsinline** 才能自动播放（浏览器策略），组件已处理。
 *
 * 状态：idle 待命 / listening 聆听 / thinking 思考 / speaking 说话
 */
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { HAIR_PATHS, BANG_PATHS, AVATAR_STATES } from '../data/teacherCharacters.js'

const props = defineProps({
  char: { type: Object, required: true },
  state: { type: String, default: 'idle' },
  /** 点一下触发 poke 事件（页面用来「重听上一句 / 停止朗读」） */
  clickable: { type: Boolean, default: true },
})
const emit = defineEmits(['poke'])

const kind = computed(() => (props.char && props.char.kind) || 'svg')
const st = computed(() => (AVATAR_STATES.includes(props.state) ? props.state : 'idle'))

const cssVars = computed(() => {
  const p = props.char.palette || {}
  return {
    '--hair': p.hair, '--hairHi': p.hairHi, '--skin': p.skin, '--eye': p.eye,
    '--cloth': p.cloth, '--cloth2': p.cloth2, '--acc': p.accent, '--blush': p.blush,
  }
})

/** 图片形象：精确状态 → idle → 任意一张 */
const imgSrc = computed(() => {
  const im = props.char.images || {}
  return im[st.value] || im.idle || Object.values(im).find(Boolean) || ''
})
/** 图片形象的主色（用于光晕、状态色）—— 没给就从 palette 里拿 */
const tint = computed(() => props.char.tint || (props.char.palette && props.char.palette.accent) || '#6366f1')
const hasImg = computed(() => kind.value === 'image' && !!imgSrc.value)

/* ---------------- 动态形象（kind: 'video'） ----------------
 * 每个状态一段无缝循环的静音短视频，切换状态就切视频。
 * 同时播放多段会白烧 CPU，所以只让当前那段在播，其余暂停。 */
const vidEls = ref({})
const setVidRef = (k, el) => { if (el) vidEls.value[k] = el; else delete vidEls.value[k] }
const vidStates = computed(() => {
  const v = (props.char && props.char.videos) || {}
  return AVATAR_STATES.filter((s) => !!v[s])
})
/** 当前该播哪段：优先精确状态，否则回退第一段（约定排序里 idle 在前） */
const activeVid = computed(() => (vidStates.value.includes(st.value) ? st.value : vidStates.value[0] || ''))
const hasVid = computed(() => kind.value === 'video' && vidStates.value.length > 0)
const poster = computed(() => (props.char.images && props.char.images.idle) || '')

/**
 * 透明背景形象（去背抠图）。
 * 卡片式形象是「图填满圆角矩形」；抠图式是「人物浮在页面上」——
 * 不画底、不加圆角、不裁切，而且通常比半身更长（到腰/到大腿）。
 * 素材带 alpha 通道即可，数据里标 `cutout: true`。
 */
const cutout = computed(() => !!props.char.cutout && (kind.value === 'image' || kind.value === 'video'))

function syncVideos() {
  for (const [k, el] of Object.entries(vidEls.value)) {
    if (!el) continue
    try {
      if (k === activeVid.value) { el.play().catch(() => {}) } else { el.pause() }
    } catch (e) { /* 自动播放被拦就停在首帧，仍有海报图兜底 */ }
  }
}
watch([activeVid, vidEls, () => props.char && props.char.id], () => nextTick(syncVideos))
onBeforeUnmount(() => { for (const el of Object.values(vidEls.value)) { try { el.pause() } catch (e) {} } })
</script>

<template>
  <div class="stage" :class="[st, kind, { clickable, cutout, noimg: (kind === 'image' || kind === 'video') && !hasImg && !hasVid }]"
       :style="{ '--tint': tint }"
       :title="char.role" @click="clickable && emit('poke')">
    <span class="ring r1"></span>
    <span class="ring r2"></span>

    <!-- 动态形象：每个状态一段静音循环短视频，只让当前那段在播 -->
    <template v-if="kind === 'video'">
      <template v-if="hasVid">
        <video v-for="s in vidStates" :key="s" :ref="(el) => setVidRef(s, el)"
               v-show="s === activeVid" class="clip" :src="char.videos[s]"
               :poster="poster" muted loop playsinline autoplay preload="auto" />
      </template>
      <img v-else-if="poster" class="portrait" :src="poster" :alt="char.name" draggable="false" />
      <div v-else class="ph">
        <span>缺视频</span>
        <code>{{ char.id }}</code>
      </div>
      <span class="wave w1"></span>
      <span class="wave w2"></span>
    </template>

    <!-- 图片形象：单张静图，状态靠 CSS 表达 -->
    <template v-else-if="kind === 'image'">
      <img v-if="hasImg" class="portrait" :src="imgSrc" :alt="char.name" draggable="false" />
      <div v-else class="ph">
        <span>缺图片</span>
        <code>{{ char.id }}</code>
      </div>
      <span class="wave w1"></span>
      <span class="wave w2"></span>
    </template>

    <!-- 内置矢量形象 -->
    <svg v-else viewBox="0 0 120 120" class="bot" :style="cssVars" :aria-label="char.name">
      <circle cx="60" cy="62" r="50" class="halo" />

      <!-- 肩 / 衣服 -->
      <path d="M24 118C26 100 40 93 60 93C80 93 94 100 96 118Z" fill="var(--cloth)" />
      <path d="M52 94L60 105L68 94" fill="none" stroke="var(--cloth2)" stroke-width="2.4"
            stroke-linejoin="round" />
      <!-- 脖子 -->
      <rect x="53" y="80" width="14" height="16" rx="6" fill="var(--skin)" />

      <!-- 头发：后层（长发/波波/马尾） -->
      <path :d="HAIR_PATHS[char.hair]" fill="var(--hair)" />
      <path v-if="char.hair === 'tail'" d="M86 46C98 52 100 74 92 92C88 80 86 62 86 46Z"
            fill="var(--hairHi)" opacity=".9" />
      <path d="M40 40C46 32 54 29 62 29" fill="none" stroke="var(--hairHi)" stroke-width="3"
            stroke-linecap="round" opacity=".65" />

      <!-- 脸 -->
      <ellipse cx="60" cy="60" rx="24" ry="25" fill="var(--skin)" />
      <!-- 刘海 -->
      <path :d="BANG_PATHS[char.hair]" fill="var(--hair)" />

      <!-- 眉毛 -->
      <path d="M45 50C48 47.5 53 47.5 56 49.5" fill="none" stroke="var(--eye)" stroke-width="1.6"
            stroke-linecap="round" opacity=".75" />
      <path d="M64 49.5C67 47.5 72 47.5 75 50" fill="none" stroke="var(--eye)" stroke-width="1.6"
            stroke-linecap="round" opacity=".75" />

      <!-- 眼睛（会眨 / 思考时眯） -->
      <g class="eyes">
        <ellipse cx="50" cy="62" rx="5.5" ry="6.6" fill="var(--eye)" />
        <ellipse cx="70" cy="62" rx="5.5" ry="6.6" fill="var(--eye)" />
        <circle cx="48" cy="59.5" r="1.9" fill="#fff" />
        <circle cx="68" cy="59.5" r="1.9" fill="#fff" />
        <circle cx="51.6" cy="64.6" r="1" fill="#fff" opacity=".55" />
        <circle cx="71.6" cy="64.6" r="1" fill="#fff" opacity=".55" />
        <path d="M43.5 57C46.5 53.5 53.5 53.5 56.5 57" fill="none" stroke="var(--eye)"
              stroke-width="1.7" stroke-linecap="round" />
        <path d="M63.5 57C66.5 53.5 73.5 53.5 76.5 57" fill="none" stroke="var(--eye)"
              stroke-width="1.7" stroke-linecap="round" />
      </g>

      <!-- 腮红 / 鼻 / 嘴 -->
      <ellipse cx="42" cy="70" rx="5" ry="3" fill="var(--blush)" />
      <ellipse cx="78" cy="70" rx="5" ry="3" fill="var(--blush)" />
      <ellipse cx="60" cy="69.5" rx="1.6" ry="1.2" fill="var(--eye)" opacity=".35" />
      <path class="lip" d="M53.5 75Q60 82.5 66.5 75Q60 78 53.5 75Z" fill="var(--eye)" />

      <!-- 配饰 -->
      <g v-if="char.acc === 'earring'">
        <circle cx="38" cy="73" r="2.6" fill="var(--acc)" />
        <circle cx="82" cy="73" r="2.6" fill="var(--acc)" />
      </g>
      <g v-else-if="char.acc === 'clip'">
        <path d="M78 30L92 34L79 39Z" fill="var(--acc)" />
        <circle cx="78" cy="34.5" r="2.4" fill="var(--acc)" />
      </g>
      <g v-else-if="char.acc === 'glasses'">
        <rect x="41" y="55" width="19" height="14" rx="5" fill="none" stroke="var(--acc)"
              stroke-width="1.6" opacity=".85" />
        <rect x="60" y="55" width="19" height="14" rx="5" fill="none" stroke="var(--acc)"
              stroke-width="1.6" opacity=".85" />
        <path d="M60 61H41M60 61H79" stroke="var(--acc)" stroke-width="1.4" opacity=".85" />
      </g>
    </svg>
  </div>
</template>

<style scoped>
/* ---------- 通用舞台 ----------
   尺寸按形象类型给，切换角色时有 0.25s 过渡，不会突兀地跳。 */
.stage { position: relative; width: 176px; height: 220px; margin: 0 auto;
         display: grid; place-items: center;
         transition: width .25s ease, height .25s ease; }
.stage.clickable { cursor: pointer; }
/* 抠图式形象：到腰/到大腿，需要更高的竖版（3:4） */
.stage.cutout { width: 190px; height: 285px; }   /* 2:3，与素材比例一致 → 零裁切 */

/* ---------- 内置矢量形象 ----------
   形象本身没变，只是舞台从 158 方框改成 176×220 竖版（为了给半身像留同一块地方，
   切换角色时下面的文字不会跳动）。 */
.bot { width: 176px; height: 176px; animation: float 4.5s ease-in-out infinite; }
@keyframes float { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-5px); } }
.halo { fill: rgba(59,130,246,.10); }
.stage.thinking .eyes { animation: none; transform: scaleY(.32); }
.stage.thinking .halo { fill: rgba(245,158,11,.16); }
/* 说话：嘴开合 */
.stage.speaking .lip { animation: talk .4s infinite alternate; transform-origin: 60px 76px; }
@keyframes talk { from { transform: scaleY(.45); } to { transform: scaleY(2.2); } }

/* ---------- 图片形象：竖版半身卡（4:5） ----------
   ⚠️ 原来是 border-radius:50% 的圆形裁切 —— 那等于强制「头部特写」，跟「半身像」冲突。
   现在改成圆角矩形卡片，图片按 object-fit:cover 填满；出图请用竖版、胸像以上构图。
   写实图做不了逐帧口型，状态靠光效与呼吸表达。 */
.stage.image .portrait { width: 100%; height: 100%; object-fit: cover; object-position: 50% 22%;
  border-radius: 16px; background: var(--surface-2, #f3f4f6);
  animation: float 5s ease-in-out infinite;
  box-shadow: 0 10px 26px rgba(0,0,0,.16);
  transition: filter .3s, box-shadow .3s; user-select: none; }
/* 说话：呼吸式缩放 */
.stage.image.speaking .portrait { animation: breathe .75s ease-in-out infinite alternate; }
@keyframes breathe { from { transform: scale(1); } to { transform: scale(1.025); } }
/* 思考：轻降饱和 + 描边转暖 */
.stage.image.thinking .portrait { filter: saturate(.75) brightness(.97);
  box-shadow: 0 10px 26px rgba(0,0,0,.16), 0 0 0 3px rgba(245,158,11,.45); }
/* 聆听：红色呼吸描边 */
.stage.image.listening .portrait { box-shadow: 0 10px 26px rgba(0,0,0,.16), 0 0 0 3px rgba(220,38,38,.5);
  animation: float 5s ease-in-out infinite, glow 1.4s ease-in-out infinite; }
@keyframes glow { 0%,100% { box-shadow: 0 10px 26px rgba(0,0,0,.16), 0 0 0 3px rgba(220,38,38,.35); }
                  50% { box-shadow: 0 10px 26px rgba(0,0,0,.16), 0 0 0 6px rgba(220,38,38,.16); } }
/* 说话：底部声波（从卡内向上扩散） */
.stage.image .wave { position: absolute; left: 50%; bottom: 14px; width: 64px; height: 64px;
  margin-left: -32px; border-radius: 50%; border: 2px solid var(--tint, #6366f1); opacity: 0;
  pointer-events: none; }
.stage.image.speaking .wave { animation: ripple 1.4s ease-out infinite; }
.stage.image.speaking .w2 { animation-delay: .7s; }
/* 缺图时的占位（不会白屏，也不报错） */
.stage.image .ph { width: 100%; height: 100%; border-radius: 16px; display: grid; place-content: center;
  gap: 4px; background: var(--surface-2, #f3f4f6); border: 2px dashed rgba(0,0,0,.15);
  color: #9ca3af; font-size: 12px; text-align: center; }
.stage.image .ph code { font-size: 10.5px; color: #6b7280; }

/* ---------- 抠图式形象（透明背景，人物浮在页面上）----------
   没有底板、没有圆角、不裁切 —— 所以素材本身必须是带 alpha 的去背图，
   而且高宽比最好就是 3:4（= 舞台比例），否则会被 contain 留白。 */
.stage.cutout .portrait, .stage.cutout .clip {
  width: 100%; height: 100%; object-fit: contain; object-position: 50% 100%;
  border-radius: 0; background: none; box-shadow: none;
  /* 抠图没有底，用一层极淡的投影让它在浅色页面上"站得住" */
  filter: drop-shadow(0 8px 16px rgba(0,0,0,.14)); }
.stage.cutout .portrait { animation: float 6s ease-in-out infinite; }
.stage.cutout.speaking .portrait { animation: breathe .75s ease-in-out infinite alternate; }
.stage.cutout.thinking .portrait { filter: drop-shadow(0 8px 16px rgba(0,0,0,.14)) saturate(.75) brightness(.97); }
.stage.cutout.listening .portrait { filter: drop-shadow(0 0 0 rgba(0,0,0,0)) drop-shadow(0 8px 16px rgba(0,0,0,.14))
  drop-shadow(0 0 10px rgba(220,38,38,.55)); animation: float 6s ease-in-out infinite; }
.stage.cutout .wave { bottom: 34px; }
.stage.cutout .ph { border-radius: 16px; }

/* ---------- 动态形象：与图片共用卡片外观 ---------- */
.stage.video .clip, .stage.video .portrait { width: 100%; height: 100%; object-fit: cover;
  object-position: 50% 22%; border-radius: 16px; background: var(--surface-2, #f3f4f6);
  box-shadow: 0 10px 26px rgba(0,0,0,.16); user-select: none; pointer-events: none; }
.stage.video .clip { display: block; }
.stage.video.thinking .clip { filter: saturate(.75) brightness(.97);
  box-shadow: 0 10px 26px rgba(0,0,0,.16), 0 0 0 3px rgba(245,158,11,.45); }
.stage.video.listening .clip { box-shadow: 0 10px 26px rgba(0,0,0,.16), 0 0 0 3px rgba(220,38,38,.5);
  animation: glow 1.4s ease-in-out infinite; }
.stage.video.speaking .wave { animation: ripple 1.4s ease-out infinite; }
.stage.video.speaking .w2 { animation-delay: .7s; }
.stage.video .wave { position: absolute; left: 50%; bottom: 14px; width: 64px; height: 64px;
  margin-left: -32px; border-radius: 50%; border: 2px solid var(--tint, #6366f1); opacity: 0;
  pointer-events: none; }
/* 动态形象的声波：只跟随说话状态，其余状态用循环视频本身的动感 */
.stage.video:not(.speaking) .wave { display: none; }
.stage.video .ph { width: 100%; height: 100%; border-radius: 16px; display: grid; place-content: center;
  gap: 4px; background: var(--surface-2, #f3f4f6); border: 2px dashed rgba(0,0,0,.15);
  color: #9ca3af; font-size: 12px; text-align: center; }
.stage.video .ph code { font-size: 10.5px; color: #6b7280; }

/* ---------- 状态扩散环：只给矢量形象用 ----------
   图片是矩形卡片，圆环套上去会溢出，所以图片用描边 + 光晕表达状态。 */
.ring { display: none; }
.stage.svg .ring { display: block; position: absolute; left: 50%; top: 50%;
  width: 168px; height: 168px; margin: -84px 0 0 -84px;
  border-radius: 50%; border: 2px solid rgba(220,38,38,.45); opacity: 0; pointer-events: none; }
.stage.svg.listening .ring { animation: ripple 1.6s ease-out infinite; }
.stage.svg.listening .r2 { animation-delay: .8s; }
.stage.svg.listening .halo { fill: rgba(220,38,38,.12); }
@keyframes ripple { 0% { transform: scale(.85); opacity: .8; } 100% { transform: scale(1.35); opacity: 0; } }
</style>
