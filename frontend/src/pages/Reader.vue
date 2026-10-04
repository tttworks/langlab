<script setup>
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount, inject } from 'vue'
import OriginalReader from '../components/OriginalReader.vue'
import TtsPanel from '../components/TtsPanel.vue'
import { useSpeech } from '../useSpeech.js'
import { useTtsSettings } from '../useTtsSettings.js'

const api = inject('api')
const state = inject('state')
const say = inject('say')
const props = defineProps({ route: Object, go: Function })

const MID = computed(() => Number(props.route.args[0] || 0))
const m = ref(null)
const segs = ref([])
const anns = ref([])
const ep = ref(null)        // 剧集上下文（非剧集素材为 null）：剧名/季/集 + 上一集/下一集
const loading = ref(true)
const wrap = ref(null)

/* ---------------- 落地页（不带素材 ID 进阅读器时） ----------------
 * 阅读器以前只能从别处带素材 ID 进来（素材库「阅读」/ 卡片「回到原文」/ 剧集「文本精读」），
 * 侧栏没有入口；直接访问 #/reader 会报「素材不存在」。这里补一个可用的落地页。
 */
const landing = ref(false)
const mats = ref([])
const matLoading = ref(false)
const LAST_KEY = 'langlab.lastReader'
/** 上次读的那一篇（供「接着读」） */
const lastId = ref(Number((() => { try { return localStorage.getItem(LAST_KEY) || 0 } catch (e) { return 0 } })()))
const lastMat = computed(() => mats.value.find((x) => x.id === lastId.value) || null)
const progOf = (x) => (x.progress && x.progress.read_seq) || 0
const pctOf = (x) => (x.segment_count ? Math.round(progOf(x) / x.segment_count * 100) : 0)
/** 正在读的排最前，其余保持接口顺序（最近更新在前） */
const matsSorted = computed(() => {
  const a = mats.value.slice()
  a.sort((x, y) => (y.id === lastId.value ? 1 : 0) - (x.id === lastId.value ? 1 : 0))
  return a
})

async function loadLanding() {
  matLoading.value = true
  try {
    const r = await api.materials({ lang: state.lang, course: state.course })
    mats.value = r.materials || []
  } catch (e) { say(e.message) }
  matLoading.value = false
}
// 落地页停留时切换语言/课程 → 列表跟着换
watch(() => [state.lang, state.course], () => { if (landing.value) loadLanding() })

// 精读 / 原版 双模式：原版只有 EPUB / PDF 才有
const mode = ref('intensive')
const canOriginal = computed(() => !!(m.value && m.value.has_file && ['epub', 'pdf'].includes(String(m.value.type))))
const chapters = computed(() => {
  const out = []
  segs.value.forEach((s) => {
    const c = s.chapter || ''
    if (!out.length || out[out.length - 1].name !== c) out.push({ name: c, segs: [] })
    out[out.length - 1].segs.push(s)
  })
  return out
})

// 渐进渲染：一本 6000 段的书不能一次性塞进 DOM，滚到底再续
const PAGE = 300
const visibleCount = ref(PAGE)
const shownChapters = computed(() => {
  const out = []
  let n = 0
  for (const ch of chapters.value) {
    if (n >= visibleCount.value) break
    const take = ch.segs.slice(0, visibleCount.value - n)
    if (!take.length) break
    out.push({ name: ch.name, segs: take })
    n += take.length
  }
  return out
})
const hasMore = computed(() => visibleCount.value < segs.value.length)
const moreEl = ref(null)
let io = null
function bump() { visibleCount.value = Math.min(segs.value.length, visibleCount.value + PAGE * 2) }
function setupObserver() {
  if (io) { io.disconnect(); io = null }
  if (!moreEl.value || typeof IntersectionObserver === 'undefined') return
  io = new IntersectionObserver((es) => {
    if (es.some((e) => e.isIntersecting) && hasMore.value) bump()
  }, { rootMargin: '600px' })
  io.observe(moreEl.value)
}
watch([hasMore, moreEl], () => setupObserver())

/* ---------------- 语音阅读 ---------------- */
const speakFollow = ref(localStorage.getItem('langlab.speakFollow') !== '0')
watch(speakFollow, (v) => { try { localStorage.setItem('langlab.speakFollow', v ? '1' : '0') } catch (e) {} })

/* 语音引擎/音色设置统一走 useTtsSettings —— 阅读器与卡片页共用同一份，
   在一个页面换了引擎，另一个页面立刻跟着变（不会再出现两套设置） */
const ttsCfg = useTtsSettings()
const {
  conf: ttsConf, providers, curProvider, curProviderInfo, voiceScope, setScope,
  fellBack, fellBackReason, noteFallback, ensureVoices, entryFor,
  voiceFor, setVoice, voiceInfo, shownGroups: sharedGroups, hiddenGroupCount: sharedHidden,
  loadProviders,
} = ttsCfg

const LANGCODE = () => (m.value ? m.value.lang_code : 'en-US')
const LANGSHORT = () => ttsCfg.langShort(LANGCODE())
const curVoice = computed(() => voiceFor(LANGCODE()))
const serverVoices = computed(() => entryFor(LANGCODE()).voices || [])
const shownGroups = computed(() => sharedGroups(LANGCODE()))
const hiddenGroupCount = computed(() => sharedHidden(LANGCODE()))
const voicesLoading = computed(() => !!entryFor(LANGCODE()).loading)

