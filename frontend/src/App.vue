<script setup>
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount, provide } from 'vue'
import { api } from './api.js'
import Home from './pages/Home.vue'
import Dashboard from './pages/Dashboard.vue'
import Cards from './pages/Cards.vue'
import Materials from './pages/Materials.vue'
import Reader from './pages/Reader.vue'
import Dict from './pages/Dict.vue'
import Notes from './pages/Notes.vue'
import Courses from './pages/Courses.vue'
import Shows from './pages/Shows.vue'
import DramaPlayer from './pages/DramaPlayer.vue'
import VoiceTeacher from './pages/VoiceTeacher.vue'

/* ---------------- 全局状态 ---------------- */
const state = reactive({
  languages: [],
  courses: [],
  lang: localStorage.getItem('langlab.lang') || 'all',
  course: localStorage.getItem('langlab.course') || '',
  ready: false,
  error: '',
})

const toast = ref('')
let toastTimer = null
function say(msg) {
  toast.value = msg
  clearTimeout(toastTimer)
  toastTimer = setTimeout(() => (toast.value = ''), 2600)
}

provide('state', state)
provide('say', say)
provide('api', api)

function setLang(code) {
  state.lang = code
  localStorage.setItem('langlab.lang', code)
  // 切语言时，课程跟着切到该语言的第一门
  const first = state.courses.find((c) => c.lang_code === code)
  state.course = first ? first.id : ''
  localStorage.setItem('langlab.course', state.course)
}
function setCourse(id) {
  state.course = id
  const c = state.courses.find((x) => x.id === id)
  if (c) {
    state.lang = c.lang_code
    localStorage.setItem('langlab.lang', state.lang)
  }
  localStorage.setItem('langlab.course', id)
}

