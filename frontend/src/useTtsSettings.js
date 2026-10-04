/**
 * 语音引擎设置的唯一来源（模块级单例）
 *
 * 阅读器和卡片页共用这一份 —— 在一个页面选了引擎/音色，另一个页面立刻跟着变，
 * 不会再出现「阅读器用 Edge、卡片页还在用系统语音」这种两套设置的问题。
 *
 * 持久化：langlab.tts（引擎 + 每种「引擎:语言」各记一个音色）、langlab.voiceScope（本口音 / 全部口音）
 */
import { ref, reactive, computed } from 'vue'
import { api } from './api.js'

const CONF_KEY = 'langlab.tts'
const SCOPE_KEY = 'langlab.voiceScope'

function loadConf() {
  try {
    const j = JSON.parse(localStorage.getItem(CONF_KEY) || 'null') || {}
    return { provider: j.provider || 'edge', voices: j.voices || {} }
  } catch (e) {
    return { provider: 'edge', voices: {} }
  }
}

/* ---------------- 单例状态 ---------------- */
const conf = reactive(loadConf())
const providers = ref([])
const providersErr = ref('')
const voiceScope = ref((() => {
  try { return localStorage.getItem(SCOPE_KEY) || 'core' } catch (e) { return 'core' }
})())

// 服务端引擎失败后临时回退浏览器内置；只作用于本次会话，不改用户的选择
const fellBack = ref(false)
const fellBackReason = ref('')

// 'edge:en' -> { voices, groups, core, loading, error }
const book = reactive({})
let providersLoaded = false

function saveConf() {
  try { localStorage.setItem(CONF_KEY, JSON.stringify({ provider: conf.provider, voices: conf.voices })) } catch (e) {}
}
function saveScope() {
  try { localStorage.setItem(SCOPE_KEY, voiceScope.value) } catch (e) {}
}

const curProvider = computed(() => (fellBack.value ? 'browser' : conf.provider))
const langShort = (code) => String(code || 'en-US').slice(0, 2).toLowerCase()
const bookKey = (provider, code) => provider + ':' + langShort(code)

/* 默认音色的显式优先级表 —— 不依赖接口返回顺序（顺序会随服务端音色表变动） */
const PREFERRED = {
  // 英语：一律优先美式标准音（General American）。用户要求「美式纽约 / 美式标准发音」
  en: [
    'en-US-AvaMultilingualNeural', 'en-US-EmmaMultilingualNeural',
    'en-US-AndrewMultilingualNeural', 'en-US-BrianMultilingualNeural',
    'en-US-AriaNeural', 'en-US-JennyNeural', 'en-US-GuyNeural', 'en-US-AndrewNeural',
  ],
  ja: ['ja-JP-NanamiNeural', 'ja-JP-KeitaNeural', 'ja-JP-AoiNeural', 'ja-JP-DaichiNeural'],
}

/**
 * 挑默认音色：同 locale 优先 → 同语言 → 优先级表 → core → 第一个
 * @param {string} code 素材语言（en-US / ja-JP）
 * @param {Array}  list 接口返回的音色
 */
function pickDefaultVoice(code, list) {
  const arr = list || []
  if (!arr.length) return ''
  const loc = (v) => String(v.locale || v.lang || '').toLowerCase()
  const want = String(code || '').toLowerCase()
  const short = langShort(code)
  const exact = arr.filter((v) => loc(v) === want)
  const same = arr.filter((v) => loc(v).startsWith(short))
  const src = exact.length ? exact : (same.length ? same : arr)
  for (const id of (PREFERRED[short] || [])) {
    const hit = src.find((v) => v.id === id)
    if (hit) return hit.id
  }
  return (src.find((v) => v.core) || src[0]).id
}

/* ---------------- 引擎清单 ---------------- */
async function loadProviders() {
  if (providersLoaded) return
  providersLoaded = true
  try {
    const r = await api.ttsProviders()
    providers.value = Object.values(r.providers || {})
  } catch (e) {
    providersErr.value = String(e.message || e)
  }
}
const curProviderInfo = computed(() => providers.value.find((p) => p.id === conf.provider) || null)

/* ---------------- 音色清单（按引擎+语言缓存） ----------------
 * 缓存 key 是「引擎:语言」，所以**同一时刻可以缓存多个引擎的音色表** ——
 * 这是「不同角色用不同引擎」的前提（虚拟老师要玫走 Edge、Mali 走 MiniMax）。
 * 下面 For 系列都显式带 provider；不带的就是「用当前全局引擎」的便捷包装。
 */