function setServerVoice(id) { setVoice(LANGCODE(), id) }
async function loadServerVoices() { await ensureVoices(LANGCODE()) }


// 解构出来用：这样的 ref 在模板里会自动解包，不用写 .value
const {
  supported: ttsOk,
  error: ttsError,
  hint: ttsHint,
  loadingAudio,
  voices: ttsVoices,
  voiceURI: ttsVoiceURI,
  rate: ttsRate,
  currentId: speakId,
  currentIndex: speakIdx,
  chunkIndex: speakChunk,
  chunkTotal: speakChunks,
  charFrom: speakFrom2,
  charTo: speakTo2,
  curWord: speakWord,
  playing: speaking,
  paused: speakPaused,
  setVoice: setTtsVoice,
  setRate: setTtsRate,
  play: ttsPlay,
  next: ttsNext,
  prev: ttsPrev,
  pause: ttsPause,
  resume: ttsResume,
  stop: ttsStop,
} = useSpeech({
  lang: () => (m.value ? m.value.lang_code : 'en-US'),
  provider: () => curProvider.value,
  voice: () => (curProvider.value === 'browser' ? ttsVoiceURI.value : curVoice.value),
  needMoreAt: () => visibleCount.value - 20,
  onNeedMore: () => { if (hasMore.value) bump() },
})

// 服务端引擎失败 → 自动回退浏览器内置，并把当前这一段用内置语音重读一遍（不让用户手动重按）
watch(ttsError, (e) => {
  if (!e || curProvider.value === 'browser' || fellBack.value) return
  const idx = speakIdx.value
  noteFallback(e, ttsHint.value)
  say('在线语音失败，已临时切回浏览器内置：' + e)
  setTimeout(() => {
    if (idx >= 0 && segs.value.length) ttsPlay(speechList.value, idx, true)
  }, 60)
})

// 只朗读正文，不含段号
const speechList = computed(() => segs.value.map((s) => ({ id: s.id, text: s.text })))

function ensureRendered(i) {
  if (i >= visibleCount.value) visibleCount.value = Math.min(segs.value.length, i + PAGE)
}
function firstVisibleIndex() {
  const rows = wrap.value ? wrap.value.querySelectorAll('.seg') : []
  const mid = window.scrollY + window.innerHeight * 0.35
  let idx = 0
  rows.forEach((el, i) => {
    if (el.getBoundingClientRect().top + window.scrollY < mid) idx = i
  })
  return idx
}
/** 读单段（不往下连） */
function speakSeg(s) {
  ttsPlay([{ id: s.id, text: s.text }], 0, false)
}
/** 从这一段往下连读 */
function speakFrom(s) {
  const i = segs.value.findIndex((x) => x.id === s.id)
  if (i < 0) return
  ensureRendered(i)
  ttsPlay(speechList.value, i, true)
}
/** 工具条主按钮：朗读 / 暂停 / 继续 */
function speakToggle() {
  if (speaking.value) { ttsPause(); return }
  if (speakPaused.value) { ttsResume(); return }
  const i = speakIdx.value >= 0 ? speakIdx.value : firstVisibleIndex()
  ensureRendered(i)
  ttsPlay(speechList.value, i, true)
}
/** 段落上的喇叭：读这一段；正在读就停 */
function segToggle(s) {
  if (speakId.value === s.id && speaking.value) { ttsStop(); return }
  speakSeg(s)
}

// 跟着朗读滚动
watch(speakId, (id) => {
  if (!id || !speakFollow.value) return
  const el = document.querySelector('.seg.playing')
  if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' })
})

const RATES = [0.7, 0.85, 1, 1.15, 1.3]
function voiceLabel(v) {
  return (v.name || v.voiceURI || '').replace(/^Microsoft\s+/, '').replace(/\s*-\s*.*$/, '')
}

/* ---------------- 跟读横线 ----------------
   刻意不走 Vue 响应式：charTo 每帧都在变，走响应式会把整屏段落（可能 300 段）重渲染一遍。
   这里用一个 rAF 循环直接改几个元素的 style —— 元素只挂在「正在朗读的那一段」上。 */
const MAX_PL = 8       // 已读进度线最多铺几行（换行时一行一个）
const MAX_WL = 4       // 当前词的线

function locate(root, index) {
  const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT)
  let acc = 0
  let n
  while ((n = walker.nextNode())) {
    const len = n.nodeValue.length
    if (index <= acc + len) return { node: n, offset: index - acc }
    acc += len
  }
  return null
}

/** 取 [from, to) 的可见矩形（按视觉行拆开，处理换行） */
function rectsFor(root, from, to) {
  if (to <= from) return []
  const a = locate(root, Math.floor(from))
  const b = locate(root, Math.ceil(to))
  if (!a || !b) return []
  const r = document.createRange()
  try {
    r.setStart(a.node, a.offset)
    r.setEnd(b.node, b.offset)
  } catch (e) { return [] }
  return Array.from(r.getClientRects())
    // 换行处会切出一个宽不到 1px 的碎片，画出来就是一个多余的小点，滤掉
    .filter((x) => x.width > 1.2 && x.height > 1)
    .map((x) => ({ left: x.left, top: x.top, width: x.width, height: x.height, bottom: x.bottom }))
}

