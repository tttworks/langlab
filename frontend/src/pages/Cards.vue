<script setup>
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount, inject } from 'vue'
import TtsPanel from '../components/TtsPanel.vue'
import { useSpeech } from '../useSpeech.js'
import { useTtsSettings } from '../useTtsSettings.js'

const api = inject('api')
const state = inject('state')
const say = inject('say')
const props = defineProps({ route: Object, go: Function })

const sets = ref([])
const cards = ref([])
const loading = ref(true)
const set = ref('all')
const cat = ref('all')
const q = ref('')
const quiz = ref(false)          // 考察模式
// v2 产出模式：把「术语/名词/陷阱/框架」类的正面换成中文，英文是答案（模糊遮罩）
// 依据：实测显示他的瓶颈是「实义词取不出来」（口语沉默 58%），不是"不认识"
const prod = ref(false)
const PROD_KINDS = ['term', 'noun', 'trap', 'frame', 'question', 'phrase']
function canProd(c) {
  return PROD_KINDS.includes(c.kind) && !!(c.data && (c.data.cn || c.data.plain))
}

/* ============ v2 卡片互通：例句/搭配里的词可点，跳到那张卡 ============ */
const lk = reactive({ show: false, x: 0, y: 0, word: '', cards: [], loading: false, err: '',
  ctx: '', from: '', cn: '', made: null, busy: false })

/** 把一句英文切成「非词 / 词」的片段，词的部分可点 */
function lkWords(text) {
  if (!text) return []
  return String(text).split(/(\s+)/).map((s) => {
    const m = s.match(/^([^A-Za-z'\-]*)([A-Za-z][A-Za-z'\-]*)(.*)$/)
    if (!m) return { t: s, w: null }
    return { t: s, pre: m[1], w: m[2], post: m[3] }
  }).filter((x) => x.t !== '')
}

async function lookup(word, e, ctx, from) {
  if (e && e.target && e.target.getBoundingClientRect) {
    const r = e.target.getBoundingClientRect()
    lk.x = Math.max(8, Math.min(r.left, window.innerWidth - 360))
    lk.y = Math.min(r.bottom + 6, window.innerHeight - 300)
  }
  lk.word = word; lk.show = true; lk.loading = true; lk.cards = []; lk.err = ''
  // 就地建卡要带的上下文：你看到它的那句话 + 它出自哪张卡
  lk.ctx = String(ctx || '').trim()
  lk.from = String(from || '').trim()
  lk.cn = ''; lk.made = null; lk.busy = false
  try {
    const r = await fetch('/api/cards/lookup?w=' + encodeURIComponent(word)).then((x) => x.json())
    lk.cards = (r && r.cards) || []
  } catch (err) { lk.err = '查询失败：' + err.message }
  lk.loading = false
}
/** 就地建卡：释义留空就交给后端用 AI 补（含美式音标） */
async function makeCard() {
  if (lk.busy) return
  lk.busy = true; lk.err = ''
  try {
    const r = await api.quickCard({
      word: lk.word, ctx: lk.ctx, from: lk.from, cn: (lk.cn || '').trim(),
      course_id: state.course, lang_code: LANG(),
    })
    lk.made = r.card || null
    lk.cards = [Object.assign({}, r.card, { set_title: '我的生词 · 手动添加' })]
    say((r.created ? '已建卡：' : '已更新：') + lk.word
      + (r.gloss_source === 'ai' ? '（AI 补了释义）' : r.gloss_source === 'dict' ? '（词库释义）' : ''))
  } catch (e) {
    lk.err = '建卡失败：' + e.message
  }
  lk.busy = false
}
function lkClose() { lk.show = false }

/** 删掉自己建的卡（后端只允许 my-* 卷） */
async function delOne(c) {
  const w = (c.data && c.data.w) || '这张卡'
  if (!window.confirm('删除「' + w + '」？\n删掉后不可恢复。')) return
  try {
    await api.delCard(c.id)
    const i = cards.value.findIndex((x) => x.id === c.id)
    if (i >= 0) cards.value.splice(i, 1)
    delete local[c.id]
    say('已删除：' + w)
    await loadSets()
  } catch (e) { say('删除失败：' + e.message) }
}

/** 跳到那张卡：在当前列表里就滚过去；不在就切到它的卡组再滚 */
async function lkGo(c) {
  lk.show = false
  const inDeck = deck.value.some((x) => x.id === c.id)
  if (!inDeck) {
    dueMode.value = false
    set.value = c.set_id
    cat.value = 'all'
    await loadCards()
  }
  hl.value = 'c' + c.id
  setTimeout(() => {
    const el = document.getElementById('c' + c.id)
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' })
  }, 130)
}

/* ============ 可拖动弹窗（共用）============ */
function dragStart(e, obj) {
  if (e && e.button !== 0) return
  const sx = e.clientX, sy = e.clientY
  const ox = obj.x, oy = obj.y
  document.body.style.userSelect = 'none'
  const mv = (ev) => {
    obj.x = Math.min(Math.max(0, ox + ev.clientX - sx), Math.max(0, window.innerWidth - 160))
    obj.y = Math.min(Math.max(0, oy + ev.clientY - sy), Math.max(0, window.innerHeight - 52))
  }
  const up = () => {
    document.body.style.userSelect = ''
    window.removeEventListener('mousemove', mv)
    window.removeEventListener('mouseup', up)
  }
  window.addEventListener('mousemove', mv)
  window.addEventListener('mouseup', up)
}

/* ============ 回到原文：弹窗显示源文档段落 + 前后文 ============ */
const srcP = reactive({ show: false, x: 0, y: 0, loading: false, err: '', data: null, title: '', w: '' })
async function toSource(c) {
  srcP.w = (c.data && c.data.w) || ''
  srcP.title = '出处原文'
  srcP.data = null; srcP.err = ''; srcP.loading = true
  srcP.x = Math.max(12, window.innerWidth / 2 - 250)
  srcP.y = 110
  srcP.show = true
  try {
    const r = await fetch('/api/cards/' + c.id + '/source').then((x) => x.json())
    srcP.data = r
    if (r && r.material_title) srcP.title = r.material_title
  } catch (err) { srcP.err = '读取失败：' + err.message }
  srcP.loading = false
}
function srcClose() { srcP.show = false }
function srcGoReader() {
  const mid = srcP.data && srcP.data.material_id
  srcP.show = false
  if (mid && props.go) props.go('reader', mid)
}
const onlyNew = ref(false)
const shuffled = ref(false)
const revealed = reactive({})    // 考察模式下已揭晓的卡
const hl = ref('')               // 高亮的卡
const local = reactive({})       // 本地即时生效的掌握状态

