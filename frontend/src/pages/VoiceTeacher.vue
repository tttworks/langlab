<script setup>
/**
 * 虚拟老师 —— 自由对话 + 实时纠错，语音闭环，双语讲解，多会话历史。
 *
 * 布局：左侧独立「岛」= 虚拟形象（纯 SVG，随状态动：待命/聆听/思考/说话，可点击交互，
 *      角色可在 teacherCharacters.js 里任意增删）+ 历史会话列表；右侧对话与纠错；底部大麦克风。
 *
 * 闭环：点麦开始录音 → 浏览器语音识别 → /api/teacher/chat（DeepSeek，可调用本系统学习数据）
 *      → 外语回复 + 中文讲解 + 纠错 → 只朗读老师说的话（中文讲解块不念）→ 落库到会话。
 *
 * 朗读的可控性（用户要求）：
 *   - 顶栏「🔊 自动朗读 / 🔇 不朗读」总开关，关掉后老师只出文字不出声；
 *   - 每条发言下面都有「🔊 念这句」，历史发言随时可重念；正在念时变成「⏹ 停止」。
 *
 * 四条交互纪律（都是踩过的坑）：
 *  1. 识别只累积、绝不自动发送，点第二下才发 —— 否则长句说不完就被发出去。
 *  2. 发送后输入框不再回显识别到的文字（abort + committed 双重拦截；rec.stop() 是异步的，
 *     会把残留结果补发一次，那次回调发生在清空输入框之后）。
 *  3. 输入框用 textarea 自动增高，长句不再被单行遮挡。
 *  4. 类名不能撞 theme.css 的全局类：`.bar` 在全局是「进度条」（height:7px; overflow:hidden），
 *     会把工具条压扁 → 本组件一律用 `.tbar` / `.pchip` 这类带前缀的名字。
 */
import { ref, computed, watch, onMounted, onBeforeUnmount, inject, nextTick } from 'vue'
import { useTtsSettings } from '../useTtsSettings.js'
import { CHARACTERS } from '../data/teacherCharacters.js'
import TeacherAvatar from '../components/TeacherAvatar.vue'

const api = inject('api')
const say = inject('say')
defineProps({ route: Object, go: Function })

/* ---------------- 目标语言 / 识别语言 / 讲解方式 ---------------- */
// zh-CN 是特例：不是「学中文」，是「用中文一起想问题」的思维伙伴模式
const LANGS = [
  { code: 'en-US', label: '英语', asr: 'en-US', tts: 'en-US' },
  { code: 'ja-JP', label: '日语', asr: 'ja-JP', tts: 'ja-JP' },
  { code: 'zh-CN', label: '中文', asr: 'zh-CN', tts: 'zh-CN' },
  { code: 'th-TH', label: '泰语', asr: 'th-TH', tts: 'th-TH' },
]
/** 我说的语言 —— 与目标语言解耦：可以练英语但用中文提问 */
const ASR_LANGS = [
  { code: 'en-US', label: 'English' },
  { code: 'zh-CN', label: '中文' },
  { code: 'ja-JP', label: '日本語' },
  { code: 'th-TH', label: 'ไทย' },
  { code: 'auto', label: '自动' },
]
/** 开场白按目标语言给 */
const GREET = {
  'en-US': 'Just talk naturally. I\'ll reply, then correct your mistakes.',
  'ja-JP': '日本語で自由に話してください。言い終わったら、間違いを直します。',
  'th-TH': 'ลองพูดภาษาไทยดูสิ ฉันจะช่วยแก้ให้',
  'zh-CN': '想到什么就说。我不教语言 —— 帮你把事想清楚，也给你不一样的角度。',
}
const EXPLAINS = [
  { code: 'bilingual', label: '双语' },
  { code: 'target', label: '只外语' },
  { code: 'zh', label: '中文为主' },
]

const lang = ref(localStorage.getItem('langlab.teacher.lang') || 'en-US')
const asrLang = ref(localStorage.getItem('langlab.teacher.asr') || 'en-US')
const explain = ref(localStorage.getItem('langlab.teacher.explain') || 'bilingual')
const charId = ref(localStorage.getItem('langlab.teacher.char') || CHARACTERS[0].id)

/**
 * 两种模式，界面上完全分开：
 *   lang  —— 外语练习（英/日/泰）：外语回复 + 中文讲解 + 纠错
 *   think —— 中文聊想法：不教语言，接住你的想法、给角度、追着往下想
 * zh-CN 就是 think 模式；切回 lang 模式时恢复上次练的那门外语。
 */
const isThinkingMode = computed(() => lang.value === 'zh-CN')
const lastForeign = ref(localStorage.getItem('langlab.teacher.lastForeign') || 'en-US')
const FOREIGN = ['en-US', 'ja-JP', 'th-TH']

function setMode(m) {
  if (m === 'think') { lang.value = 'zh-CN'; return }
  lang.value = FOREIGN.includes(lastForeign.value) ? lastForeign.value : 'en-US'
}

// 换语言时顺手把角色切到对应的那位（比如切到中文 → 玫；泰语 → Mali）
watch(lang, (v) => {
  localStorage.setItem('langlab.teacher.lang', v)
  if (v !== 'zh-CN') {
    lastForeign.value = v
    localStorage.setItem('langlab.teacher.lastForeign', v)
  }
  if (curChar.value.lang !== v) {
    const m = CHARACTERS.find((c) => c.lang === v)
    if (m) charId.value = m.id
  }
})
watch(asrLang, (v) => localStorage.setItem('langlab.teacher.asr', v))
watch(explain, (v) => localStorage.setItem('langlab.teacher.explain', v))
// 点角色 = 连语言一起切。角色是语言的唯一来源，
// 否则会出现「岛上是 Yuki、顶栏却停在英语」这种对不上的状态。
watch(charId, (v) => {
  localStorage.setItem('langlab.teacher.char', v)
  const c = CHARACTERS.find((x) => x.id === v)
  if (c && c.lang && c.lang !== lang.value) lang.value = c.lang
})

const curLang = computed(() => LANGS.find((l) => l.code === lang.value) || LANGS[0])
const curChar = computed(() => CHARACTERS.find((c) => c.id === charId.value) || CHARACTERS[0])
const msgs = ref([])        // {role:'me'|'ai', text, zh, points:[], corrections:[], tools:[]}
const langLabel = (c) => (LANGS.find((l) => l.code === c) || {}).label || ''
const input = ref('')
const thinking = ref(false)
const listening = ref(false)
const speaking = ref(false)
const speakingIdx = ref(-1)      // 正在念的是第几条（-1 = 没在念）
/** 总开关：老师说完后要不要自动念出来。关掉就只出文字。 */
const autoSpeak = ref(localStorage.getItem('langlab.teacher.autospeak') !== '0')
watch(autoSpeak, (v) => localStorage.setItem('langlab.teacher.autospeak', v ? '1' : '0'))
const scrollEl = ref(null)
const taEl = ref(null)