/** 分数位置：整数部分取完整字符，小数部分按比例缩短最后一个字符的矩形 —— 线走得才顺 */
function rectsUpTo(root, from, to) {
  const cut = Math.floor(to)
  let rects = rectsFor(root, from, cut)
  const frac = to - cut
  if (frac > 0.002) {
    const one = rectsFor(root, cut, cut + 1)
    // 换行刚好卡在这个字符上时，缩短后的尾巴会变成下一行开头的一个小点 —— 太短就不要了
    if (one.length && one[0].width * frac >= 1.5) {
      rects = rects.concat([{ ...one[0], width: one[0].width * frac }])
    }
  }
  return rects
}

function paintRects(nodes, rects, base, height) {
  nodes.forEach((el, i) => {
    const r = rects[i]
    if (!r) { el.style.display = 'none'; return }
    el.style.display = 'block'
    el.style.left = (r.left - base.left) + 'px'
    el.style.top = (r.bottom - base.top) + 'px'
    el.style.width = r.width.toFixed(1) + 'px'
    el.style.height = height + 'px'
  })
}

let lineRaf = 0
function loopLine() {
  lineRaf = requestAnimationFrame(loopLine)
  // 直接从 DOM 找：这是 v-for 里的元素，用模板 ref 会拿到数组，反而更绕
  const root = wrap.value || document
  const host = root.querySelector('.seg.playing .speakline')
  if (!host) return
  const seg = host.closest('.seg')
  const tx = seg && seg.querySelector('.tx')
  if (!tx) return
  const base = host.getBoundingClientRect()
  const pl = host.querySelectorAll('.pl')
  const wl = host.querySelectorAll('.wl')

  const from = speakFrom2.value
  const to = speakTo2.value
  if (from < 0 || to < from) {
    paintRects(pl, [], base, 2)
    paintRects(wl, [], base, 2)
    return
  }
  paintRects(pl, rectsUpTo(tx, from, to).slice(0, MAX_PL), base, 2)
  const w = speakWord
  if (w.from >= 0 && w.to > w.from) {
    paintRects(wl, rectsFor(tx, w.from, w.to).slice(0, MAX_WL), base, 3)
  } else {
    paintRects(wl, [], base, 3)
  }
}

function startLine() { if (!lineRaf) lineRaf = requestAnimationFrame(loopLine) }
function stopLine() { if (lineRaf) { cancelAnimationFrame(lineRaf); lineRaf = 0 } }

watch(speaking, (on) => { on ? startLine() : stopLine() })
watch(speakId, () => { if (speaking.value) startLine() })

/* 划词浮层 */
const pop = reactive({ show: false, x: 0, y: 0, text: '', segSeq: 0, segId: 0, start: 0, end: 0, note: '', gloss: '' })

function segParts(seg) {
  const list = anns.value.filter((a) => a.segment_id === seg.id && a.start_off !== null && a.end_off !== null)
    .sort((a, b) => a.start_off - b.start_off)
  if (!list.length) return [{ t: seg.text, a: null }]
  const out = []
  let pos = 0
  list.forEach((a) => {
    if (a.start_off > pos) out.push({ t: seg.text.slice(pos, a.start_off), a: null })
    out.push({ t: seg.text.slice(a.start_off, a.end_off), a })
    pos = a.end_off
  })
  if (pos < seg.text.length) out.push({ t: seg.text.slice(pos), a: null })
  return out
}

/* ============ v2 法律限定词高亮 ============
   依据：他扫读速度 762–914 wpm（属 skim），**扫读会自动跳过限定词**——
   而那些词恰恰决定责任边界（一条 may 变 shall、加不加 materially）。
   所以把限定词做成可见的靶子：点开看"它在这一句里锁住了什么"。 */