/* SM-2 复习队列（路线 A：全卡统一调度） */
const dueMode = ref(false)       // true = 今天该复习视图
const dueCards = ref([])         // 到期卡片
const dueCount = ref(0)          // 到期数量（标签页角标）
const dueOverdue = ref(0)        // 其中逾期数量
const dueToday = ref(0)          // 今天到期（未逾期）数量
const dueLoading = ref(false)
const revealedDue = reactive({}) // 复习模式下已「显示答案」的卡

const LANG = () => (state.courses.find((c) => c.id === state.course) || {}).lang_code || 'en-US'

const curSet = computed(() => sets.value.find((s) => s.id === set.value) || null)

// ⚠️ 分类的唯一真相来源是「卡片自己的 data.cat」，不是 card_sets.cats 的 key。
// card_sets.cats 只是「标签字典」（key → 好看的中文名），手写的，和实际数据可能对不上。
// 曾经拿它的 key 当筛选值 → 发出数据里不存在的值 → 服务端返回 0 条 → 「卡片全消失」（2026-10-03 修）。
// 现在：筛选值一律取自真实数据，标签能查到就用字典，查不到就原样显示 data.cat。
const catList = computed(() => {
  const m = new Map()
  cards.value.forEach((c) => {
    const k = c.data && c.data.cat
    if (k) m.set(k, (m.get(k) || 0) + 1)
  })
  const dict = (curSet.value && curSet.value.cats) || {}
  return [...m.entries()]
    .map(([k, n]) => ({ k, n, label: dict[k] || k }))
    .sort((a, b) => b.n - a.n)
})

const shown = computed(() => {
  let list = cards.value
  // 分类过滤改在客户端做：卡片量小（≤200/组），且值就是 data.cat 原文，不可能对不上
  if (cat.value !== 'all') list = list.filter((c) => c.data && c.data.cat === cat.value)
  if (onlyNew.value) list = list.filter((c) => st(c) !== 'mastered')
  return list
})

// 兜底：若当前分类在新一组卡片里不存在（换卷/换课程后），自动退回「全部」
watch(catList, (l) => {
  if (cat.value !== 'all' && !l.some((x) => x.k === cat.value)) cat.value = 'all'
})
// 当前真正要渲染的卡：复习模式 = 到期队列，否则 = 浏览筛选结果
const deck = computed(() => (dueMode.value ? dueCards.value : shown.value))
const masteredCount = computed(() => cards.value.filter((c) => st(c) === 'mastered').length)
const allMastered = computed(() => sets.value.reduce((a, s) => a + s.mastered, 0))
const allTotal = computed(() => sets.value.reduce((a, s) => a + s.total, 0))

function st(c) { return local[c.id] || c.state || 'new' }

async function loadSets() {
  const r = await api.cardSets(state.course)
  sets.value = r.sets
}
async function loadCards() {
  loading.value = true
  // 注意：不带 cat —— 分类过滤在客户端做（见 shown / catList）
  const r = await api.cards({
    course: state.course,
    set: set.value,
    q: q.value.trim(),
    limit: 2000,
  })
  cards.value = r.cards
  if (shuffled.value) shuffleInPlace()
  loading.value = false
}
function shuffleInPlace() {
  const a = cards.value
  for (let i = a.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1))
    ;[a[i], a[j]] = [a[j], a[i]]
  }
  cards.value = a.slice()
}

function pickSet(id) { set.value = id; cat.value = 'all'; loadCards() }

/** 深链：#/cards/<set_id> 直接落到某一卷（阅读器「去卡片学习器复习」用的就是这个）。
 *  卷必须真的属于当前课程，否则退回「全部」 */
function wantSetId() {
  const w = String((props.route && props.route.args && props.route.args[0]) || '')
  return (w && sets.value.some((s) => s.id === w)) ? w : 'all'
}
/** 一键清空所有筛选并重载（空状态里的救援按钮） */
function resetFilters() {
  dueMode.value = false
  set.value = 'all'
  cat.value = 'all'
  q.value = ''
  onlyNew.value = false
  loadCards()
}

// 当前课程（含卡片数）。courseCards === 0 表示这是一门占位课程：素材还没导入。
// ⚠️ 卡片页自己没有课程选择器，一旦 localStorage 里存的是空课程，用户就出不去（2026-10-03 修）。
const curCourse = computed(() => state.courses.find((x) => x.id === state.course) || null)
const courseCards = computed(() => (curCourse.value ? (curCourse.value.cards || 0) : null))
const courseName = computed(() => (curCourse.value ? curCourse.value.name : state.course))
/** 有卡片的课程 —— 空课程时给用户一键跳过去 */
const coursesWithCards = computed(() => state.courses.filter((c) => (c.cards || 0) > 0))

/** 切到另一门课程（空课程自救出口）。state.course 的 watcher 会自动重载卡组与卡片 */
function pickCourse(id) {
  const c = state.courses.find((x) => x.id === id)
  if (!c) return
  state.course = id
  state.lang = c.lang_code
  localStorage.setItem('langlab.course', id)
  localStorage.setItem('langlab.lang', c.lang_code)
}
function toggleShuffle() { shuffled.value = !shuffled.value; if (shuffled.value) shuffleInPlace(); else loadCards() }

async function mark(c, next) {
  const to = st(c) === next ? 'new' : next
  local[c.id] = to
  const s = sets.value.find((x) => x.id === c.set_id)
  if (s) s.mastered += (to === 'mastered' ? 1 : 0) - (c.state === 'mastered' ? 1 : 0)
  try {
    await api.setProgress([{ id: c.id, state: to }])
    touched++
  } catch (e) { say('保存失败：' + e.message) }
  // 复习模式下标记掌握 → 立刻移出今天的队列
  if (dueMode.value) {
    const i = dueCards.value.findIndex((x) => x.id === c.id)
    if (i >= 0) { dueCards.value.splice(i, 1); dueCount.value = Math.max(0, dueCount.value - 1) }
  }
}

/* ---------------- SM-2 复习队列 ---------------- */
async function loadDue() {
  dueLoading.value = true
  try {
    const r = await api.cardsDue(state.course)
    dueCards.value = r.cards
    dueCount.value = r.count
    dueOverdue.value = r.overdue_count || 0
    dueToday.value = r.due_today_count || 0
  } catch (e) { say('加载复习队列失败：' + e.message) }
  finally { dueLoading.value = false }
}
async function review(c, grade) {
  try {
    const r = await api.review(c.id, grade)
    const i = dueCards.value.findIndex((x) => x.id === c.id)
    if (i >= 0) { dueCards.value.splice(i, 1); dueCount.value = Math.max(0, dueCount.value - 1) }
    const label = { again: '忘了', hard: '困难', good: '良好', easy: '简单' }[grade]
    say(`已记录「${label}」→ 下次 ${r.due_date}（间隔 ${r.interval_days} 天）`)
    touched++
  } catch (e) { say('复习提交失败：' + e.message) }
}