const Rec = typeof window !== 'undefined' ? (window.SpeechRecognition || window.webkitSpeechRecognition) : null
const asrOk = computed(() => !!Rec)
/** 能不能录音（发音诊断需要）。有 Web Speech 或 MediaRecorder 其一就能点麦 */
const recOk = computed(() => typeof MediaRecorder !== 'undefined' && !!navigator.mediaDevices)
const micOk = computed(() => asrOk.value || recOk.value)

/** 虚拟形象当前状态 → 驱动 SVG/CSS 动画 */
const avatarState = computed(() => {
  if (listening.value) return 'listening'
  if (thinking.value) return 'thinking'
  if (speaking.value) return 'speaking'
  return 'idle'
})
const stateText = computed(() => ({
  idle: '待命中 · 点我可重听',
  listening: '正在听你说…',
  thinking: '老师在想…',
  speaking: '老师说话中…',
})[avatarState.value])

/* ---------------- 语音识别（点击开/点击关） ---------------- */
let rec = null
let finalText = ''
let committed = false   // 已发送 —— 之后的识别回调一律丢弃

// 自动模式：拿不到结果就换一种语言再试（Web Speech 单实例只能挂一种语言，
// 所以用「本轮没听出东西 → 换语言重启」来兜底中英混说）
const autoSeq = computed(() => ({
  'ja-JP': ['ja-JP', 'zh-CN'],
  'th-TH': ['th-TH', 'zh-CN'],
  'zh-CN': ['zh-CN', 'en-US'],
}[lang.value] || ['en-US', 'zh-CN']))
let autoIdx = 0
const curAsr = () => (asrLang.value === 'auto' ? autoSeq.value[autoIdx % autoSeq.value.length] : asrLang.value)

function initRec() {
  if (!Rec) return
  rec = new Rec()
  rec.continuous = true      // 持续听，不因停顿自动收尾
  rec.interimResults = true
  rec.maxAlternatives = 1

  rec.onresult = (e) => {
    if (committed) return                 // 已经发出去了，别再把文字塞回输入框
    let interim = ''
    for (let i = e.resultIndex; i < e.results.length; i++) {
      const r = e.results[i]
      if (r.isFinal) finalText += r[0].transcript
      else interim += r[0].transcript
    }
    input.value = (finalText + interim).trim()   // 只累积，绝不自动发送
  }

  rec.onerror = (ev) => {
    if (ev.error === 'no-speech' || ev.error === 'aborted') return
    say('识别失败：' + ev.error + '（可改用输入框打字）')
  }

  rec.onend = () => {
    if (listening.value) {                // 还在录音状态 → 自动续听，长句不被掐断
      if (asrLang.value === 'auto' && !finalText.trim()) autoIdx++   // 这轮没听出来 → 换语言
      try { rec.lang = curAsr(); rec.start(); return } catch (e) {}
    }
  }
}
onMounted(initRec)
onBeforeUnmount(() => { try { rec && rec.abort() } catch (e) {} })
watch([lang, asrLang], () => { autoIdx = 0 })

function beginListen() {
  if (listening.value) return
  committed = false
  finalText = ''
  input.value = ''
  listening.value = true
  startRecorder()   // 与识别解耦：没有 Web Speech 也照录，发音诊断只需要音频
  if (!Rec) {
    say('这个浏览器没有语音识别 —— 录音已开始，说完点第二次可做发音诊断；文字请自己打')
    return
  }
  try {
    rec.lang = curAsr()
    rec.start()
  } catch (e) {
    say('无法启动录音：' + e.message)
  }
}
/** abort 而非 stop：stop 会把残留结果补发一次，那正是"发送后又冒出文字"的根源 */
function endListen() {
  try { rec && rec.abort() } catch (e) {}
  listening.value = false
  stopRecorder()           // 停录 → 立刻上传诊断（与下面的 send() 并行，不阻塞）
}

/* ---------------- 发音诊断（旁路，不阻塞对话） ----------------
 * 为什么要有：Web Speech 只给文本、音频不留，老师无法判断「念错了」还是「识别错了」。
 * 这里用 MediaRecorder 把音频留下 → 本地 whisper 做词级分析 → 挂到「我」那条消息下面。
 * 延迟实测：8 秒语音约 3.2 秒，且与老师回话并行，用户感知不到。
 */
let mediaRec = null
let chunks = []
let micStream = null
const recErr = ref('')

function pickMime() {
  if (typeof MediaRecorder === 'undefined') return ''
  for (const m of ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4']) {
    try { if (MediaRecorder.isTypeSupported(m)) return m } catch (e) {}
  }
  return ''
}

async function startRecorder() {
  recErr.value = ''
  if (typeof MediaRecorder === 'undefined' || !navigator.mediaDevices) {
    recErr.value = '这个浏览器不支持录音（发音诊断需要）'
    return
  }
  try {
    micStream = await navigator.mediaDevices.getUserMedia({
      // ⚠️ noiseSuppression 必须关：它会削掉 s / sh / th / f 这些摩擦音，
      //    而它们恰恰是发音评估最需要的信息。echoCancellation 留着（避免把老师的 TTS 又录回去）。
      audio: { channelCount: 1, echoCancellation: true, noiseSuppression: false, autoGainControl: true },
    })
    chunks = []
    const mime = pickMime()
    mediaRec = new MediaRecorder(micStream, mime ? { mimeType: mime } : undefined)
    mediaRec.ondataavailable = (e) => { if (e.data && e.data.size) chunks.push(e.data) }
    mediaRec.start(250)
  } catch (e) {
    recErr.value = '拿不到麦克风：' + (e.message || e)
  }
}

/** 停录并上传；结果挂到「这条发言」上。
 *  时序：onMicClick 先 endListen()（本函数）再 send()，而 mediaRecorder.stop() 的 onstop 是异步的，
 *  所以此刻用 msgs.length 记下「即将被 push 的那条消息」的下标，等 onstop 回来再认领。 */