const qualOn = ref(false)
const QUALIFIERS = [
  { cat: '义务强度', re: /\b(shall|may|must|will|is entitled to|are entitled to)\b/gi,
    tip: 'shall=必须｜may=可以（许可，不强制）｜must=必须（外部要求）。一条 may 变 shall，义务就锁死了。' },
  { cat: '重要性门槛', re: /\b(materially|material adverse effect|material|de minimis)\b/gi,
    tip: 'materially / material =「重大地」。加不加它，决定小的违约算不算违约。' },
  { cat: '努力程度', re: /\b(best efforts|reasonable efforts|commercially reasonable efforts|to the extent practicable)\b/gi,
    tip: 'best efforts ＞ reasonable efforts ＞ commercially reasonable efforts。差一级，义务差很多。' },
  { cat: '例外与保留', re: /\b(provided that|without prejudice to|subject to|notwithstanding)\b/gi,
    tip: 'provided that=但…｜subject to=受…限制｜notwithstanding=尽管有…（它后面的优先）。' },
  { cat: '时间与条件', re: /\b(upon|prior to|no later than|from time to time|forthwith)\b/gi,
    tip: 'upon=一经…即刻｜prior to=之前｜no later than=不迟于（硬期限）｜forthwith=立即。' },
  { cat: '责任边界', re: /\b(indemnify|hold harmless|in no event shall|without limitation)\b/gi,
    tip: 'indemnify / hold harmless = 赔偿并使免于受损（你要掏钱的那一类）。' },
]
function qualParts(text) {
  if (!qualOn.value || !text) return [{ t: text, q: null }]
  const hits = []
  QUALIFIERS.forEach((g) => {
    const re = new RegExp(g.re.source, 'gi')   // 每次新建：避免 /g 的 lastIndex 污染
    let m
    while ((m = re.exec(text)) !== null) {
      hits.push({ start: m.index, end: m.index + m[0].length, q: g })
      if (m.index === re.lastIndex) re.lastIndex++
    }
  })
  if (!hits.length) return [{ t: text, q: null }]
  hits.sort((a, b) => a.start - b.start || (b.end - b.start) - (a.end - a.start))
  const kept = []
  let cur = 0
  hits.forEach((h) => { if (h.start >= cur) { kept.push(h); cur = h.end } })
  const out = []
  let pos = 0
  kept.forEach((h) => {
    if (h.start > pos) out.push({ t: text.slice(pos, h.start), q: null })
    out.push({ t: text.slice(h.start, h.end), q: h.q })
    pos = h.end
  })
  if (pos < text.length) out.push({ t: text.slice(pos), q: null })
  return out
}
const qualStats = computed(() => {
  const c = {}
  segs.value.forEach((s) => {
    qualParts(s.text).forEach((p) => { if (p.q) c[p.q.cat] = (c[p.q.cat] || 0) + 1 })
  })
  return c
})

async function load() {
  // 不带素材 ID 直接进阅读器（侧栏「阅读」入口）→ 给落地页，而不是报「素材不存在」
  if (!MID.value) {
    landing.value = true
    m.value = null; segs.value = []; anns.value = []; ep.value = null
    await loadLanding()
    loading.value = false
    return
  }
  landing.value = false
  loading.value = true
  try {
    const r = await api.material(MID.value)
    m.value = r.material
    segs.value = r.segments
    anns.value = r.annotations
    ep.value = r.episode || null
    // 记住这一篇，供落地页的「接着读」用
    try { localStorage.setItem(LAST_KEY, String(MID.value)) } catch (e) {}
    lastId.value = MID.value
    // 模式：URL 里带了就用 URL 的；否则 EPUB/PDF 默认开原版，其余默认精读
    const want = props.route.args[1]
    const openable = r.material.has_file && ['epub', 'pdf'].includes(String(r.material.type))
    mode.value = want === 'original' || want === 'intensive'
      ? want
      : (openable ? 'original' : 'intensive')
    visibleCount.value = Math.max(300, Math.min(r.segments.length, 300))
    setTimeout(setupObserver, 60)
    if (curProvider.value !== 'browser') loadServerVoices()
  } catch (e) { say(e.message) }
  loading.value = false
}

function setMode(m) {
  mode.value = m
  // 写进 hash，方便回链（刷新/前进后退也能保持）
  location.hash = '#/reader/' + MID.value + '/' + m
}

// 同剧同季换集（剧集素材才有）
function openEp(id) { if (id) props.go('reader', id) }
function backToSeries() {
  if (ep.value && m.value) props.go('shows', m.value.course_id, ep.value.series + '|' + ep.value.season)
  else props.go('materials')
}

/* 选区 → 偏移量 */
function onMouseUp(e) {
  const sel = window.getSelection()
  if (!sel || sel.isCollapsed) { pop.show = false; return }
  const range = sel.getRangeAt(0)
  const host = range.startContainer.parentElement && range.startContainer.parentElement.closest('.tx')
  if (!host || !host.closest('.seg')) { pop.show = false; return }
  const segId = Number(host.closest('.seg').dataset.sid)
  const seg = segs.value.find((s) => s.id === segId)
  if (!seg) return
  const pre = document.createRange()
  pre.selectNodeContents(host)
  try { pre.setEnd(range.startContainer, range.startOffset) } catch (err) { return }
  const start = pre.toString().length
  const text = sel.toString().trim()
  if (!text) { pop.show = false; return }
  pop.show = true
  pop.x = Math.min(e.clientX, window.innerWidth - 344)
  pop.y = Math.min(e.clientY + 12, window.innerHeight - 250)
  pop.text = text
  pop.segSeq = seg.seq
  pop.segId = seg.id
  pop.start = start
  pop.end = start + sel.toString().length
  pop.note = ''
  pop.gloss = ''
  // 顺手查一下字典里有没有这个词
  api.dict({ lang: m.value.lang_code, q: text, scope: 'general', limit: 3 })
    .then((r) => { if (r.entries.length) pop.gloss = r.entries[0].gloss_zh || '' })
    .catch(() => {})
}