/* ---------------- 朗读：与阅读器同一套引擎与音色 ---------------- */
const ttsCfg = useTtsSettings()
const { curProvider, ensureVoices, loadProviders, fellBack, fellBackReason, noteFallback } = ttsCfg

const {
  supported: ttsSupported,
  error: ttsError,
  hint: ttsHint,
  loadingAudio,
  currentId: speakId,
  playing: speaking,
  rate: ttsRate,
  setRate: setTtsRate,
  play: ttsPlay,
  stop: ttsStop,
} = useSpeech({
  lang: () => LANG(),
  provider: () => curProvider.value,
  voice: () => ttsCfg.voiceFor(LANG()),
})

const RATES = [0.7, 0.85, 1, 1.15, 1.3]

// 当前口音（英语素材应显示「美式英语」）。放在工具栏上，不打开设置面板也能一眼确认
const curVoiceInfo = computed(() => (ttsCfg.curProvider.value === 'browser' ? null : ttsCfg.voiceInfo(LANG())))

/** 每张卡要读什么：优先朗读字段，其次按卡片类型挑英文内容 */
function sayOf(c) {
  const d = c.data || {}
  if (d.say) return d.say
  switch (c.kind) {
    case 'word':
    case 'colloc':
    case 'anno':
      return d.w || ''
    case 'chinglish':
      // good 里可能是 "a / b / c" 的备选列表，只读第一个
      return String(d.good || '').split('/')[0].trim() || d.good || ''
    case 'pattern':
      return d.eg || d.en || ''
    case 'concept':
      return d.en || ''
    default:
      return d.w || d.n || ''
  }
}
function enOf(c) { return c.data.en || c.data.eg || c.data.quote || '' }
function zhOf(c) { return c.data.zh || c.data.egZh || c.data.quoteZh || '' }
/** 例句与朗读对象不同才值得单独给个按钮 */
function exampleOf(c) {
  const en = enOf(c)
  return en && en !== sayOf(c) ? en : ''
}

// ⚠️ 每张卡的播放必须是**独立 id**。以前所有卡共用 '__card__' 哨兵，
// 于是「任意一张在播」= 「speakId === '__card__' && speaking」对所有卡都成立 → 全部图标一起变（2026-10-03 修）
const keyWord = (c) => 'cw' + c.id
const keyEg = (c) => 'ce' + c.id
/** 本卡是否正在读「词条」 */
const playingWord = (c) => speaking.value && speakId.value === keyWord(c)
/** 本卡是否正在读「例句」 */
const playingEg = (c) => speaking.value && speakId.value === keyEg(c)

/** 点 🔊（或点卡片本体）：正在读就停，否则读词条。用本卡专属 id，互不干扰 */
function speakCard(c, e) {
  e && e.stopPropagation()
  if (playingWord(c)) return ttsStop()
  const t = sayOf(c)
  if (!t) return
  ttsPlay([{ id: keyWord(c), text: String(t) }], 0, false)
}
/** 点 💬：正在读就停，否则读例句 */
function speakExample(c, e) {
  e && e.stopPropagation()
  if (playingEg(c)) return ttsStop()
  const ex = exampleOf(c)
  if (!ex) return
  ttsPlay([{ id: keyEg(c), text: String(ex) }], 0, false)
}
/** 试听：用当前卡片的词条当样本，比念一句「voice test」有用 */
function testWithCard(text) {
  if (text) ttsPlay([{ id: '__test__', text }], 0, false)
}
// 在线引擎失败 → 回退浏览器内置，并把当前这张卡重读一遍
watch(ttsError, (e) => {
  if (!e || curProvider.value === 'browser' || fellBack.value) return
  if (!noteFallback(e, ttsHint.value)) return
  say('在线语音失败，已临时切回浏览器内置：' + e)
})

function reveal(c, e) {
  if (dueMode.value) { revealedDue[c.id] = !revealedDue[c.id]; return }
  if (quiz.value) revealed[c.id] = !revealed[c.id]
  else if (e) speakCard(c, e)
}
async function draw() {
  if (!deck.value.length) return
  const c = deck.value[Math.floor(Math.random() * deck.value.length)]
  hl.value = 'c' + c.id
  if (sayOf(c)) speakCard(c)
  const el = document.getElementById(hl.value)
  if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' })
  setTimeout(() => (hl.value = ''), 4200)
}

/* 学习时长埋点 */
let t0 = Date.now()
let touched = 0
function flush(final) {
  const sec = Math.round((Date.now() - t0) / 1000)
  if (sec < 5 && touched === 0) return
  const payload = {
    lang_code: LANG(), course_id: state.course, kind: 'card',
    ref_id: set.value, item_count: touched, duration_sec: sec,
  }
  t0 = Date.now(); touched = 0
  if (final && navigator.sendBeacon) {
    navigator.sendBeacon('/api/sessions', new Blob([JSON.stringify(payload)], { type: 'application/json' }))
  } else {
    api.addSession(payload).catch(() => {})
  }
}
function onHide() { if (document.visibilityState === 'hidden') flush(true) }

onMounted(async () => {
  document.addEventListener('visibilitychange', onHide)
  loadProviders()
  await loadSets()
  set.value = wantSetId()
  await loadCards()
  await loadDue()            // 预载复习队列数量，供标签页角标显示
  ensureVoices(LANG())
})
onBeforeUnmount(() => { flush(true); document.removeEventListener('visibilitychange', onHide) })

watch(() => state.course, async () => {
  await loadSets()
  set.value = wantSetId()
  cat.value = 'all'
  await loadCards()
  if (dueMode.value) await loadDue()
})
// 同一实例内换深链（#/cards → #/cards/anno-en-contract）不会重挂载，也没换课程，得单独听
watch(() => (props.route && props.route.args && props.route.args[0]) || '', async (w) => {
  if (!w) return
  if (!sets.value.length) await loadSets()
  if (sets.value.some((s) => s.id === w)) { set.value = w; cat.value = 'all'; await loadCards() }
})
// 切到「今天该复习」时拉取队列；切回浏览时重新载入全部卡
watch(dueMode, async (v) => { if (v) await loadDue(); else await loadCards() })
let qt = null
watch(q, () => { clearTimeout(qt); qt = setTimeout(loadCards, 260) })

