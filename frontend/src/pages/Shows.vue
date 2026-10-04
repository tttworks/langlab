<script setup>
/**
 * 影视剧集 —— 三级浏览：类别（美剧/日剧）→ 剧名·第几季 → 每集
 *
 * 三级都放在 hash 路由里（#/shows/<课程>/<剧名|季>），这样浏览器前进/后退、
 * 收藏、从阅读页跳回来都能用。剧集结构来自后端 materials 的 series/season/ep 字段，
 * 前端**不解析标题**（解析只在入库时做一次，避免两处规则打架）。
 */
import { ref, computed, watch, onMounted, inject } from 'vue'

const api = inject('api')
const state = inject('state')
const say = inject('say')
const props = defineProps({ route: Object, go: Function })

const list = ref([])
const loading = ref(false)

// 类别 = 课程里 kind 是 script/drama 的那些（美剧台词 / 日剧台词）
const cats = computed(() => state.courses.filter((c) => c.kind === 'script' || c.kind === 'drama'))

const catId = computed(() => props.route.args[0] || '')
const seriesKey = computed(() => props.route.args[1] || '')
const cat = computed(() => cats.value.find((c) => c.id === catId.value) || null)

/* ---------------- 取数据 ---------------- */
async function load() {
  if (!cat.value) { list.value = []; return }
  loading.value = true
  try {
    const r = await api.materials({ course: cat.value.id })
    list.value = r.materials
  } catch (e) { say('读取素材失败：' + e.message) }
  loading.value = false
}
onMounted(load)
watch(catId, load)

/* ---------------- 第 2 级：剧名 · 第几季 ---------------- */
const grouped = computed(() => {
  const byKey = new Map()
  for (const m of list.value) {
    if (!m.series) continue
    const key = m.series + '|' + (m.season ?? '')
    if (!byKey.has(key)) {
      byKey.set(key, { key, series: m.series, season: m.season ?? null, eps: [] })
    }
    byKey.get(key).eps.push(m)
  }
  const seasonsOf = new Map()   // 剧名 → 出现过的季号集合
  for (const g of byKey.values()) {
    if (!seasonsOf.has(g.series)) seasonsOf.set(g.series, new Set())
    if (g.season !== null) seasonsOf.get(g.series).add(g.season)
  }
  const out = []
  for (const g of byKey.values()) {
    g.eps.sort((a, b) => (a.ep_sort ?? 1e9) - (b.ep_sort ?? 1e9) || a.id - b.id)
    // 一门剧只有一季时不必写「第 1 季」——用户口径就是「剧名 / 剧名·第几季」
    g.multi = (seasonsOf.get(g.series) || new Set()).size > 1
    g.label = g.season === null ? g.series
      : (g.multi ? g.series + ' · 第 ' + g.season + ' 季' : g.series)
    g.segments = g.eps.reduce((n, m) => n + (m.segment_count || 0), 0)
    g.readTotal = g.eps.reduce((n, m) => n + readOf(m), 0)
    out.push(g)
  }
  out.sort((a, b) => a.series.localeCompare(b.series, 'zh') || (a.season ?? 0) - (b.season ?? 0))
  return out
})

const orphan = computed(() => list.value.filter((m) => !m.series))

function readOf(m) { return (m.progress && m.progress.read_seq) || 0 }
function pct(m) {
  const t = m.segment_count || 0
  return t ? Math.round(readOf(m) / t * 100) : 0
}
function epLabel(m) {
  const t = m.ep_title || ''
  if (/finale|最终|最終/i.test(t)) return '最终回'
  if (/^sp$|special|特别|特別|生放送/i.test(t)) return '特别篇'
  if (m.ep === null || m.ep === undefined || m.ep === '') return '全季'
  const n = Number(m.ep)
  return '第 ' + (Number.isInteger(n) ? n : m.ep) + ' 集'
}
function epSub(m) {
  const t = m.ep_title || ''
  if (!t || /^sp$|finale/i.test(t)) return ''
  return t
}

/* ---------------- 当前选中的剧/季 ---------------- */
const cur = computed(() => grouped.value.find((g) => g.key === seriesKey.value) || null)
const curEps = computed(() => (cur.value ? cur.value.eps : []))

// 「继续」指向第一集还没读完的；都读完了就回到第一集
const resume = computed(() => {
  const eps = curEps.value
  if (!eps.length) return null
  return eps.find((m) => readOf(m) < (m.segment_count || 0)) || eps[eps.length - 1]
})

function openCat(id) { props.go('shows', id) }
function openSeries(key) { props.go('shows', catId.value, key) }
function openEp(m) { props.go('reader', m.id, 'intensive') }

async function markRead(m) {
  try {
    await api.setReadProgress(m.id, { read_seq: m.segment_count || 0 })
    m.progress = { ...(m.progress || {}), read_seq: m.segment_count || 0 }
    say('已标记《' + m.title + '》读毕')
  } catch (e) { say(e.message) }
}
</script>