async function saveAnn() {
  try {
    const r = await api.addAnnotation({
      material_id: MID.value,
      segment_id: pop.segId,
      kind: pop.text.split(/\s+/).length > 3 ? 'sentence' : pop.text.split(/\s+/).length > 1 ? 'phrase' : 'word',
      start_off: pop.start, end_off: pop.end,
      target_text: pop.text,
      gloss: pop.gloss ? { zh: pop.gloss } : null,
      note: pop.note || null,
    })
    if (pop.note) {
      await api.addNote({
        lang_code: m.value.lang_code, course_id: m.value.course_id, material_id: MID.value,
        annotation_id: r.annotation_id, title: pop.text.slice(0, 40), content: pop.note,
      })
    }
    // 说清卡去了哪里 —— 自建卷是跨课程可见的，标明卷名用户才知道去哪儿找
    say('已建卡：' + pop.text.slice(0, 20)
      + (pop.note ? '（含笔记）' : '')
      + ' → 卡片学习器 · 语料批注'
      + (m.value && m.value.course_id === state.course ? '' : '（跨课程可见）'))
    pop.show = false
    await load()
  } catch (e) { say('保存失败：' + e.message) }
}

async function delAnn(a, e) {
  e && e.stopPropagation()
  if (!confirm('删除这条批注？')) return
  try { await api.delAnnotation(a.id); await load() } catch (err) { say(err.message) }
}

/* 滚动阅读进度（防抖） */
let pt = null
function onScroll() {
  clearTimeout(pt)
  pt = setTimeout(() => {
    const el = wrap.value
    if (!el || !segs.value.length) return
    const tops = Array.from(el.querySelectorAll('.seg'))
    const mid = window.scrollY + window.innerHeight * 0.5
    let last = 0
    tops.forEach((t, i) => { if (t.getBoundingClientRect().top + window.scrollY < mid) last = i + 1 })
    if (last > 0 && (!m.value.progress || (m.value.progress.read_seq || 0) < last)) {
      api.setReadProgress(MID.value, last).catch(() => {})
      m.value.progress = { read_seq: last }
    }
  }, 900)
}

let t0 = Date.now()
function flush() {
  if (!m.value) return
  const sec = Math.round((Date.now() - t0) / 1000)
  if (sec < 5) return
  t0 = Date.now()
  api.addSession({
    lang_code: m.value.lang_code, course_id: m.value.course_id, kind: 'read',
    ref_id: String(MID.value), item_count: 0, duration_sec: sec,
  }).catch(() => {})
}

onMounted(() => {
  load()
  loadProviders()
  window.addEventListener('scroll', onScroll, { passive: true })
})
watch(MID, () => {
  // 换素材必须重新载入：从「影视剧集」点上一集/下一集时，route.name 仍是 reader，
  // 组件实例会被复用，不重新 load 就会停在上一集的内容上
  load()
  if (curProvider.value !== 'browser') loadServerVoices()
})
onBeforeUnmount(() => { window.removeEventListener('scroll', onScroll); flush(); if (io) io.disconnect(); stopLine() })
</script>