let voiceTip = ref('')
onMounted(() => {
  setTimeout(() => {
    if (!ttsSupported.value) voiceTip.value = '这个浏览器不支持语音合成'
    else if (curProvider.value === 'browser') {
      const vs = (window.speechSynthesis && speechSynthesis.getVoices()) || []
      if (!vs.some((v) => v.lang && v.lang.startsWith(LANG().slice(0, 2)))) {
        voiceTip.value = `系统里没找到 ${LANG()} 的语音包：设置 → 时间和语言 → 语言和区域 → 给该语言勾上「语音」；也可以到「⚙ 语音」切到 Edge 在线语音`
      }
    }
  }, 1600)
})

const LEVEL = { 高: 'r', 中: 'a', 低: '' }
const KINDLABEL = {
  word: '词汇', colloc: '搭配', chinglish: '翻译腔', pattern: '句式', concept: '概念', anno: '我的批注',
  // v2 新增类别
  term: '术语', noun: '抽象名词', trap: '中文一词多形', frame: '复述框架', question: '尽调提问', phrase: '商务语块',
}

/* 朗读工具条：与阅读器共用同一份引擎/音色设置 */
/** 从当前显示的卡片开始，按顺序连读（只读词条本身） */
function speakCurrentSet(e) {
  if (e) e.stopPropagation()
  const list = deck.value.map((c) => ({ id: c.id, text: sayOf(c) })).filter((x) => x.text)
  if (!list.length) return
  ttsPlay(list, 0, true)
}
// 连读时把正在读的那张卡滚到视野里并高亮，否则只闻其声不知读到哪。
// ⚠️ 只有「朗读本卷」用卡片数字 id；单卡朗读用 cw12 / ce12 / __card__ / __test__ 这类非数字 id，不该触发滚动
watch(speakId, (id) => {
  if (!/^\d+$/.test(String(id ?? ''))) return
  hl.value = 'c' + id
  const el = document.getElementById(hl.value)
  if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' })
})

function ctxParts(c) {
  const ctx = String(c.data.ctx || '')
  const t = String(c.data.w || '')
  if (!ctx || !t) return { before: ctx, hit: '', after: '' }
  const i = ctx.toLowerCase().indexOf(t.toLowerCase())
  if (i < 0) return { before: ctx, hit: '', after: '' }
  return { before: ctx.slice(0, i), hit: ctx.slice(i, i + t.length), after: ctx.slice(i + t.length) }
}
/* toSource 已改为弹窗版本（见上方「回到原文」区块），旧的跳转实现已移除 */
</script>