function stopRecorder() {
  const rec0 = mediaRec
  mediaRec = null
  if (!rec0 || rec0.state === 'inactive') { releaseMic(); return }
  const targetIdx = msgs.value.length
  const myLang = String(lang.value).slice(0, 2)
  rec0.onstop = async () => {
    releaseMic()
    if (!chunks.length) return
    const blob = new Blob(chunks, { type: rec0.mimeType || 'audio/webm' })
    chunks = []
    if (blob.size < 2000) return            // 太短，别浪费一次诊断
    const msg = msgs.value[targetIdx]
    if (!msg || msg.role !== 'me') return   // 这轮没真的发出去（比如识别为空）→ 不挂了
    msg.pronPending = true
    try {
      const r = await api.teacherAssess(blob, myLang, '')
      msg.pron = r
      msg.pronPending = false
      // 刻意不自动滚动：用户此时多半正在读老师的话，别把视野拽走（诊断卡挂在「我」那条消息下，往上一滚就能看到）
      // 再补一条针对性点评。刻意放在诊断之后、且失败也不打扰用户 ——
      // 诊断卡本身已经有用了，点评只是加分项。
      if (r.flags && r.flags.length) {
        msg.tipPending = true
        try {
          const t = await api.teacherPronounce({ lang: myLang, text: r.text || '', flags: r.flags })
          msg.tip = t.tip || ''
        } catch (e) { msg.tip = '' }
        msg.tipPending = false
      }
    } catch (e) {
      msg.pronPending = false
      msg.pronErr = String(e.message || e)
    }
  }
  try { rec0.stop() } catch (e) { releaseMic() }
}

function releaseMic() {
  try { micStream && micStream.getTracks().forEach((t) => t.stop()) } catch (e) {}
  micStream = null
}
onBeforeUnmount(releaseMic)

/** 诊断卡上显示什么：把「念得吃力」的词挑出来，最多 6 个 */
function pronFlags(m) {
  return ((m.pron && m.pron.flags) || []).slice(0, 6)
}
const REASON_ZH = { low_conf: '识别吃力', long_dur: '拖了很久' }

/** 单击切换：一下开始录音，再一下结束并发送（不再需要按住） */
function onMicClick() {
  if (listening.value) { endListen(); send() }
  else beginListen()
}

/* ---------------- 朗读（复用项目 TTS 网关） ---------------- */
const tts = useTtsSettings()
// 只念外语正文，所以只需要目标语言的音色
watch(lang, () => { tts.ensureVoices(curLang.value.tts) }, { immediate: true })

let audio = null
function stopAudio() {
  try { if (audio) { audio.pause(); audio = null } } catch (e) {}
  try { window.speechSynthesis.cancel() } catch (e) {}
}
function speakOnce(text, vLang, prov = '', voiceOverride = '') {
  return new Promise(async (resolve) => {
    if (!text) return resolve(false)
    const provider = prov || tts.curProvider.value || 'edge'
    const voice = voiceOverride || tts.voiceForProvider(provider, vLang)
    try {
      const res = await fetch('/api/tts', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ provider, voice, lang: vLang, text, rate: '+0%' }),
      })
      if (!res.ok) throw new Error('HTTP ' + res.status)
      const blob = await res.blob()
      audio = new Audio(URL.createObjectURL(blob))
      audio.onended = () => resolve(true)
      audio.onerror = () => resolve(false)
      await audio.play()
      return
    } catch (e) {
      if ('speechSynthesis' in window) {
        const u = new SpeechSynthesisUtterance(text)
        u.lang = vLang
        u.onend = () => resolve(true)
        u.onerror = () => resolve(false)
        window.speechSynthesis.speak(u)
        return
      }
      say('朗读失败：' + e.message)
      resolve(false)
    }
  })
}
/** 只念老师说的话（外语正文）。中文讲解块不念——那是给人看的，不是给人听的。 */
async function speakOne(text, idx = -1) {
  stopAudio()
  speakingIdx.value = idx
  speaking.value = true
  await speakOnce(text, curLang.value.tts, curProv.value, curVoiceId.value)   // 用当前角色自己的引擎+音色
  speaking.value = false
  speakingIdx.value = -1
}
function stopSpeak() { stopAudio(); speaking.value = false; speakingIdx.value = -1 }

/* ---------------- 每个角色一套固定的「语言 + 引擎 + 音色」 ----------------
 * 取值优先级：界面里改过的（localStorage） > teacherCharacters.js 里的默认值 > 全局设置。
 * 引擎和语言都是角色专属的 —— 玫走 Edge 中文、Mali 走 MiniMax 泰语，互不干扰
 * （前提是音色表按「引擎:语言」分别缓存，见 useTtsSettings 的 ensureVoicesFor / voiceForProvider）。
 */
const charTts = ref(JSON.parse(localStorage.getItem('langlab.teacher.charTts') || '{}'))
function persistCharTts() {
  localStorage.setItem('langlab.teacher.charTts', JSON.stringify(charTts.value))
}
const pickOpen = ref(false)
const pickVoice = ref('')
const pickProvider = ref('')

/** 当前角色最终生效的配置；语言对不上就当没配，避免换语言后串味 */
const curCfg = computed(() => {
  const want = curChar.value.lang
  for (const c of [charTts.value[curChar.value.id], curChar.value.tts]) {
    if (c && c.voice && (!c.lang || !want || c.lang === want)) return c
  }
  return null
})
const curProv = computed(() => (curCfg.value && curCfg.value.provider) || tts.conf.provider || 'edge')
const curProvLabel = computed(() => {
  const p = tts.providers.value.find((x) => x.id === curProv.value)
  return p ? p.label : curProv.value
})
/** 音色列表要按「角色自己的语言」取，不是按顶栏语言（两者通常一致，但角色是权威） */
const cfgLang = computed(() => curChar.value.lang || curLang.value.tts)
const voiceOverridden = computed(() => !!curCfg.value)
const curVoiceId = computed(() =>
  (curCfg.value && curCfg.value.voice) || tts.voiceForProvider(curProv.value, cfgLang.value))
const voiceList = computed(() => tts.entryForProvider(curProv.value, cfgLang.value).voices || [])
const curVoiceName = computed(() => {
  const v = voiceList.value.find((x) => x.id === curVoiceId.value)
  return v ? v.name : (curVoiceId.value || '未设置')
})
const voiceGroups = computed(() => tts.shownGroupsFor(curProv.value, cfgLang.value))
const voiceHint = computed(() => {
  const e = tts.entryForProvider(curProv.value, cfgLang.value)
  if (e.loading) return '正在载入音色列表…'
  if (e.error) return '加载失败：' + e.error
  const p = tts.providers.value.find((x) => x.id === curProv.value)
  return (p && p.note) || ''
})
const voiceScopeAll = computed(() => tts.voiceScope.value === 'all')
const hiddenVoiceCount = computed(() => tts.hiddenGroupCountFor(curProv.value, cfgLang.value))