<template>
  <!-- 不带素材 ID 直接进阅读器（侧栏「阅读」）→ 落地页：接着读 / 挑一篇 -->
  <template v-if="landing">
    <div class="card" style="padding:16px">
      <h2 style="margin:0 0 4px">阅读</h2>
      <p class="small muted" style="margin:0 0 14px">
        挑一篇开始精读。划词可批注、可加进卡片；上面的「限定词高亮」标出 shall / may / materially 这类责任边界词。
      </p>

      <div v-if="lastMat" style="margin-bottom:18px">
        <button class="btn pri" @click="go('reader', lastMat.id)">
          ▶ 接着读《{{ lastMat.title }}》
          <span style="opacity:.85;font-weight:400">· 第 {{ progOf(lastMat) }} / {{ lastMat.segment_count }} 段（{{ pctOf(lastMat) }}%）</span>
        </button>
      </div>

      <div v-if="matLoading" class="loading">读素材列表…</div>
      <div v-else-if="!mats.length" class="empty">
        当前筛选下还没有素材。倒到「素材库」导入一篇，或换上面的语言 / 课程。
      </div>
      <template v-else>
        <div class="small muted" style="margin-bottom:8px">全部素材（{{ matsSorted.length }}）—— 点标题直接开读</div>
        <button v-for="x in matsSorted" :key="x.id" class="pick" :class="{ on: x.id === lastId }"
                @click="go('reader', x.id)">
          <div class="pt">
            {{ x.title }}
            <span class="tag p" v-if="x.id === lastId">上次在读</span>
          </div>
          <div class="pmeta">
            <span class="tag">{{ x.type }}</span>
            <span class="tag mono">{{ x.lang_code }}</span>
            <span class="small muted">{{ progOf(x) }} / {{ x.segment_count }} 段 · {{ pctOf(x) }}%</span>
          </div>
          <div class="bar g"><i :style="{ width: pctOf(x) + '%' }"></i></div>
        </button>
      </template>
    </div>
  </template>

  <template v-else>
  <div class="row" style="margin-bottom:12px">
    <button class="btn gho sm" :title="ep ? '回到这部剧的剧集列表' : '回到素材库'" @click="backToSeries">
      ← {{ ep ? ep.series : '素材库' }}
    </button>
    <template v-if="ep">
      <button class="btn sm" :disabled="!ep.prev" :title="ep.prev ? '上一集：' + ep.prev.label : '已是第一集'"
              @click="openEp(ep.prev && ep.prev.id)">← 上一集</button>
      <button class="btn sm" :disabled="!ep.next" :title="ep.next ? '下一集：' + ep.next.label : '已是最后一集'"
              @click="openEp(ep.next && ep.next.id)">下一集 →</button>
    </template>
    <div class="sp" style="flex:1"></div>
    <div class="row" v-if="m">
      <!-- 限定词高亮是「精读」的功能，跟有没有 EPUB/PDF 原版文件无关 ——
           原来它被关在 v-if="canOriginal" 里，没有原版文件的素材根本看不到这个按钮（2026-10-03 修） -->
      <button class="btn sm" :class="{ on: qualOn }" @click="qualOn = !qualOn"
              title="标出 shall/may、materially、best vs reasonable efforts 等责任边界词。扫读会跳过它们，而投资文件的价值全在这些词里">限定词高亮</button>
      <template v-if="canOriginal">
        <button class="btn sm" :class="{ on: mode === 'intensive' }" @click="setMode('intensive')">精读（可批注）</button>
        <button class="btn sm" :class="{ on: mode === 'original' }" @click="setMode('original')">
          原版 {{ String(m.type).toUpperCase() }}
        </button>
      </template>
    </div>
  </div>

  <!-- v2 限定词高亮：图例 + 本篇命中统计 -->
  <div class="qualbox" v-if="qualOn && Object.keys(qualStats).length">
    <b>本篇限定词命中：</b>
    <span v-for="(n, cat) in qualStats" :key="cat" class="qual legend" :data-cat="cat">{{ cat }} ×{{ n }}</span>
    <span class="small muted" style="display:block;margin-top:5px">
      鼠标悬停单个词可看「它在这一句里锁住了什么」。扫读会自动跳过这些词 —— 而投资文件的价值全在它们身上。
    </span>
  </div>
  <div class="qualbox" v-else-if="qualOn">
    <b>限定词高亮已开</b> —— 本篇没有命中。这类文本（如剧本、口语）本来就不含法律限定词。
  </div>

  <div class="row" style="margin:-4px 0 12px" v-if="m">
    <span class="crumb" v-if="ep">
      <b>{{ ep.series }}</b> · 第 {{ ep.season }} 季 · {{ ep.label }}<template v-if="ep.ep_title">「{{ ep.ep_title }}」</template><template v-if="ep.index">（{{ ep.index }}/{{ ep.total }}）</template>
    </span>
    <span class="small muted">
      {{ m.segment_count }} 段 · {{ m.word_count }} 词 · 已批注 {{ anns.length }} 处
      <template v-if="m.author"> · {{ m.author }}</template>
    </span>
  </div>

  <div v-if="loading" class="loading">加载中…</div>

  <template v-else-if="m">
    <!-- 原版阅读 -->
    <OriginalReader v-if="mode === 'original' && canOriginal" :material="m" />

    <!-- 精读 + 批注 -->
    <div v-else class="grid" style="grid-template-columns:minmax(0,1fr) 268px;gap:16px;align-items:start">
      <div class="card">
        <h3 style="margin-bottom:4px">{{ m.title }}</h3>
        <p class="small muted" style="margin:0 0 10px">
          选中任意词、短语或句子 → 弹出面板可查词、可写笔记，保存后自动进入该课程的「语料批注」复习卡。
          黄色底为已批注处，点一下可删除。
        </p>

        <!-- 语音阅读工具条 -->
        <div class="speakbar">
          <button class="btn sm" :class="{ pri: speaking }" :disabled="!ttsOk || !segs.length"
                  :title="speaking ? '暂停' : '从当前位置开始连读'"
                  @click="speakToggle">
            {{ speaking ? '⏸ 暂停' : (speakPaused ? '▶ 继续' : '▶ 朗读') }}
          </button>
          <button class="btn sm" :disabled="!ttsOk || speakIdx < 0" title="上一段" @click="ttsPrev">⏮ 上一段</button>
          <button class="btn sm" :disabled="!ttsOk || speakIdx < 0" title="下一段" @click="ttsNext">⏭ 下一段</button>
          <button class="btn sm" :disabled="!speaking && !speakPaused" @click="ttsStop">⏹ 停止</button>

          <span class="small muted" style="margin-left:4px">语速</span>
          <select class="select" style="width:auto;padding:4px 8px"
                  :value="ttsRate" @change="setTtsRate($event.target.value)">
            <option v-for="r in RATES" :key="r" :value="r">{{ r }}×</option>
          </select>

          <!-- 浏览器内置：音色来自系统 -->
          <select v-if="curProvider === 'browser' && ttsVoices.length"
                  class="select" style="width:auto;max-width:180px;padding:4px 8px"
                  :value="ttsVoiceURI" @change="setTtsVoice($event.target.value)">
            <option v-for="v in ttsVoices" :key="v.voiceURI" :value="v.voiceURI">
              {{ voiceLabel(v) }} · {{ v.lang }}
            </option>
          </select>

          <!-- 在线引擎：音色按口音分组 -->
          <template v-else-if="curProvider !== 'browser'">
            <select v-if="serverVoices.length" class="select"
                    style="width:auto;max-width:250px;padding:4px 8px"
                    :value="curVoice" @change="setServerVoice($event.target.value)">
              <optgroup v-for="g in shownGroups" :key="g.locale" :label="g.label">
                <option v-for="v in g.voices" :key="v.id" :value="v.id">
                  {{ v.name }}（{{ v.gender === 'Female' ? '女' : '男' }}{{ v.tag ? '·' + v.tag : '' }}）
                </option>
              </optgroup>
            </select>
            <span v-else-if="voicesLoading" class="small muted">取音色…</span>
          </template>

          <!-- 引擎与音色设置：抽成共用组件，卡片页用的是同一个 -->
          <TtsPanel :lang-code="m.lang_code" :on-test="() => {}" />

          <label class="small" style="display:flex;align-items:center;gap:5px;cursor:pointer">
            <input type="checkbox" v-model="speakFollow" /> 跟随滚动
          </label>

          <div class="sp" style="flex:1"></div>
          <span class="small muted" v-if="loadingAudio">合成中…</span>
          <span class="small muted" v-else-if="speaking || speakPaused">
            {{ speakPaused ? '已暂停于' : '正在读' }}第 {{ speakIdx + 1 }} / {{ segs.length }} 段<template
              v-if="speakChunks > 1"> · 第 {{ speakChunk + 1 }}/{{ speakChunks }} 句</template>
          </span>
          <span class="small muted" v-else>{{ segs.length }} 段可朗读</span>
        </div>

        <div class="speakwarn" v-if="fellBack">
          在线语音失败了，已临时切回浏览器内置<template v-if="fellBackReason">：{{ fellBackReason }}</template>。
          到「⚙ 语音」里换一个引擎，或在系统里给该语言装语音包。
        </div>
        <div class="speakwarn" v-if="!ttsOk">
          这个浏览器不支持语音合成。建议用 Edge 或 Chrome 打开；若没有声音，到
          「设置 → 时间和语言 → 语言和区域」给对应语言勾上「语音」。
        </div>
        <div class="speakwarn" v-else-if="ttsHint">
          {{ ttsError }} —— {{ ttsHint }}
        </div>
        <div class="speakwarn" v-else-if="ttsError">
          发音失败（{{ ttsError }}）。若在预览面板里，容器会拦截语音，
          请用 <code>启动语言学习器.bat</code> 或直接打开页面。
        </div>
        <div class="speakwarn" v-else-if="curProvider === 'browser' && !ttsVoices.length">
          系统里没找到 {{ m.lang_code }} 的语音包：到「设置 → 时间和语言 → 语言和区域」给该语言勾上「语音」，
          重启浏览器后再来；也可以切到「Edge 在线语音」。
        </div>

        <div class="readwrap" ref="wrap">
          <template v-for="(ch, ci) in shownChapters" :key="ci">
            <div v-if="ch.name" class="chapterhead">{{ ch.name }}</div>
            <div v-for="s in ch.segs" :key="s.id" class="seg" :data-sid="s.id"
                 :class="{ playing: speakId === s.id }">
              <!-- 跟读横线（只存在于正在朗读的那一段） -->
              <span v-if="speakId === s.id" class="speakline" aria-hidden="true">
                <i v-for="n in MAX_PL" :key="'pl' + n" class="pl"></i>
                <i v-for="n in MAX_WL" :key="'wl' + n" class="wl"></i>
              </span>
              <span class="no">{{ s.seq }}</span>
              <span class="tx" @mouseup="onMouseUp">
                <template v-for="(p, i) in segParts(s)" :key="i">
                  <!-- 批注优先：已批注的片段不参与限定词切分 -->
                  <span v-if="p.a" class="ann" :title="(p.a.gloss && p.a.gloss.zh ? p.a.gloss.zh + '｜' : '') + (p.a.note || '')"
                        @click="delAnn(p.a, $event)">{{ p.t }}</span>
                  <!-- 限定词高亮（v2）：把扫读会跳过的责任边界词标出来 -->
                  <template v-else-if="qualOn">
                    <template v-for="(q, j) in qualParts(p.t)" :key="j">
                      <span v-if="!q.q">{{ q.t }}</span>
                      <span v-else class="qual" :data-cat="q.q.cat" :title="q.q.cat + '：' + q.q.tip">{{ q.t }}</span>
                    </template>
                  </template>
                  <span v-else>{{ p.t }}</span>
                </template>
              </span>
              <span class="segtools" v-if="ttsOk">
                <button class="icb" :class="{ on: speakId === s.id && speaking }"
                        :title="speakId === s.id && speaking ? '停止朗读本段' : '朗读本段'"
                        @click.stop="segToggle(s)">
                  {{ speakId === s.id && speaking ? '⏹' : '🔊' }}
                </button>
                <button class="icb" title="从这里往下连读" @click.stop="speakFrom(s)">⏵</button>
              </span>
            </div>
          </template>
          <div ref="moreEl" class="morebar">
            <template v-if="hasMore">
              已显示 {{ visibleCount }} / {{ segs.length }} 段
              <button class="btn sm" @click="bump">继续加载</button>
            </template>
            <template v-else-if="segs.length > 600">
              — 已到文末（共 {{ segs.length }} 段）—
            </template>
          </div>
        </div>
      </div>

      <!-- 右栏：批注清单 -->
      <div class="card" style="position:sticky;top:18px">
        <h3>批注与笔记<span class="sub">{{ anns.length }}</span></h3>
        <div class="small muted" style="line-height:1.75;margin:-2px 0 10px">
          <b>划词建卡：</b>在左边正文里拖选一个词 / 短语 / 句子 → 浮层里写下你的理解 → 点「保存并生成卡片」。
          保存的那一刻就已经建好卡了，不用再手动同步。
        </div>
        <div class="row" style="margin-bottom:10px" v-if="anns.length">
          <button class="btn sm pri" @click="go('cards', 'anno-' + m.course_id)">去卡片学习器复习 →</button>
        </div>
        <div v-if="!anns.length" class="small muted">还没有批注。在左边选中文字即可添加。</div>
        <div v-for="a in anns" :key="a.id" style="padding:8px 0;border-bottom:1px dashed var(--border)">
          <div class="row" style="justify-content:space-between">
            <span class="tag c">{{ a.kind }}</span>
            <button class="btn gho sm" @click="delAnn(a)">删</button>
          </div>
          <div style="font-weight:600;margin-top:3px;word-break:break-word">{{ a.target_text }}</div>
          <div class="small" v-if="a.gloss && a.gloss.zh" style="color:var(--primary-ink)">{{ a.gloss.zh }}</div>
          <div class="small muted" v-if="a.note">{{ a.note }}</div>
        </div>
        <p class="small muted" style="margin:10px 0 0">
          这些批注已同步到「卡片学习器 → 语料批注 · 来自阅读」，可以直接复习。
        </p>
      </div>
    </div>
  </template>

  <!-- 划词浮层 -->
  <div v-if="pop.show" class="pop" :style="{ left: pop.x + 'px', top: pop.y + 'px' }">
    <div class="row" style="justify-content:space-between">
      <span class="small muted">第 {{ pop.segSeq }} 段</span>
      <button class="btn gho sm" @click="pop.show = false">×</button>
    </div>
    <div class="hd">{{ pop.text }}</div>
    <div class="bd">
      <template v-if="pop.gloss"><span class="tag g">字典命中</span> {{ pop.gloss }}</template>
      <template v-else>
        <span class="muted">字典暂无此词条 —— 不影响建卡，卡片会以你自己的笔记为准。
          <br />想要自动带中文释义：先到「素材库」给这篇点一次「生成词条」。</span>
      </template>
    </div>
    <textarea v-model="pop.note" placeholder="这个点为什么重要 / 你怎么理解 / 易错在哪（会显示在卡片背面）"></textarea>
    <div class="small muted" style="margin:7px 0 2px;line-height:1.7">
      保存后会<b>立刻生成一张复习卡</b>，进「卡片学习器 → 语料批注 · 来自阅读」，不用再手动同步。
    </div>
    <div class="row" style="margin-top:6px">
      <button class="btn pri sm" @click="saveAnn">保存并生成卡片</button>
      <button class="btn gho sm" @click="pop.show = false">取消</button>
    </div>
  </div>
  </template>