<template>
  <!-- 面包屑 -->
  <div class="row" style="margin-bottom:12px" v-if="catId">
    <button class="btn gho sm" @click="props.go('shows')">← 类别</button>
    <button class="btn gho sm" v-if="seriesKey" @click="openCat(catId)">← {{ cat ? cat.name : '剧集' }}</button>
    <span class="crumb">
      {{ cat ? cat.name : '' }}<template v-if="cur"> / {{ cur.label }}</template>
    </span>
  </div>

  <!-- 第 1 级：类别 -->
  <div v-if="!catId">
    <div class="card">
      <h3>影视剧集<span class="sub">先选类别，再选剧名，最后挑一集</span></h3>
      <p class="small muted" style="margin:0">
        这里的每一集都是一份可精读、可批注、可朗读的语料；选中句子就能查词、写笔记。
      </p>
    </div>
    <div class="grid g2" style="margin-top:14px">
      <div v-for="c in cats" :key="c.id" class="card" style="cursor:pointer" @click="openCat(c.id)">
        <div class="row" style="justify-content:space-between;align-items:flex-start">
          <div>
            <h3 style="margin-bottom:4px">{{ c.name }}</h3>
            <p class="small muted" style="margin:0">{{ c.description || '按剧集浏览' }}</p>
          </div>
          <span class="tag p">{{ c.lang_code }}</span>
        </div>
        <div class="row mt">
          <span class="tag">{{ c.materials || 0 }} 集</span>
          <span class="tag p">进 →</span>
        </div>
      </div>
      <div v-if="!cats.length" class="card">
        <h3>还没有影视类课程</h3>
        <p class="small muted" style="margin:0">
          课程的 kind 设为 <code class="mono">script</code> 就会出现在这里。
        </p>
      </div>
    </div>
  </div>

  <!-- 第 2 级：剧名 · 第几季 -->
  <template v-else-if="!seriesKey">
    <div class="card">
      <h3>{{ cat.name }}<span class="sub">{{ grouped.length }} 部剧</span></h3>
      <p class="small muted" style="margin:0">
        选一部剧（同一部剧有多季时，会按季分开列），然后挑一集开始读。
      </p>
    </div>

    <div v-if="loading" class="loading">加载中…</div>
    <div v-else-if="!grouped.length" class="empty" style="margin-top:14px">
      <b>这门课下还没有可识别的剧集</b>
      剧集靠素材标题识别（形如 <code class="mono">The Good Wife S04E01 …</code> 或 <code class="mono">半泽直树2 S02E01</code>）。
      标题里没有 <code class="mono">S..E..</code> 的素材请在「素材库」里看。
    </div>

    <div v-else class="grid g2" style="margin-top:14px">
      <div v-for="g in grouped" :key="g.key" class="card" style="cursor:pointer" @click="openSeries(g.key)">
        <div class="row" style="justify-content:space-between;align-items:flex-start">
          <div>
            <h3 style="margin-bottom:4px">{{ g.label }}</h3>
            <div class="small muted">
              {{ g.eps.length }} 集 · {{ g.segments.toLocaleString() }} 段
            </div>
          </div>
          <span class="tag">{{ g.readTotal ? '在读' : '未开始' }}</span>
        </div>
        <div style="margin-top:10px">
          <div class="row small" style="justify-content:space-between">
            <span class="muted">已读</span>
            <b>{{ g.readTotal.toLocaleString() }} / {{ g.segments.toLocaleString() }} 段</b>
          </div>
          <div class="bar g"><i :style="{ width: (g.segments ? g.readTotal / g.segments * 100 : 0) + '%' }"></i></div>
        </div>
        <div class="row mt">
          <span class="btn sm">看剧集 →</span>
        </div>
      </div>
    </div>

    <p class="small muted" v-if="orphan.length" style="margin-top:12px">
      另有 <b>{{ orphan.length }}</b> 份素材没被识别成剧集（标题里没有 S..E.. 结构），
      <a href="#/materials" @click.prevent="props.go('materials')">去素材库看 →</a>
    </p>
  </template>

  <!-- 第 3 级：每集 -->
  <template v-else>
    <div class="card">
      <div class="row" style="justify-content:space-between;align-items:flex-start">
        <div>
          <h3 style="margin-bottom:4px">{{ cur ? cur.label : seriesKey }}</h3>
          <div class="small muted">
            共 {{ curEps.length }} 集 · {{ (cur ? cur.segments : 0).toLocaleString() }} 段 ·
            已读 {{ (cur ? cur.readTotal : 0).toLocaleString() }} 段
          </div>
        </div>
        <button class="btn pri" v-if="resume" @click="openEp(resume)">
          继续 {{ epLabel(resume) }}
        </button>
      </div>
    </div>

    <div v-if="loading" class="loading">加载中…</div>
    <div v-else class="card" style="margin-top:14px;padding:6px 14px">
      <div v-for="m in curEps" :key="m.id" class="eprow">
        <div class="eplab">
          <b>{{ epLabel(m) }}</b>
          <span class="small muted" v-if="epSub(m)">{{ epSub(m) }}</span>
        </div>
        <div class="epmeta small muted">
          {{ m.segment_count.toLocaleString() }} 段 · {{ m.word_count.toLocaleString() }} 词
        </div>
        <div class="epprog">
          <div class="bar g" style="min-width:70px"><i :style="{ width: pct(m) + '%' }"></i></div>
          <span class="small muted">{{ pct(m) }}%</span>
        </div>
        <div class="row" style="gap:6px">
          <button class="btn pri sm" @click="openEp(m)">{{ pct(m) ? '继续读' : '开始读' }}</button>
          <button class="btn sm" title="左台词右视频，点台词跳播对应片段" @click="props.go('drama', m.id)">跟读</button>
          <button class="btn sm" v-if="pct(m) < 100" title="把这一集标记为已读" @click="markRead(m)">标已读</button>
        </div>
      </div>
    </div>
  </template>
</template>