// 切角色 / 改引擎，都要保证对应「引擎:语言」的音色表已载入
watch([curProv, cfgLang], () => { tts.ensureVoicesFor(curProv.value, cfgLang.value) }, { immediate: true })

function openPicker() {
  pickOpen.value = !pickOpen.value
  if (!pickOpen.value) return
  pickProvider.value = curProv.value
  pickVoice.value = curVoiceId.value
  tts.ensureVoicesFor(pickProvider.value, cfgLang.value)
}
async function onPickProvider() {
  await tts.ensureVoicesFor(pickProvider.value, cfgLang.value)
  pickVoice.value = tts.voiceForProvider(pickProvider.value, cfgLang.value) || ''
}
function applyVoice() {
  if (!pickVoice.value) { say('先选一个音色'); return }
  charTts.value = {
    ...charTts.value,
    [curChar.value.id]: { provider: pickProvider.value, voice: pickVoice.value, lang: cfgLang.value },
  }
  persistCharTts()
  pickOpen.value = false
  say('已把 ' + curChar.value.name + '（' + langLabel(cfgLang.value) + '）的声音设为「' + curVoiceName.value + '」')
}
function clearVoice() {
  const c = { ...charTts.value }
  delete c[curChar.value.id]
  charTts.value = c
  persistCharTts()
  pickOpen.value = false
  say(curChar.value.name + ' 已恢复为默认设置')
}
const SAMPLE = {
  'ja-JP': 'こんにちは。今日も一緒に練習しましょう。',
  'th-TH': 'สวัสดีค่ะ วันนี้เราฝึกภาษาไทยกันนะคะ',
  'zh-CN': '你好，我们开始吧。',
}
async function tryVoice() {
  if (!pickVoice.value) { say('先选一个音色'); return }
  await speakOnce(SAMPLE[curLang.value.tts] || 'Hello, let us practise together.',
    curLang.value.tts, pickProvider.value, pickVoice.value)
}

/** 手动重念对话里任意一条 —— 历史发言随时可回放；正在念这条就停止 */
function replay(i) {
  const m = msgs.value[i]
  if (!m || !m.text) return
  if (speakingIdx.value === i) { stopSpeak(); return }
  speakOne(m.text, i)
}

/* ---------------- 多会话历史 ---------------- */
const sessions = ref([])
const curSid = ref(0)

async function loadSessions() {
  try {
    const r = await api.teacherSessions()
    sessions.value = r.sessions || []
  } catch (e) { /* 静默：列表失败不影响对话 */ }
}
/** 历史也按模式分组：外语练习 / 中文聊想法 */
const sessionGroups = computed(() => [
  { key: 'lang', title: '🗣 外语练习', items: sessions.value.filter((s) => s.lang !== 'zh-CN') },
  { key: 'think', title: '💭 中文聊想法', items: sessions.value.filter((s) => s.lang === 'zh-CN') },
].filter((g) => g.items.length))
function newSession() {
  stopSpeak()
  if (listening.value) endListen()
  curSid.value = 0
  msgs.value = []
  input.value = ''
  nextTick(autosize)
}
async function openSession(id) {
  if (listening.value) endListen()
  stopSpeak()
  try {
    const r = await api.teacherSession(id)
    curSid.value = id
    msgs.value = (r.messages || []).map((m) => ({
      role: m.role, text: m.text || '', zh: m.zh || '',
      corrections: m.corrections || [], tools: m.tools || [], points: m.points || [],
    }))
    const s = r.session || {}
    if (s.lang) lang.value = s.lang
    if (s.char_id) charId.value = s.char_id
    if (s.explain) explain.value = s.explain
    nextTick(scrollBottom)
  } catch (e) { say('打不开这个会话：' + e.message) }
}
async function delSession(id) {
  if (!confirm('删除这个会话？记录不可恢复。')) return
  try {
    await api.teacherDelSession(id)
    if (curSid.value === id) newSession()
    await loadSessions()
  } catch (e) { say('删除失败：' + e.message) }
}
async function renameSession(id) {
  const t = prompt('给这个会话起个名字：')
  if (!t || !t.trim()) return
  try { await api.teacherRenameSession(id, t.trim()); await loadSessions() } catch (e) { say('改名失败：' + e.message) }
}

/* ---------------- 对话 ---------------- */
async function send() {
  const text = (input.value || '').trim()
  if (!text || thinking.value) return
  committed = true          // 之后识别回调一律忽略
  input.value = ''
  msgs.value.push({ role: 'me', text, zh: '', corrections: [], tools: [] })
  thinking.value = true
  scrollBottom()

  const history = msgs.value
    .filter((m) => m.role === 'me' || m.role === 'ai')
    .slice(-20)
    .map((m) => ({ role: m.role === 'me' ? 'user' : 'assistant', content: m.text }))

  try {
    const r = await api.teacherChat({
      lang: lang.value, explain: explain.value, char_id: charId.value,
      session_id: curSid.value || 0, messages: history,
    })
    msgs.value.push({
      role: 'ai', text: r.reply || '', zh: r.zh || '', points: r.points || [],
      corrections: r.corrections || [], tools: r.tools_used || [],
    })
    if (r.session_id) curSid.value = r.session_id
    scrollBottom()
    loadSessions()          // 列表刷新（新会话/新标题）
    if (autoSpeak.value && r.reply) await speakOne(r.reply, msgs.value.length - 1)
  } catch (e) {
    say('老师没回应：' + e.message)
  }
  thinking.value = false
  // ⚠️ 这里原本还有一次 scrollBottom()：它会在朗读完之后再滚一次，
  //    把用户正在读的位置拽走。定位已经在收到回复时做过，这里不再动（2026-10-03 修）。
}

/**
 * 让最新一条消息「被第一眼看到」。
 *
 * ⚠️ 以前一律 `scrollTop = scrollHeight`（滚到底）—— 但老师那句话（`.tx`）在每个消息块的**最上面**，
 * 下面跟着中文讲解 + 纠错列表，于是一整块被顶出视野：用户必须手动往上滚才知道老师说了什么（2026-10-03 修）。
 *
 * 现在的规则：
 *  - 整块放得下 → 滚到底（全都能看见，不留白）
 *  - 放不下   → 把这一块的**顶部**对齐容器顶部，保证「老师说的话」第一眼就在；
 *               讲解与纠错往下滚一点就能看，内容一律不删。
 */