async function ensureVoicesFor(p, code) {
  if (!p || p === 'browser') return
  const k = bookKey(p, code)
  if (book[k] && (book[k].loaded || book[k].loading)) return
  book[k] = { loading: true, voices: [], groups: [] }
  try {
    const r = await api.ttsVoices(p, langShort(code), code)
    const list = r.voices || []
    book[k] = { loading: false, loaded: true, voices: list, groups: r.groups || [] }
    const saved = conf.voices[k]
    if (!saved && list.length) {
      conf.voices[k] = pickDefaultVoice(code, list)
      saveConf()
    } else if (saved && list.length && !list.some((v) => v.id === saved)) {
      // ⚠️ 存着的音色已经不在可用列表里（换过引擎 / 服务端音色表变了）。
      // 不纠正的话会「下拉显示第一个（美式）、实际还在用旧的（可能是英式）」—— 听着口音不对却查不出原因。
      const alt = pickDefaultVoice(code, list)
      if (alt) { conf.voices[k] = alt; saveConf() }
    }
  } catch (e) {
    book[k] = { loading: false, loaded: false, error: String(e.message || e), voices: [], groups: [] }
  }
}
async function ensureVoices(code) {
  return ensureVoicesFor(curProvider.value, code)
}
const entryForProvider = (p, code) =>
  book[bookKey(p, code)] || { voices: [], groups: [], loading: false }
const entryFor = (code) => entryForProvider(curProvider.value, code)

/* ---------------- 对外取值 ---------------- */
/** 当前引擎 + 语言下选中的音色（浏览器引擎由 useSpeech 自己管系统音色） */
function voiceForProvider(p, code) {
  return conf.voices[bookKey(p, code)] || ''
}
function voiceFor(code) {
  return voiceForProvider(curProvider.value, code)
}

/** 下拉里显示的分组：默认只留「和素材同口音」那一组 */
function shownGroupsFor(p, code) {
  const all = entryForProvider(p, code).groups || []
  if (!all.length) return []
  if (voiceScope.value === 'all') return all
  // 关键：当前选中的音色所在分组**永远要显示**，否则 <select> 找不到匹配项会退回首项显示，
  // 出现「面板显示美式、实际念英式」的静默不一致（2026-10-03 修）
  const cur = voiceForProvider(p, code)
  const core = all.filter((g) => g.core || (g.voices || []).some((v) => v.id === cur))
  return core.length ? core : all.slice(0, 1)
}
function shownGroups(code) {
  return shownGroupsFor(curProvider.value, code)
}
function hiddenGroupCountFor(p, code) {
  const all = entryForProvider(p, code).groups || []
  return Math.max(0, all.length - shownGroupsFor(p, code).length)
}
function hiddenGroupCount(code) {
  return hiddenGroupCountFor(curProvider.value, code)
}
function voiceInfo(code) {
  const v = voiceFor(code)
  return (entryFor(code).voices || []).find((x) => x.id === v) || null
}

/* ---------------- 改设置 ---------------- */
function setProvider(p) {
  fellBack.value = false
  fellBackReason.value = ''
  conf.provider = p
  saveConf()
}
function setVoice(code, id) {
  conf.voices[bookKey(curProvider.value, code)] = id
  saveConf()
}
function setScope(s) {
  voiceScope.value = s
  saveScope()
}
function noteFallback(err, hintTxt) {
  if (fellBack.value || curProvider.value === 'browser') return false
  fellBack.value = true
  fellBackReason.value = String(err || '') + (hintTxt ? '（' + hintTxt + '）' : '')
  return true
}

export function useTtsSettings() {
  return {
    conf, providers, providersErr, loadProviders, curProvider, curProviderInfo,
    voiceScope, setScope,
    fellBack, fellBackReason, noteFallback,
    ensureVoices, entryFor,
    voiceFor, setVoice, voiceInfo, shownGroups, hiddenGroupCount,
    // 指定引擎的版本：虚拟老师的「每个角色一套引擎+音色」靠它们，互不干扰
    ensureVoicesFor, entryForProvider, voiceForProvider, shownGroupsFor, hiddenGroupCountFor,
    // 默认音色挑选（TtsPanel 的「切到美式标准发音」用它）
    pickDefaultVoice,
    langShort,
  }
}