<template>
  <div class="card" style="padding:14px 16px">
    <div class="row" style="margin-bottom:12px">
      <span class="tab" :class="{ on: !dueMode }" @click="dueMode = false">全部卡片</span>
      <span class="tab" :class="{ on: dueMode }" @click="dueMode = true">今天该复习 <span class="n">{{ dueCount }}</span></span>
    </div>

    <div class="row">
      <input class="input" style="max-width:320px" v-model="q" placeholder="搜索：英文 / 中文 / 音标 / 条款号（如 10.1.1）" />
      <button class="btn" :class="{ on: quiz }" @click="quiz = !quiz">考察模式</button>
      <button class="btn" :class="{ on: prod }" @click="prod = !prod"
              title="术语/名词类卡片：正面显示中文，英文作为答案 → 练「取得出来」">产出模式（中→英）</button>
      <button class="btn" :class="{ on: onlyNew }" @click="onlyNew = !onlyNew">只看未掌握</button>
      <button class="btn" :class="{ on: shuffled }" @click="toggleShuffle">乱序</button>
      <button class="btn" @click="draw">随机抽一张</button>
      <div class="sp" style="flex:1"></div>
      <span class="small muted" v-if="!dueMode">
        本卷 <b>{{ masteredCount }}</b>/{{ cards.length }}
        <template v-if="allTotal"> · 全部 <b>{{ allMastered }}</b>/{{ allTotal }}</template>
      </span>
      <span class="small muted" v-else>今天该复习 <b>{{ dueCount }}</b> 张<template v-if="dueOverdue"> · 其中 <b style="color:var(--red)">{{ dueOverdue }}</b> 张逾期</template></span>
    </div>

    <!-- 朗读：与阅读器共用同一份引擎/音色 -->
    <div class="row" style="margin-top:10px;padding-top:10px;border-top:1px dashed var(--border)">
      <button class="btn pri sm" :disabled="!ttsSupported || !deck.length || speaking"
              :title="'从第一张开始按顺序朗读本卷（' + deck.length + ' 张）'"
              @click="speakCurrentSet">
        ▶ 朗读本卷
      </button>
      <button class="btn sm" :disabled="!speaking" @click="ttsStop">⏹ 停止</button>

      <span class="small muted" style="margin-left:4px">语速</span>
      <select class="select" style="width:auto;padding:4px 8px"
              :value="ttsRate" @change="setTtsRate($event.target.value)">
        <option v-for="r in RATES" :key="r" :value="r">{{ r }}×</option>
      </select>

      <TtsPanel :lang-code="LANG()" :sample-text="deck[0] ? sayOf(deck[0]) : ''" :on-test="testWithCard" compact />

      <div class="sp" style="flex:1"></div>
      <span class="small muted" v-if="loadingAudio">合成中…</span>
      <span class="small muted" v-else-if="speaking">正在朗读…</span>
      <span class="small muted" v-else>
        每张卡右上角的 🔊 读词条<template v-if="curVoiceInfo"> · 音色 <b>{{ curVoiceInfo.accent }}</b></template>
      </span>
    </div>

    <div class="speakwarn" v-if="fellBack" style="margin-top:10px">
      在线语音失败了，已临时切回浏览器内置<template v-if="fellBackReason">：{{ fellBackReason }}</template>。
      到「⚙ 语音」里换一个引擎。
    </div>
    <div class="speakwarn" v-if="ttsHint" style="margin-top:10px">{{ ttsError }} —— {{ ttsHint }}</div>
    <div v-else-if="voiceTip" class="small" style="margin-top:9px;color:var(--amber)">{{ voiceTip }}</div>

    <!-- 本课程自己没有内容卡 → 说明白，但别让用户以为「卡片丢了」。
         自建卡（★）是跨课程可见的，所以这一页有卡 ≠ 这门课有卡（2026-10-04） -->
    <div class="small muted" v-if="!dueMode && sets.length && !sets.some((s) => !s.self_built)"
         style="margin-top:11px;line-height:1.75">
      这门课程还没有自己的卡片，下面是<b>你自己攒的卡</b>（★）——
      语料批注和我的生词跨课程都看得到。课程内容可以去「素材库」导入并生成词条。
    </div>

    <!-- 卷 -->
    <div class="row" style="margin-top:11px" v-if="!dueMode">
      <span class="chip" :class="{ on: set === 'all' }" @click="pickSet('all')">全部<span class="n">{{ allTotal }}</span></span>
      <span v-for="s in sets" :key="s.id" class="chip"
            :class="{ on: set === s.id, other: s.self_built && !s.here }"
            :title="(s.self_built ? '你自己攒的卡（跨课程可见）' : '') + (s.here ? '' : '· 属于其他课程') + (s.sub ? '\n' + s.sub : '')"
            @click="pickSet(s.id)">
        <template v-if="s.self_built">★ </template>{{ s.title.split(' · ')[0] }} · {{ s.title.split(' · ')[1] || '' }}
        <span class="n">{{ s.total }}</span><span class="n ok">{{ s.mastered }}</span>
      </span>
    </div>

    <!-- 分类：值取自卡片真实的 data.cat（不是 card_sets.cats 的 key），所以绝不会点了没卡 -->
    <div class="row" style="margin-top:8px" v-if="catList.length && set !== 'all' && !dueMode">
      <span class="chip" :class="{ on: cat === 'all' }" @click="cat = 'all'">
        全部分类<span class="n">{{ cards.length }}</span>
      </span>
      <span v-for="x in catList" :key="x.k" class="chip" :class="{ on: cat === x.k }" @click="cat = x.k">
        {{ x.label }}<span class="n">{{ x.n }}</span>
      </span>
    </div>

    <div v-if="voiceTip" class="small" style="margin-top:9px;color:var(--amber)">{{ voiceTip }}</div>
  </div>

  <div v-if="(dueMode ? dueLoading : loading)" class="loading">加载中…</div>
  <div v-else-if="!deck.length" class="empty" style="margin-top:14px">
    <b v-if="dueMode">今天都复习完啦 🎉</b>

    <!-- 情形一：这门课程本身就没有卡片（占位课程 / 课程 id 失效）——「卡片全消失」最常见的原因 -->
    <template v-else-if="!cards.length && coursesWithCards.length && (courseCards === 0 || !curCourse)">
      <b>「{{ courseName }}」这门课还没有卡片。</b>
      <div class="small muted" style="margin-top:7px;line-height:1.9">
        它是一门占位课程，素材还没导入，所以卡片页是空的 —— 不是你操作错了，也不是卡片丢了。
      </div>
      <div class="small muted" style="margin-top:13px">目前有卡片的是这几门（点一下直接切过去）：</div>
      <div class="row" style="margin-top:8px;justify-content:center;flex-wrap:wrap">
        <button v-for="c in coursesWithCards" :key="c.id" class="btn sm pri" @click="pickCourse(c.id)">
          {{ c.name }}（{{ c.cards }} 张）
        </button>
      </div>
    </template>

    <!-- 情形二：卡片是有的，被筛选条件挡住了 -->
    <template v-else>
      <b>没有匹配的卡片。</b>
      <!-- 关键：把当前筛选显示出来，否则用户不知道是被哪个条件挡住了 -->
      <div class="small muted" style="margin-top:7px;line-height:1.9">
        当前筛选：课程 <b>{{ courseName }}</b> ｜ 卷 <b>{{ set }}</b> ｜ 分类 <b>{{ cat }}</b> ｜
        关键词 <b>{{ q ? q : '（空）' }}</b> ｜ 只看未掌握 <b>{{ onlyNew ? '开' : '关' }}</b>
      </div>
      <div class="row" style="margin-top:11px;justify-content:center">
        <button class="btn sm pri" @click="resetFilters">清除全部筛选并重载</button>
        <button class="btn gho sm" @click="loadCards()">重新加载</button>
      </div>
      <div class="small muted" style="margin-top:8px">
        提示：如果刚才是改完代码后出现的，按 <b>Ctrl+Shift+R</b> 强制刷新一次。
      </div>
    </template>
  </div>

  <div v-else class="deck" style="margin-top:14px">
    <div v-for="c in deck" :key="c.id" :id="'c' + c.id" class="cc"
         :class="{ done: st(c) === 'mastered', hl: hl === 'c' + c.id, reveal: (!quiz || revealed[c.id]) && (!dueMode || revealedDue[c.id]), anno: c.kind === 'anno', 'cc-urg-high': dueMode && c.urgency === 'high', 'cc-urg-mid': dueMode && c.urgency === 'mid' }"
         @click="reveal(c)">
      <!-- 复习视图：遗忘曲线紧急度徽标（不随答案遮罩，始终可见） -->
      <div class="row due-flag" v-if="dueMode">
        <span class="due-badge" :class="c.overdue_days ? ('u-' + c.urgency) : 'u-today'">
          {{ c.overdue_days ? ('逾期 ' + c.overdue_days + ' 天') : '今天到期' }}
        </span>
        <span class="small muted" v-if="c.overdue_days">留存率 ≈ {{ Math.round(c.retention * 100) }}%</span>
        <span class="small muted" v-else-if="c.repetitions > 0">已复习 {{ c.repetitions }} 次</span>
      </div>
      <div class="acts">
        <!-- 每张卡各自的状态：以前共用 '__card__' 哨兵，导致任意一张在播时所有卡图标一起变 -->
        <button class="icb" :class="{ on: playingWord(c) }"
                :title="playingWord(c) ? '停止' : '朗读本卡词条'"
                @click="speakCard(c, $event)">
          {{ playingWord(c) ? '⏹' : '🔊' }}
        </button>
        <button v-if="exampleOf(c)" class="icb" :class="{ on: playingEg(c) }"
                :title="playingEg(c) ? '停止' : '朗读例句'"
                @click="speakExample(c, $event)">
          {{ playingEg(c) ? '⏹' : '💬' }}
        </button>
        <button class="icb" :class="{ ok: st(c) === 'mastered' }" title="标记已掌握"
                @click.stop="mark(c, 'mastered')">{{ st(c) === 'mastered' ? '✓' : '○' }}</button>
        <!-- 只有「我的生词」卷的卡能删（语料卡/批注卡的生命周期归素材与批注管） -->
        <button v-if="String(c.set_id).startsWith('my-')" class="icb" title="删除这张卡"
                @click.stop="delOne(c)">🗑</button>
      </div>

      <!-- 复习操作：仅「今天该复习」视图、已显示答案后 -->
      <div class="row" v-if="dueMode && revealedDue[c.id]" style="margin-top:11px">
        <button class="btn rev-again" @click.stop="review(c,'again')">忘了</button>
        <button class="btn rev-hard" @click.stop="review(c,'hard')">困难</button>
        <button class="btn pri rev-good" @click.stop="review(c,'good')">良好</button>
        <button class="btn rev-easy" @click.stop="review(c,'easy')">简单</button>
      </div>
      <div class="row" v-else-if="dueMode" style="margin-top:11px">
        <button class="btn sm" @click.stop="reveal(c)">显示答案</button>
        <span class="small muted">点卡片任意处也能显示</span>
      </div>

      <!-- 词汇 -->
      <template v-if="c.kind === 'word'">
        <div class="top">
          <span class="w">{{ c.data.w }}</span>
          <span class="ipa" v-if="c.data.ipa">{{ c.data.ipa }}</span>
          <span class="small muted" v-if="c.data.pos">{{ c.data.pos }}</span>
          <span class="tag c" v-if="c.data.cat">{{ (curSet && curSet.cats && curSet.cats[c.data.cat]) || c.data.cat }}</span>
        </div>
        <div class="cn mask">{{ c.data.cn }}</div>
        <div class="small muted mask" v-if="c.data.plain">{{ c.data.plain }}</div>
        <div class="small mask" v-if="c.data.colloc" style="margin-top:5px">
          <b>搭配：</b>{{ c.data.colloc }}
        </div>
        <div class="quote mask" v-if="c.data.en">
          <em>{{ c.data.en }}</em>
          <div class="small muted" v-if="c.data.zh">{{ c.data.zh }}</div>
          <div class="cite" v-if="c.data.cite">出处：{{ c.data.cite }}<span v-if="c.data.src"> · {{ c.data.src }}</span></div>
        </div>
        <div class="tipbox mask" v-if="c.data.tip">{{ c.data.tip }}</div>
      </template>

      <!-- 搭配 -->
      <template v-else-if="c.kind === 'colloc'">
        <div class="top">
          <span class="w">{{ c.data.w }}</span>
          <span class="ipa" v-if="c.data.ipa">{{ c.data.ipa }}</span>
          <span class="small muted" v-if="c.data.pos">{{ c.data.pos }}</span>
        </div>
        <div class="cn mask">{{ c.data.cn }}</div>
        <div class="small muted mask" v-if="c.data.sense">{{ c.data.sense }}</div>
        <table class="mask">
          <tr v-for="(col, i) in (c.data.cols || [])" :key="i">
            <td>{{ col[0] }}</td><td class="muted">{{ col[1] }}</td>
          </tr>
        </table>
        <div class="quote mask" v-if="c.data.en">
          <em>{{ c.data.en }}</em>
          <div class="small muted" v-if="c.data.zh">{{ c.data.zh }}</div>
        </div>
        <div class="tipbox mask" v-if="c.data.note">{{ c.data.note }}</div>
      </template>

      <!-- 翻译腔 -->
      <template v-else-if="c.kind === 'chinglish'">
        <div class="top">
          <span class="w" style="font-size:16px">{{ c.data.cn }}</span>
          <span class="tag" :class="LEVEL[c.data.level] || ''" v-if="c.data.level">风险 {{ c.data.level }}</span>
        </div>
        <div class="mask" style="margin-top:7px">
          <div><span class="tag r">❌ 直译</span> <span class="mono" style="text-decoration:line-through">{{ c.data.bad }}</span></div>
          <div style="margin-top:5px"><span class="tag g">✅ 地道</span> <b>{{ c.data.good }}</b></div>
        </div>
        <div class="small muted mask" v-if="c.data.why" style="margin-top:6px">{{ c.data.why }}</div>
        <div class="quote mask" v-if="c.data.en">
          <em>{{ c.data.en }}</em>
          <div class="small muted" v-if="c.data.zh">{{ c.data.zh }}</div>
          <div class="cite" v-if="c.data.cite">出处：{{ c.data.cite }}</div>
        </div>
      </template>

      <!-- 句式 -->
      <template v-else-if="c.kind === 'pattern'">
        <div class="top"><span class="w" style="font-size:16px">{{ c.data.n }}</span></div>
        <div class="quote mask" style="border-left-color:var(--violet)">
          <em class="mono" style="font-size:13px">{{ c.data.en }}</em>
          <div class="small muted">{{ c.data.zh }}</div>
        </div>
        <div class="small mask" v-if="c.data.use"><b>用途：</b>{{ c.data.use }}</div>
        <div class="small mask" v-if="c.data.eg" style="margin-top:6px">
          <b>例句：</b>{{ c.data.eg }}
          <div class="muted">{{ c.data.egZh }}</div>
        </div>
        <div class="small mask" v-if="c.data.vars && c.data.vars.length" style="margin-top:6px">
          <b>变体：</b><span v-for="(v, i) in c.data.vars" :key="i" class="tag" style="margin-right:4px">{{ v }}</span>
        </div>
        <div class="tipbox mask" v-if="c.data.warn">{{ c.data.warn }}</div>
      </template>

      <!-- 概念 -->
      <template v-else-if="c.kind === 'concept'">
        <div class="top"><span class="w" style="font-size:16px">{{ c.data.n }}</span></div>
        <div class="small mono muted mask">{{ c.data.en }}</div>
        <div class="mask" style="margin-top:6px"><b>{{ c.data.one }}</b></div>
        <div class="small mask" style="margin-top:5px">{{ c.data.body }}</div>
        <div class="quote mask" v-if="c.data.quote">
          <em>{{ c.data.quote }}</em>
          <div class="small muted" v-if="c.data.quoteZh">{{ c.data.quoteZh }}</div>
          <div class="cite" v-if="c.data.cite">出处：{{ c.data.cite }}</div>
        </div>
        <div class="tipbox mask" v-if="c.data.act">{{ c.data.act }}</div>
      </template>

      <!-- 批注卡（来自阅读器，读→标注→复习闭环） -->
      <template v-else-if="c.kind === 'anno'">
        <div class="top">
          <span class="w" style="font-size:17px">{{ c.data.w }}</span>
          <span class="ipa" v-if="c.data.reading">{{ c.data.reading }}</span>
          <span class="small muted" v-if="c.data.pos">{{ c.data.pos }}</span>
          <span class="tag v" style="margin-left:auto;margin-right:30px">我的批注</span>
        </div>

        <div class="cn mask" v-if="c.data.cn">{{ c.data.cn }}</div>
        <div class="small muted mask" v-else>（这条批注没命中字典释义，以你自己的笔记为准）</div>

        <div class="quote mask" style="margin-top:8px">
          <span class="small muted">原文上下文：</span>
          <template v-if="quiz && !revealed[c.id]">
            <span>{{ ctxParts(c).before }}</span>
            <span style="letter-spacing:1px">＿＿＿＿＿＿</span>
            <span>{{ ctxParts(c).after }}</span>
          </template>
          <template v-else>
            <span>{{ ctxParts(c).before }}</span>
            <span v-if="ctxParts(c).hit" style="background:#fff3c4;border-bottom:2px solid #e8c34a;border-radius:2px">
              {{ ctxParts(c).hit }}</span>
            <span>{{ ctxParts(c).after }}</span>
          </template>
        </div>

        <div class="tipbox mask" v-if="c.data.note">
          <b>我的笔记：</b>{{ c.data.note }}
        </div>

        <div class="row" style="margin-top:9px">
          <span class="cite" v-if="c.data.cite">出处：{{ c.data.cite }}</span>
        </div>
      </template>

      <!-- v2 新增类别（术语/抽象名词/中文一词多形/复述框架/尽调提问/商务语块）
           ⚠️ 必须放在最后作为兜底：没有这段，这 6 类卡片（约 569 张）只会显示页脚标签、内容全空 -->
      <template v-else>
        <!-- 产出模式：正面=中文，英文藏在 .mask 里（未揭晓时模糊） -->
        <template v-if="prod && canProd(c)">
          <div class="top">
            <span class="w" style="font-size:18px">{{ c.data.cn || c.data.plain }}</span>
            <span class="tag c" v-if="c.data.cat">{{ c.data.cat }}</span>
            <span class="tag" v-if="c.data.src">{{ c.data.src }}</span>
          </div>
          <div class="small muted mask" v-if="c.data.plain && c.data.cn">{{ c.data.plain }}</div>
          <div class="mask" style="margin-top:8px">
            <div class="w" style="font-size:18px;color:var(--primary-ink)">{{ c.data.w }}</div>
            <div class="ipa" v-if="c.data.ipa">{{ c.data.ipa }}</div>
            <div class="small" v-if="c.data.syll"><b>音节：</b>{{ c.data.syll }}</div>
            <div class="small" v-if="c.data.say"><b>念法：</b>{{ c.data.say }}</div>
          </div>
          <div class="tipbox mask" v-if="c.data.tip">{{ c.data.tip }}</div>
          <div class="quote mask" v-if="c.data.ex"><em>{{ c.data.ex }}</em></div>
        </template>
        <!-- 普通（接受性）模式 -->
        <template v-else>
          <div class="top">
            <span class="w">{{ c.data.w }}</span>
            <span class="ipa" v-if="c.data.ipa">{{ c.data.ipa }}</span>
            <span class="tag c" v-if="c.data.cat">{{ c.data.cat }}</span>
            <span class="tag" v-if="c.data.src">{{ c.data.src }}</span>
          </div>
          <div class="cn mask" v-if="c.data.cn">{{ c.data.cn }}</div>
          <div class="small muted mask" v-if="c.data.plain">{{ c.data.plain }}</div>
          <div class="small mask" v-if="c.data.syll"><b>音节：</b>{{ c.data.syll }}</div>
          <div class="small mask" v-if="c.data.say"><b>念法：</b>{{ c.data.say }}</div>

          <!-- 固定搭配（可点，跳到该搭配的卡） -->
          <div class="collocbox mask" v-if="c.data.colloc">
            <div class="small muted" style="margin-bottom:3px"><b>固定搭配</b></div>
            <span v-for="(cl, i) in String(c.data.colloc).split(/[;；|]/)" :key="'cl' + i"
                  class="cl" v-show="String(cl).trim()"
                  @click.stop="lookup(String(cl).trim(), $event, (c.data.en || c.data.eg || c.data.w), '搭配 · ' + c.data.w)">{{ String(cl).trim() }}</span>
          </div>

          <!-- 出处原句：完整句子，便于背诵（可点词） -->
          <div class="srcsent mask" v-if="c.data.en">
            <div class="small muted" style="margin-bottom:3px"><b>出处原句</b></div>
            <div class="s-en">
              <template v-for="(tok, i) in lkWords(c.data.en)" :key="'s' + i">
                <span v-if="!tok.w">{{ tok.t }}</span>
                <template v-else>{{ tok.pre }}<span class="wl" @click.stop="lookup(tok.w, $event, c.data.en, '出处句 · ' + c.data.w)">{{ tok.w }}</span>{{ tok.post }}</template>
              </template>
            </div>
            <div class="s-zh" v-if="c.data.zh">{{ c.data.zh }}</div>
            <div class="s-cite" v-if="c.data.cite || c.data.src_file">
              出处：{{ c.data.cite }}<span v-if="c.data.src_file"> · {{ c.data.src_file }}</span>
            </div>
          </div>

          <!-- 通用例句（非出处，便于理解，可点词） -->
          <div class="egbox mask" v-if="c.data.eg">
            <div class="small muted" style="margin-bottom:3px"><b>通用例句</b></div>
            <div class="eg-en">
              <template v-for="(tok, i) in lkWords(c.data.eg)" :key="'e' + i">
                <span v-if="!tok.w">{{ tok.t }}</span>
                <template v-else>{{ tok.pre }}<span class="wl" @click.stop="lookup(tok.w, $event, c.data.eg, '通用例句 · ' + c.data.w)">{{ tok.w }}</span>{{ tok.post }}</template>
              </template>
            </div>
            <div class="eg-zh" v-if="c.data.egZh">{{ c.data.egZh }}</div>
          </div>

          <div class="tipbox mask" v-if="c.data.tip">{{ c.data.tip }}</div>
        </template>
      </template>

      <!-- 共用页脚：所有卡型都有「回到原文」+「互查」 -->
      <div class="row" style="margin-top:9px">
        <span class="tag">{{ KINDLABEL[c.kind] || c.kind }}</span>
        <span class="small muted">{{ c.set_id }}</span>
        <div class="sp" style="flex:1"></div>
        <button class="btn gho sm" title="看这张卡在源文档里的原文段落（弹窗可拖动）"
                @click.stop="toSource(c)">回到原文</button>
        <span class="small muted" style="cursor:pointer" title="点例句里的任意英文词，也能跳转"
              @click.stop="lookup(c.data.w, $event, (c.data.en || c.data.eg || ''), '本卡：' + c.data.w)">互查 ↗</span>
      </div>
    </div>
  </div>

  <!-- v2 卡片互通：点例句里的词 → 查它有没有卡 → 一键跳过去
       弹窗带标题栏，可按住标题栏拖动 -->
  <div v-if="lk.show" class="pnl" :style="{ left: lk.x + 'px', top: lk.y + 'px', width: '330px' }" @click.stop>
    <div class="pnl-bar" @mousedown="dragStart($event, lk)">
      <span class="pnl-title">🔍 互查 · {{ lk.word }}</span>
      <div class="sp" style="flex:1"></div>
      <button class="icb" title="关闭" @click="lkClose">×</button>
    </div>
    <div class="pnl-body">
      <div v-if="lk.loading" class="lk-none">查询中…</div>
      <div v-if="lk.err" class="lk-none" :style="{ color: 'var(--amber)' }">{{ lk.err }}</div>
      <div v-if="!lk.loading && !lk.cards.length" class="lk-none">
        <div>没有这个词的卡片。 <b>就地建一张？</b></div>
        <div class="small muted" style="margin-top:5px;line-height:1.7">
          释义可以留空 —— 会自动用 AI 补上中文释义 + <b>美式音标</b> + 词性。建好的卡进
          「我的生词 · 手动添加」这一卷，随时可改。
        </div>
        <div v-if="lk.ctx" class="lk-ctx">「{{ lk.ctx.length > 90 ? lk.ctx.slice(0, 90) + '…' : lk.ctx }}」</div>
        <input class="input" style="margin-top:8px;font-size:13px;padding:5px 8px"
               v-model="lk.cn" placeholder="中文释义（留空 = 让 AI 补）" @keydown.enter.stop="makeCard" />
        <div class="row" style="margin-top:8px">
          <button class="btn sm pri" :disabled="lk.busy" @click="makeCard">
            {{ lk.busy ? '建卡中…' : (lk.cn ? '保存并建立这张卡' : '⚡ 直接建卡（AI 补释义）') }}
          </button>
          <button class="btn gho sm" @click="lkClose(); props.go('reader')">去阅读器划词 →</button>
        </div>
      </div>
      <div v-else>
        <div v-for="c2 in lk.cards" :key="c2.id" style="margin-bottom:11px">
          <div class="lk-w">{{ c2.w }}</div>
          <div class="ipa" v-if="c2.ipa">{{ c2.ipa }}</div>
          <div class="lk-cn" v-if="c2.cn">{{ c2.cn }}</div>
          <div class="lk-eg" v-if="c2.en">{{ c2.en }}</div>
          <div class="row" style="margin-top:6px">
            <button class="btn sm" @click="lkGo(c2)">跳到这张卡</button>
            <span class="small muted">{{ c2.set_title || c2.set_id }}</span>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- 回到原文：弹窗显示源文档段落 + 前后文，同样可拖动 -->
  <div v-if="srcP.show" class="pnl" :style="{ left: srcP.x + 'px', top: srcP.y + 'px', width: '520px' }" @click.stop>
    <div class="pnl-bar" @mousedown="dragStart($event, srcP)">
      <span class="pnl-title">📄 回到原文 · {{ srcP.w }}</span>
      <div class="sp" style="flex:1"></div>
      <button class="icb" title="关闭" @click="srcClose">×</button>
    </div>
    <div class="pnl-body">
      <div v-if="srcP.loading" class="lk-none">读取中…</div>
      <div v-else-if="srcP.err" class="lk-none">{{ srcP.err }}</div>
      <template v-else-if="srcP.data && srcP.data.found">
        <div class="small muted" style="margin-bottom:7px">
          {{ srcP.data.material_title }} · 第 {{ srcP.data.hit_seq }} 段
        </div>
        <div class="srcseg">
          <div v-for="s in srcP.data.segments" :key="s.seq" class="srcseg-p" :class="{ hit: s.is_hit }">
            <span class="srcseg-no">{{ s.seq }}</span><span>{{ s.text }}</span>
          </div>
        </div>
        <div class="row" style="margin-top:10px">
          <button class="btn sm pri" @click="srcGoReader">在阅读器里打开全文</button>
          <span class="small muted">可用上方标题栏拖动窗口</span>
        </div>
      </template>
      <!-- 没有链到源文档段落时：也要给出有内容的信息，不能空着 -->
      <template v-else-if="srcP.data">
        <div class="lk-w" v-if="srcP.data.w">{{ srcP.data.w }}</div>
        <div class="lk-cn" v-if="srcP.data.cn">{{ srcP.data.cn }}</div>
        <div class="srcsent" style="margin-top:9px">
          <div class="small muted" style="margin-bottom:3px"><b>出处原句</b></div>
          <div class="s-en">{{ srcP.data.en || '（这张卡没有出处原句）' }}</div>
          <div class="s-zh" v-if="srcP.data.zh">{{ srcP.data.zh }}</div>
          <div class="s-cite" v-if="srcP.data.cite || srcP.data.src_file">
            出处：{{ srcP.data.cite }}<span v-if="srcP.data.src_file"> · {{ srcP.data.src_file }}</span>
          </div>
        </div>
        <div class="egbox" v-if="srcP.data.eg">
          <div class="small muted" style="margin-bottom:3px"><b>通用例句</b></div>
          <div class="eg-en">{{ srcP.data.eg }}</div>
        </div>
        <div class="tipbox" v-if="srcP.data.note"><b>我的笔记：</b>{{ srcP.data.note }}</div>
        <div class="small muted" style="margin-top:9px">
          这张卡没有链到源文档段落。已链回原文的卡（如投资术语、合同术语）会显示上下文段落。
        </div>
        <div class="row" style="margin-top:9px" v-if="srcP.data.material_id">
          <button class="btn sm pri" @click="srcGoReader">在阅读器里打开全文</button>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