function scrollToLatest() {
  nextTick(() => {
    const el = scrollEl.value
    if (!el) return
    const all = el.querySelectorAll('.bwrap')
    const last = all.length ? all[all.length - 1] : null
    if (!last) { el.scrollTop = el.scrollHeight; return }
    if (last.offsetHeight <= el.clientHeight - 8) {
      el.scrollTop = el.scrollHeight
      return
    }
    // 用 getBoundingClientRect 算相对容器的偏移，不依赖 offsetParent 是否为 .vtlog
    const top = last.getBoundingClientRect().top - el.getBoundingClientRect().top + el.scrollTop
    el.scrollTop = Math.max(0, top - 6)
  })
}
/** 兼容旧调用点：恢复历史会话、跳到底部这类场景 */
function scrollBottom() { scrollToLatest() }

/* textarea 自动增高：长句不再被单行裁掉 */
function autosize() {
  const el = taEl.value
  if (!el) return
  el.style.height = 'auto'
  el.style.height = Math.min(el.scrollHeight, 168) + 'px'
}
watch(input, () => nextTick(autosize))
onMounted(() => { nextTick(autosize); loadSessions(); tts.loadProviders() })

function onEnter(e) { if (!e.shiftKey) { e.preventDefault(); send() } }

function clearChat() { msgs.value = []; stopSpeak(); nextTick(autosize) }

/** 点虚拟形象：说话中就停止，否则重听老师上一句 */
function pokeAvatar() {
  if (speaking.value) { stopSpeak(); return }
  const last = [...msgs.value].reverse().find((m) => m.role === 'ai' && m.text)
  if (last) speakOne(last.text, msgs.value.indexOf(last))
  else say('还没有老师的话可以重听，先说一句吧')
}

const greeting = computed(() => GREET[lang.value] || GREET['en-US'])
</script>