/* ---------------- hash 路由 ---------------- */
const route = ref(parseHash())
function parseHash() {
  const h = (location.hash || '#/home').replace(/^#\/?/, '')
  const [path, ...rest] = h.split('/')
  // 必须解码：剧名/系列名可能是中文或日文，go() 那边用 encodeURIComponent 编码过
  const dec = (s) => { try { return decodeURIComponent(s) } catch (e) { return s } }
  return { name: dec(path) || 'home', args: rest.map(dec) }
}
function onHash() { route.value = parseHash() }
function go(name, ...args) {
  drawer.value = false          // 手机上点了菜单项就把抽屉收起来
  location.hash = '#/' + [name, ...args].map(encodeURIComponent).join('/')
}
/**
 * 导航高亮。以前 reader 没有自己的入口，只能让「素材库」代亮；
 * 现在有「阅读」了 → 各归各。顺带让「影视剧集」在台词跟读页也亮着。
 */
function navOn(name) {
  const r = route.value.name
  if (name === 'shows') return r === 'shows' || r === 'drama'
  return r === name
}

/* ---------------- 低分辨率下的左侧抽屉 ---------------- */
const drawer = ref(false)
function closeDrawer() { drawer.value = false }

// 抽屉开着时锁住页面滚动，免得滑动穿透到下面的内容
watch(drawer, (open) => {
  document.body.style.overflow = open ? 'hidden' : ''
})
// 路由一变就收起（含浏览器前进/后退）
watch(route, () => { drawer.value = false })
function onKeydown(e) {
  if (e.key === 'Escape') closeDrawer()
}

/* ---------------- 导航 ---------------- */
const NAV = [
  { group: '学习', items: [
    { name: 'home', label: '学习入口', ic: '◎' },
    { name: 'dashboard', label: '数据大盘', ic: '▤' },
    { name: 'courses', label: '课程', ic: '❑' },
    { name: 'teacher', label: '虚拟老师', ic: '☏' },
  ] },
  { group: '内容', items: [
    { name: 'cards', label: '卡片学习器', ic: '▥' },
    { name: 'reader', label: '阅读', ic: '❖' },
    { name: 'shows', label: '影视剧集', ic: '▶' },
    { name: 'materials', label: '素材库', ic: '❐' },
    { name: 'dict', label: '字典', ic: '⌕' },
    { name: 'notes', label: '学习笔记', ic: '✎' },
  ] },
]
const MOBILE = [
  { name: 'home', label: '入口', ic: '◎' },
  { name: 'dashboard', label: '大盘', ic: '▤' },
  { name: 'cards', label: '卡片', ic: '▥' },
  { name: 'reader', label: '阅读', ic: '❖' },
  { name: 'shows', label: '剧集', ic: '▶' },
  { name: 'materials', label: '素材', ic: '❐' },
  { name: 'dict', label: '字典', ic: '⌕' },
]

const pages = { home: Home, dashboard: Dashboard, cards: Cards, shows: Shows, materials: Materials, dict: Dict, notes: Notes, courses: Courses, reader: Reader, drama: DramaPlayer, teacher: VoiceTeacher }
const current = computed(() => pages[route.value.name] || Home)

const enabledLangs = computed(() => state.languages.filter((l) => l.enabled))
const langOptions = computed(() => [{ code: 'all', name_zh: '全部语言' }, ...enabledLangs.value])

/* ---------------- 启动 ---------------- */
onMounted(async () => {
  window.addEventListener('hashchange', onHash)
  window.addEventListener('keydown', onKeydown)
  try {
    const [l, c] = await Promise.all([api.languages(), api.courses()])
    state.languages = l.languages
    state.courses = c.courses
    if (!state.course && c.courses.length) setCourse(c.courses[0].id)
    state.ready = true
  } catch (e) {
    state.error = String(e.message || e)
  }
  if (!location.hash) location.hash = '#/home'
})
onBeforeUnmount(() => {
  window.removeEventListener('hashchange', onHash)
  window.removeEventListener('keydown', onKeydown)
  document.body.style.overflow = ''
})
</script>

<template>
  <div class="app">
    <!-- 侧边栏：桌面常驻；低分辨率下变成左侧抽屉，由 .open 控制滑出 -->
    <aside class="side" :class="{ open: drawer }">
      <div class="brand">
        <div class="dot">L</div>
        <div>
          <b>language lab</b>
          <small>语言学习器</small>
        </div>
        <button class="sideclose" title="收起菜单" @click="closeDrawer">×</button>
      </div>

      <div v-if="state.ready" class="sidepickers">
        <label class="small muted">语种</label>
        <select class="select" :value="state.lang" @change="setLang($event.target.value)">
          <option v-for="l in langOptions" :key="l.code" :value="l.code">{{ l.name_zh }}</option>
        </select>
        <label class="small muted" style="margin-top:6px">课程</label>
        <select class="select" :value="state.course" @change="setCourse($event.target.value)">
          <option v-for="c in state.courses" :key="c.id" :value="c.id">
            {{ c.name }}{{ state.lang === 'all' ? '（' + c.lang_code + '）' : '' }}
          </option>
        </select>
      </div>

      <div v-for="g in NAV" :key="g.group" class="navgroup">
        <h4>{{ g.group }}</h4>
        <button v-for="it in g.items" :key="it.name" class="nav"
                :class="{ on: navOn(it.name) }"
                @click="go(it.name)">
          <span class="ic">{{ it.ic }}</span><span>{{ it.label }}</span>
        </button>
      </div>
    </aside>

    <!-- 抽屉打开时的遮罩 -->
    <div v-if="drawer" class="scrim" @click="closeDrawer"></div>

    <!-- 主区 -->
    <main class="main">
      <div class="pagehead">
        <button class="menubtn" :aria-expanded="drawer ? 'true' : 'false'" aria-label="打开菜单"
                title="菜单" @click="drawer = true">☰</button>
        <div>
          <h1>{{ { home:'学习入口', dashboard:'数据大盘', courses:'课程', cards:'卡片学习器', shows:'影视剧集', materials:'素材库', reader:'阅读', drama:'台词跟读', teacher:'虚拟老师', dict:'字典', notes:'学习笔记' }[route.name] || '语言学习器' }}</h1>
          <p v-if="!state.ready && !state.error">正在连接后端…</p>
          <p v-else-if="state.error">后端连接失败：{{ state.error }}</p>
        </div>
        <div class="sp"></div>
        <div class="row headpick" v-if="state.ready">
          <select class="select" style="width:auto" :value="state.lang" @change="setLang($event.target.value)">
            <option v-for="l in langOptions" :key="l.code" :value="l.code">{{ l.name_zh }}</option>
          </select>
          <select class="select" style="width:auto" :value="state.course" @change="setCourse($event.target.value)">
            <option v-for="c in state.courses" :key="c.id" :value="c.id">
              {{ c.name }}{{ state.lang === 'all' ? '（' + c.lang_code + '）' : '' }}
            </option>
          </select>
        </div>
      </div>

      <div v-if="state.error" class="card">
        <h3>后端没起来</h3>
        <p class="muted">请在 <code class="mono">/path/to/langlab/backend</code> 下执行：<br>
          <code class="mono">D:\php82\php.exe -S 127.0.0.1:8001 -t public</code><br>
          或直接双击 <code class="mono">/path/to/langlab\启动语言学习器.bat</code></p>
      </div>

      <component v-else-if="state.ready" :is="current" :route="route" :go="go" />
      <div v-else class="loading">加载中…</div>
    </main>

    <!-- 移动端底部 Tab -->
    <nav class="tabs">
      <button v-for="t in MOBILE" :key="t.name" :class="{ on: navOn(t.name) }" @click="go(t.name)">
        <span class="ic">{{ t.ic }}</span><span>{{ t.label }}</span>
      </button>
    </nav>

    <div v-if="toast" class="toast">{{ toast }}</div>
  </div>
</template>