/* 视图切换标签页 */
.tab {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 7px 14px; border-radius: var(--r-pill);
  border: 1px solid var(--border); background: var(--surface);
  color: var(--ink-2); cursor: pointer; font-size: 13.5px; user-select: none;
}
.tab:hover { border-color: var(--primary-line); color: var(--primary-ink); }
.tab.on { background: var(--primary); border-color: var(--primary); color: #fff; font-weight: 600; }
.tab .n { margin-left: 2px; font-size: 11.5px; opacity: .85; }
.tab.on .n { color: #fff; }

/* SM-2 复习四档按钮（颜色对应记忆强度） */
.btn.rev-again { background: var(--red); border-color: var(--red); color: #fff; }
.btn.rev-again:hover { background: #e23a20; border-color: #e23a20; color: #fff; }
.btn.rev-hard { background: var(--amber); border-color: var(--amber); color: #fff; }
.btn.rev-hard:hover { background: #f06a36; border-color: #f06a36; color: #fff; }
.btn.rev-easy { background: var(--violet); border-color: var(--violet); color: #fff; }
.btn.rev-easy:hover { background: #6a3ad8; border-color: #6a3ad8; color: #fff; }

/* 遗忘曲线：逾期紧急度 */
.cc-urg-high { border-color: var(--red); box-shadow: 0 0 0 2px var(--red-soft); }
.cc-urg-mid { border-color: var(--amber); }
.due-flag { margin-bottom: 8px; }
.due-badge { display: inline-block; padding: 2px 10px; border-radius: var(--r-pill); font-size: 12px; font-weight: 600; }
.due-badge.u-high { background: var(--red); color: #fff; }
.due-badge.u-mid { background: var(--amber); color: #fff; }
.due-badge.u-low { background: var(--green-soft); color: var(--green); }
.due-badge.u-today { background: var(--primary-soft); color: var(--primary-ink); }
</style>