<template>
  <div class="vt">
    <!-- ============ 左侧：虚拟形象岛 + 历史会话 ============ -->
    <aside class="card island">
      <TeacherAvatar :char="curChar" :state="avatarState" @poke="pokeAvatar" />

      <div class="nm">{{ curChar.name }}</div>
      <div class="role">{{ curChar.role }}</div>
      <div class="st" :class="avatarState">{{ stateText }}</div>

      <!-- 角色切换：新增角色只改 teacherCharacters.js -->
      <div class="chars">
        <button v-for="c in CHARACTERS" :key="c.id" class="pchip" :class="{ on: c.id === charId }"
                :title="c.role + ' · ' + c.tag" @click="charId = c.id">
          <!-- 图片形象显示缩略头像；矢量形象显示发色圆点 -->
          <img v-if="c.images && (c.images.idle || c.images.listening)" class="cdot" :src="c.images.idle || c.images.listening" :alt="c.name" />
          <span v-else class="dot" :style="{ background: (c.palette && c.palette.hair) || c.tint || '#94a3b8' }"></span>{{ c.name }}
        </button>
      </div>

      <!-- 语言 + 音色：每个角色一套固定的 -->
      <div class="vsect">
        <div class="vhead">
          <span class="vtitle">语言 · 音色</span>
          <button class="btn sm" @click="openPicker">{{ pickOpen ? '收起' : '修改' }}</button>
        </div>
        <div class="vcur">
          <b>{{ langLabel(cfgLang) }}</b> · {{ curProvLabel }} · {{ curVoiceName }}
          <span v-if="voiceOverridden" class="vtag">角色专属</span>
        </div>

        <div v-if="pickOpen" class="vbox">
          <label class="vlab">引擎（每个角色可以不同）</label>
          <select class="input" v-model="pickProvider" @change="onPickProvider">
            <option v-for="p in tts.providers.value" :key="p.id" :value="p.id">
              {{ p.label }}{{ p.configured ? '' : '（未配置 Key）' }}
            </option>
          </select>

          <label class="vlab">音色</label>
          <select class="input" v-model="pickVoice">
            <optgroup v-for="g in voiceGroups" :key="g.locale" :label="g.label">
              <option v-for="v in g.voices" :key="v.id" :value="v.id">{{ v.name }}</option>
            </optgroup>
          </select>
          <button v-if="hiddenVoiceCount" class="btn sm linkbtn" @click="tts.setScope(voiceScopeAll ? 'core' : 'all')">
            {{ voiceScopeAll ? '只看同口音' : '显示全部音色（还有 ' + hiddenVoiceCount + ' 组）' }}
          </button>

          <div class="vacts">
            <button class="btn sm" @click="tryVoice">试听</button>
            <button class="btn sm pri" @click="applyVoice">用于 {{ curChar.name }}</button>
            <button class="btn sm" @click="clearVoice">跟随全局</button>
          </div>
          <p class="small muted">{{ voiceHint }}</p>
        </div>
      </div>

      <!-- 历史会话 -->
      <div class="hsect">
        <div class="hhead">
          <span class="htitle">历史会话</span>
          <button class="btn sm" @click="newSession" title="开一个新对话">+ 新对话</button>
        </div>
        <div class="hlist">
          <template v-for="g in sessionGroups" :key="g.key">
            <div class="hg">{{ g.title }}</div>
            <div v-for="s in g.items" :key="s.id" class="hitem" :class="{ on: s.id === curSid }"
                 @click="openSession(s.id)" :title="'打开：' + s.title">
              <div class="ht">{{ s.title }}</div>
              <div class="hm">{{ s.msg_count }} 条 · {{ langLabel(s.lang) }}</div>
              <span class="hacts">
                <button class="hx" @click.stop="renameSession(s.id)" title="改名">✎</button>
                <button class="hx" @click.stop="delSession(s.id)" title="删除">✕</button>
              </span>
            </div>
          </template>
          <div v-if="!sessions.length" class="small muted hnone">还没有记录，说一句就有。</div>
        </div>
      </div>
    </aside>

    <!-- ============ 右侧：对话 ============ -->
    <section class="vtmain">
      <div class="card tbar">
        <button class="btn sm mode" :class="{ pri: !isThinkingMode }" @click="setMode('lang')">🗣 外语练习</button>
        <button class="btn sm mode" :class="{ pri: isThinkingMode }" @click="setMode('think')">💭 中文聊想法</button>
        <span class="sep"></span>
        <template v-if="!isThinkingMode">
          <span class="lbl">语种</span>
          <button v-for="l in LANGS" :key="l.code" v-show="l.code !== 'zh-CN'" class="btn sm"
                  :class="{ pri: lang === l.code }" @click="lang = l.code">{{ l.label }}</button>
          <span class="sep"></span>
        </template>
        <span class="lbl">我说</span>
        <button v-for="a in ASR_LANGS" :key="a.code" class="btn sm" :class="{ pri: asrLang === a.code }"
                @click="asrLang = a.code">{{ a.label }}</button>
        <template v-if="!isThinkingMode">
          <span class="sep"></span>
          <span class="lbl">讲解</span>
          <button v-for="e in EXPLAINS" :key="e.code" class="btn sm" :class="{ pri: explain === e.code }"
                  @click="explain = e.code">{{ e.label }}</button>
        </template>
        <span class="sep"></span>
        <button class="btn sm" :class="{ pri: autoSpeak }" @click="autoSpeak = !autoSpeak"
                title="关掉后老师说的话只出文字，不出声；仍可逐句手动点播">
          {{ autoSpeak ? '🔊 自动朗读' : '🔇 不朗读' }}
        </button>
      </div>

      <div class="card vtlog" ref="scrollEl">
        <div v-if="!msgs.length" class="empty small">{{ greeting }}</div>

        <div v-for="(m, i) in msgs" :key="i" class="bwrap" :class="m.role">
          <div class="bub">
            <div class="who">{{ m.role === 'me' ? '我' : curChar.name }}</div>
            <div class="tx">{{ m.text }}</div>
            <div v-if="m.role === 'ai' && m.zh" class="zh">{{ m.zh }}</div>
            <div v-if="m.tools && m.tools.length" class="tools small">调用了：{{ m.tools.join('、') }}</div>
            <div class="acts">
              <button class="abtn" :class="{ on: speakingIdx === i }" @click="replay(i)"
                      :title="speakingIdx === i ? '停止朗读' : '念这一句'">
                {{ speakingIdx === i ? '⏹ 停止' : '🔊 念这句' }}
              </button>
            </div>
          </div>

          <div v-if="m.corrections && m.corrections.length" class="corr">
            <div v-for="(c, k) in m.corrections" :key="k" class="citem">
              <div class="line"><span class="bad">✗</span><s>{{ c.wrong }}</s></div>
              <div class="line"><span class="good">✓</span><b>{{ c.fixed }}</b></div>
              <div class="note">{{ c.note }}</div>
            </div>
          </div>

          <!-- 发音诊断：录音的旁路产物（老师只看得到文字，看不到你说得清不清楚） -->
          <div v-if="m.role === 'me' && (m.pronPending || m.pron || m.pronErr)" class="pron">
            <div class="pron-h">
              <span>🔍 发音诊断</span>
              <span class="small muted" v-if="m.pron">
                {{ m.pron.duration }}s · 含停顿 {{ m.pron.metrics.wpm }} wpm · 词 {{ m.pron.metrics.words }}
              </span>
              <span class="small muted" v-else-if="m.pronPending">分析中…（不影响老师回话）</span>
            </div>

            <div v-if="m.pronErr" class="small" style="color:var(--amber)">
              诊断失败：{{ m.pronErr }}
            </div>

            <template v-if="m.pron">
              <div v-if="!pronFlags(m).length" class="small muted" style="margin-top:6px">
                每个词的识别置信度都不错 —— 这一句发音挺清楚。
              </div>
              <div v-else class="plist">
                <div v-for="(f, k) in pronFlags(m)" :key="k" class="prow">
                  <b class="pw">{{ f.w }}</b>
                  <span class="ptag" :class="f.reason">{{ REASON_ZH[f.reason] || f.reason }}</span>
                  <span class="small muted">置信 {{ Math.round(f.p * 100) }}% · {{ f.dur }}s</span>
                </div>
              </div>
              <div class="small muted" style="margin-top:7px;line-height:1.7">
                这些是你念得最吃力的词。点「念这句」听老师的标准读法，再对着卡片练一遍。
                <br />（本地模型只判「念错词 / 含糊 / 吞音」，判不了音标细节。）
              </div>

              <!-- 老师针对这几个词的发音点评 -->
              <div v-if="m.tip || m.tipPending" class="tipbox">
                <div class="tiph">老师点评{{ m.tipPending && !m.tip ? '（生成中…）' : '' }}</div>
                <div v-if="m.tip" class="tipt">{{ m.tip }}</div>
              </div>
            </template>
          </div>
          <!-- 中文思维伙伴模式给的是「可以再往下想」，不是纠错 -->
          <div v-if="m.points && m.points.length" class="pts">
            <div class="pth">可以再往下想</div>
            <div v-for="(p, k) in m.points" :key="k" class="pitem">
              <div class="pt">{{ p.t }}</div>
              <div class="pd">{{ p.d }}</div>
            </div>
          </div>
          <div v-else-if="m.role === 'ai' && m.text && !isThinkingMode" class="corr ok small">
            这一轮没有明显错误 ✓
          </div>
        </div>

        <div v-if="thinking" class="empty small">老师在想…</div>
      </div>

      <!-- 输入区：点击式大麦克风 -->
      <div class="card vtin">
        <div class="miczone">
          <button class="bigmic" :class="{ on: listening, dim: !micOk }" :disabled="!micOk"
                  @click="onMicClick" :title="micOk ? '点一下开始录音，再点一下结束并发送（并做发音诊断）' : '浏览器不支持录音'">
            <span class="pulse" v-if="listening"></span>
            <svg viewBox="0 0 24 24" class="ico" aria-hidden="true">
              <path d="M12 15a3.5 3.5 0 0 0 3.5-3.5v-6a3.5 3.5 0 1 0-7 0v6A3.5 3.5 0 0 0 12 15Z" />
              <path d="M5.5 11.5a6.5 6.5 0 0 0 13 0M12 18.5V21" fill="none" stroke-width="1.8"
                    stroke-linecap="round" />
            </svg>
          </button>
          <div class="michint">{{ listening ? '再点一下 = 发送' : '点一下开始录音' }}</div>
        </div>

        <div class="row inrow">
          <textarea ref="taEl" class="input ta" v-model="input" rows="1" @keydown.enter="onEnter"
                    :placeholder="lang === 'ja-JP'
                      ? '手入力もOK（Enter 送信 / Shift+Enter 改行）'
                      : 'Or type here — Enter to send, Shift+Enter for a new line'"></textarea>
          <div class="btns">
            <button class="btn pri" :disabled="thinking || !input.trim()" @click="send">发送</button>
            <button class="btn sm" v-if="speaking" @click="stopSpeak">停止朗读</button>
            <button class="btn sm" @click="clearChat">清空</button>
          </div>
        </div>
        <p class="small muted" v-if="!asrOk && recOk">此浏览器没有语音识别 —— 录音照常，说完点第二次可做发音诊断；文字请自己打。</p>
        <p class="small muted" v-else-if="!micOk">此浏览器既不支持语音识别也不支持录音，可用上面的输入框打字。</p>
      </div>
    </section>
  </div>