</template>
<style scoped>
/* 落地页的素材选择列表 */
.pick { display: block; width: 100%; text-align: left; padding: 10px 12px; margin-bottom: 8px;
  background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-md, 10px); cursor: pointer; }
.pick:hover { border-color: var(--primary); }
.pick.on { border-color: var(--primary); box-shadow: 0 0 0 2px var(--primary-soft, rgba(37,99,235,.15)); }
.pick .pt { font-size: 14px; font-weight: 600; margin-bottom: 5px; }
.pick .pmeta { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-bottom: 6px; }

/* ===== v2 法律限定词高亮 =====
   ⚠️ 这段原来**裸露在 <style> 之外**（<style scoped> 在 .pick 处就提前闭合了），
      属于死代码 —— 编译产物里根本没有 qual[data-cat]，限定词高亮一直是没上色的（2026-10-03 修）。 */
.qual {
  background: var(--primary-soft); color: var(--primary-ink);
  border-bottom: 2px solid var(--primary); border-radius: 2px;
  padding: 0 2px; cursor: help;
}
.qual[data-cat='义务强度'] { background:#FCEBEB; color:#A32D2D; border-bottom-color:#A32D2D; }
.qual[data-cat='重要性门槛'] { background:#FAEEDA; color:#854F0B; border-bottom-color:#854F0B; }
.qual[data-cat='努力程度'] { background:#EEEDFE; color:#534AB7; border-bottom-color:#534AB7; }
.qual[data-cat='例外与保留'] { background:#E1F5EE; color:#0F6E56; border-bottom-color:#0F6E56; }
.qual[data-cat='时间与条件'] { background:#E6F1FB; color:#185FA5; border-bottom-color:#185FA5; }
.qual[data-cat='责任边界'] { background:#EAF3DE; color:#3B6D11; border-bottom-color:#3B6D11; }
.qualbox {
  border: 1px solid var(--border); background: var(--surface);
  border-radius: var(--r-md); padding: 9px 12px; margin: 0 0 12px; font-size: 13px;
}
.qualbox .legend { display:inline-block; margin: 0 6px 3px 0; cursor: default; }
</style>