</template>

<style scoped>
.vt { display: grid; grid-template-columns: 350px minmax(0, 1fr); gap: 12px; align-items: start; }

/* ---------- 虚拟形象岛 ---------- */
/* 注意：全局 theme.css 里 `.bar` 是「进度条」（height:7px; overflow:hidden），
   所以本组件的工具条叫 .tbar、角色胶囊叫 .pchip，别用会撞车的通用名。 */
.island { position: sticky; top: 8px; text-align: center; padding: 14px 12px;
          /* ⚠️ 上限必须和 .vtmain 用同一个常数：栅格行高 = max(左岛, 右列)，
     左岛宽松（写 100vh-28px 时是 772px）会把行撑高、整页溢出 ——
     实测 800 视口下溢出 84px。改用同样的 139px（2026-10-04 修）。 */
  max-height: calc(100vh - 139px); overflow-y: auto; }


.nm { font-weight: 700; margin-top: 8px; }
.role { font-size: 12px; color: #2563eb; margin-top: 1px; }
.st { font-size: 12px; color: #6b7280; margin-top: 4px; }
.st.listening { color: #dc2626; }
.st.speaking { color: #2563eb; }
.st.thinking { color: #d97706; }

/* 眨眼 / 思考眯眼 */
.eyes { animation: blink 5.5s infinite; transform-origin: 60px 62px; }
@keyframes blink { 0%,96%,100% { transform: scaleY(1); } 98% { transform: scaleY(.12); } }


/* 聆听：扩散圈 */

/* 角色切换 */
.chars { display: flex; flex-wrap: wrap; gap: 5px; justify-content: center; margin-top: 10px; }
.pchip { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; padding: 3px 8px;
        border-radius: 999px; border: 1px solid rgba(0,0,0,.12); background: #fff; cursor: pointer; }
.pchip.on { border-color: #2563eb; background: rgba(59,130,246,.10); color: #1d4ed8; font-weight: 600; }
.dot { width: 10px; height: 10px; border-radius: 50%; }
.cdot { width: 16px; height: 16px; border-radius: 50%; object-fit: cover; border: 1px solid rgba(0,0,0,.10); }

/* ---------- 音色 ---------- */
.vsect { margin-top: 10px; border-top: 1px solid rgba(0,0,0,.08); padding-top: 10px; text-align: left; }
.vhead { display: flex; align-items: center; justify-content: space-between; gap: 6px; }
.vtitle { font-size: 12px; font-weight: 700; color: #374151; }
.vcur { font-size: 12.5px; color: #4b5563; margin-top: 4px; line-height: 1.5; word-break: break-word; }
.vtag { display: inline-block; margin-left: 5px; padding: 0 6px; border-radius: 999px;
        font-size: 10.5px; background: rgba(37,99,235,.12); color: #1d4ed8; vertical-align: 1px; }
.vbox { margin-top: 8px; display: flex; flex-direction: column; gap: 5px; }
.vlab { font-size: 11px; color: #9ca3af; }
.vbox .input { width: 100%; padding: 5px 7px; font-size: 12.5px; }
.linkbtn { border: none; background: none; color: #2563eb; padding: 0; font-size: 11.5px; cursor: pointer;
           text-align: left; }
.vacts { display: flex; gap: 5px; flex-wrap: wrap; margin-top: 2px; }

/* ---------- 历史会话 ---------- */
.hsect { margin-top: 12px; border-top: 1px solid rgba(0,0,0,.08); padding-top: 10px; text-align: left; }
.hhead { display: flex; align-items: center; justify-content: space-between; gap: 6px; margin-bottom: 6px; }
.htitle { font-size: 12px; font-weight: 700; color: #374151; }
.hlist { display: flex; flex-direction: column; gap: 4px; max-height: 220px; overflow-y: auto; }
.hg { font-size: 11px; color: #9ca3af; margin: 8px 0 2px; padding-left: 2px; }
.hg:first-child { margin-top: 0; }
.hitem { position: relative; padding: 6px 44px 6px 8px; border-radius: 8px; cursor: pointer;
         border: 1px solid transparent; }
.hitem:hover { background: rgba(0,0,0,.04); }
.hitem.on { background: rgba(59,130,246,.12); border-color: rgba(59,130,246,.35); }
.ht { font-size: 12.5px; line-height: 1.4; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.hm { font-size: 11px; color: #9ca3af; margin-top: 1px; }
.hacts { position: absolute; right: 5px; top: 50%; transform: translateY(-50%); display: flex; gap: 2px; }
.hx { border: none; background: transparent; cursor: pointer; font-size: 12px; color: #9ca3af;
      padding: 2px 3px; border-radius: 4px; }
.hx:hover { background: rgba(0,0,0,.08); color: #374151; }
.hnone { padding: 6px 8px; }

/* ---------- 顶栏 ---------- */
.tbar { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; padding: 8px 10px; }
.mode { font-weight: 600; }
.mode.pri { font-weight: 700; }
.lbl { font-size: 12px; color: #6b7280; }
.sep { width: 1px; height: 16px; background: rgba(0,0,0,.10); margin: 0 4px; }

/* ---------- 对话 ---------- */
/* 右侧主列占满可用高度：视口 − .main 上内边距(22) − 页头(57) − .main 下内边距(60) = 100vh - 139px。
   只改这一个常数；列内的分配交给 flex，别再逐个去算间隙。 */
.vtmain { display: flex; flex-direction: column; gap: 12px; min-width: 0;
          height: calc(100vh - 139px); }
/* 聊天区默认撑满：改用 flex 分配，不再手写 calc 猜测周边间距。
   ⚠️ 两条都踩过（2026-10-04）：
   1) 原来写的是 `max-height` —— 它只「封顶」不「撑开」，对话为空时盒子塌成一行问候语高（实测 124px）；
   2) 改成 `calc(100vh - 406px)` 也不行 —— 工具条与麦克风区之间的实际间距在同一页里会变（实测 26px vs 62px），
      手写常数总差几十像素，整页多出滚动条。
   现在：`.vtmain` 占满「视口 − 页头 − 上下内边距」，`.vtlog` 用 flex:1 吃掉剩余空间 → 浏览器自己算。 */
.vtlog { flex: 1 1 auto; min-height: 180px; overflow-y: auto;
         display: flex; flex-direction: column; gap: 12px; }
/* ⚠️ 原为 max-height:44vh —— 实测固定占用只有约 378px（log 顶部 154 + 底部麦克风区 194 + 间隙），
   1080 视口下白白少用 227px，导致老师「回复 + 长讲解」一屏放不下（2026-10-03 修）。 */
.bwrap { display: flex; flex-direction: column; gap: 6px; }
.bwrap.me { align-items: flex-end; }
.bwrap.ai { align-items: flex-start; }
.bub { max-width: 82%; padding: 8px 12px; border-radius: 12px; background: rgba(0,0,0,.04); }
.bwrap.me .bub { background: rgba(59,130,246,.12); }
.who { font-size: 11px; color: #6b7280; margin-bottom: 2px; }
.tx { font-size: 15px; line-height: 1.65; white-space: pre-wrap; word-break: break-word; }
.zh { margin-top: 6px; padding: 6px 9px; border-left: 3px solid #fbbf24; border-radius: 6px;
      background: rgba(251,191,36,.10); font-size: 13.5px; line-height: 1.6; color: #4b5563;
      white-space: pre-wrap; word-break: break-word; }
.tools { margin-top: 6px; color: #2563eb; }
.acts { margin-top: 6px; display: flex; gap: 6px; }
.abtn { border: 1px solid rgba(0,0,0,.10); background: #fff; border-radius: 999px;
        padding: 2px 9px; font-size: 11.5px; cursor: pointer; color: #4b5563; }
.abtn:hover { border-color: #2563eb; color: #1d4ed8; }
.abtn.on { background: rgba(220,38,38,.10); border-color: #dc2626; color: #b91c1c; }

.corr { width: 82%; display: flex; flex-direction: column; gap: 6px; }
.citem { border: 1px solid rgba(0,0,0,.08); border-radius: 10px; padding: 8px 10px; background: #fff; }
.line { font-size: 14px; line-height: 1.5; word-break: break-word; }
.bad { color: #dc2626; margin-right: 6px; }
.good { color: #16a34a; margin-right: 6px; }
.note { font-size: 12px; color: #6b7280; margin-top: 4px; }
.corr.ok { padding: 6px 10px; color: #16a34a; }

/* ---------- 发音诊断卡（录音旁路产物） ---------- */
.pron { width: 82%; border: 1px solid rgba(37,99,235,.22); border-radius: 10px;
        padding: 9px 11px; background: rgba(37,99,235,.045); }
.pron-h { display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap;
          font-size: 12.5px; font-weight: 700; color: #1d4ed8; }
.plist { margin-top: 7px; display: flex; flex-direction: column; gap: 4px; }
.prow { display: flex; align-items: center; gap: 7px; font-size: 13px; }
.pw { font-family: var(--font-serif, Georgia, serif); font-size: 14.5px; }
.ptag { font-size: 11px; padding: 1px 6px; border-radius: 999px; white-space: nowrap; }
.ptag.low_conf { background: rgba(220,38,38,.12); color: #b91c1c; }
.ptag.long_dur { background: rgba(217,119,6,.14); color: #b45309; }
.tipbox { margin-top: 9px; padding: 8px 10px; border-radius: 8px;
          background: rgba(22,163,74,.07); border: 1px solid rgba(22,163,74,.22); }
.tiph { font-size: 11.5px; font-weight: 700; color: #15803d; margin-bottom: 4px; }
.tipt { font-size: 12.5px; line-height: 1.85; white-space: pre-wrap; color: #1f2937; }

/* 中文思维伙伴：可以再往下想 */
.pts { width: 82%; border: 1px dashed rgba(180,83,9,.35); border-radius: 10px;
       padding: 8px 10px; background: rgba(251,191,36,.07); }
.pth { font-size: 11.5px; font-weight: 700; color: #b45309; margin-bottom: 5px; }
.pitem + .pitem { margin-top: 7px; padding-top: 7px; border-top: 1px dashed rgba(180,83,9,.20); }
.pt { font-size: 13.5px; font-weight: 600; color: #7c2d12; line-height: 1.5; }
.pd { font-size: 12.5px; color: #6b7280; margin-top: 2px; line-height: 1.55; }

/* ---------- 大麦克风 ---------- */
.vtin { display: flex; flex-direction: column; align-items: center; gap: 10px; }
.miczone { display: flex; flex-direction: column; align-items: center; gap: 6px; }
.bigmic { position: relative; width: 84px; height: 84px; border-radius: 50%; border: none; cursor: pointer;
          background: linear-gradient(145deg, #111827, #374151); color: #fff;
          display: flex; align-items: center; justify-content: center;
          box-shadow: 0 8px 20px rgba(0,0,0,.22); transition: transform .12s, box-shadow .12s; }
.bigmic:hover { transform: translateY(-2px); }
.bigmic:active { transform: scale(.94); }
.bigmic.on { background: linear-gradient(145deg, #dc2626, #ef4444); }
.bigmic.dim { opacity: .45; cursor: not-allowed; }
.ico { width: 34px; height: 34px; fill: currentColor; stroke: currentColor; }
.pulse { position: absolute; inset: -10px; border-radius: 50%; border: 3px solid rgba(220,38,38,.5);
         animation: ripple2 1.4s ease-out infinite; }
@keyframes ripple2 { 0% { transform: scale(1); opacity: .85; } 100% { transform: scale(1.45); opacity: 0; } }
.michint { font-size: 12px; color: #6b7280; }

.inrow { width: 100%; gap: 8px; align-items: flex-end; }
.ta { flex: 1; min-width: 180px; resize: none; overflow-y: auto; line-height: 1.6;
      min-height: 40px; max-height: 168px; padding: 9px 10px; }
.btns { display: flex; gap: 6px; flex-wrap: wrap; }

/* ⚠️ 单列（手机/窄屏）覆盖必须写在**所有基础规则之后** ——
   原来它写在文件中部，而 .vtlog / .vtmain / .island 的基础规则在后面，
   同权重下**基础规则反而赢**，覆盖完全没生效（2026-10-04 修，与 chip 那次同类）。 */
@media (max-width: 1000px) {
  .vt { grid-template-columns: 1fr; }
  /* 单列时左岛在上、右列在下，固定高度会让总高远超视口 → 全部还原成自适应 */
  .vtmain { height: auto; }
  .island { max-height: none; }
  /* 手机上左岛与对话是上下堆叠的，整页本来就要滚动 ——
     此时给聊天区强撑一个高盒子只会让页面更长（实测反而多 259px）。
     改成「随内容增长、上限 56vh」：空对话不撑高，对话变长后到 56vh 再内部滚动。 */
  .vtlog { flex: none; height: auto; max-height: 56vh; min-height: 40vh; }
}

</style>
