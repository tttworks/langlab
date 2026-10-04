<script setup>
/**
 * 原版阅读器：EPUB 走 epub.js，PDF 走 pdf.js。两者都是本地化文件（零外链）。
 * 只负责「读原版 + 记进度」；划词批注在「精读」模式里做（那里有切段文本）。
 */
import { ref, computed, onMounted, onBeforeUnmount, nextTick, inject } from 'vue'

const api = inject('api')
const say = inject('say')
const props = defineProps({ material: Object })

const host = ref(null)
const canvas = ref(null)
const wrap = ref(null)
const loading = ref(true)
const err = ref('')
const ready = ref(false)

const type = computed(() => (props.material ? String(props.material.type || '').toLowerCase() : ''))
const fileUrl = computed(() => (props.material ? `/api/materials/${props.material.id}/file` : ''))
const isPdf = computed(() => type.value === 'pdf')
const isEpub = computed(() => type.value === 'epub')

/* ---------------- 通用 UI 状态 ---------------- */
const fullscreen = ref(false)
const pct = ref(0)

/* ---------------- PDF ---------------- */
const pdf = { doc: null, page: 1, total: 0, scale: 1, mode: 'fitw' }
const pdfPage = ref(1)
const pdfTotal = ref(0)
const pdfZoom = ref(1)
const pdfMode = ref('fitw')

function computeScale() {
  const p = pdf.doc ? pdf.doc.getPage(pdf.page) : null
  if (!p) return 1
  return p.then((page) => {
    const vp = page.getViewport({ scale: 1 })
    const box = wrap.value || document.body
    const w = (box.clientWidth || 900) - 28
    const h = (window.innerHeight || 800) - 170
    if (pdfMode.value === 'fitw') return w / vp.width
    if (pdfMode.value === 'fith') return h / vp.height
    return 1
  })
}

async function renderPdf() {
  if (!pdf.doc) return
  const page = await pdf.doc.getPage(pdf.page)
  const s = await computeScale()
  pdf.scale = s
  pdfZoom.value = Math.round(s * 100) / 100
  const viewport = page.getViewport({ scale: s })
  const cv = canvas.value
  if (!cv) return
  const dpr = Math.min(window.devicePixelRatio || 1, 2)
  cv.width = Math.floor(viewport.width * dpr)
  cv.height = Math.floor(viewport.height * dpr)
  cv.style.width = Math.floor(viewport.width) + 'px'
  cv.style.height = Math.floor(viewport.height) + 'px'
  const ctx = cv.getContext('2d')
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0)
  ctx.clearRect(0, 0, viewport.width, viewport.height)
  await page.render({ canvasContext: ctx, viewport }).promise
  pdfPage.value = pdf.page
  pct.value = pdf.total ? Math.round((pdf.page / pdf.total) * 100) : 0
  saveProgress({ page: pdf.page, total_pages: pdf.total, percent: pct.value })
}

async function initPdf() {
  const lib = window.pdfjsLib
  if (!lib) throw new Error('pdf.js 未加载（frontend/public/pdf.min.js）')
  if (lib.GlobalWorkerOptions) lib.GlobalWorkerOptions.workerSrc = '/pdf.worker.min.js'
  pdf.doc = await lib.getDocument(fileUrl.value).promise
  pdf.total = pdf.doc.numPages
  pdfTotal.value = pdf.doc.numPages
  const saved = props.material.progress && props.material.progress.page
  pdf.page = saved && saved <= pdf.total ? saved : 1
  await renderPdf()
}

function pdfGo(n) {
  const t = Math.max(1, Math.min(pdf.total, n))
  if (t === pdf.page) return
  pdf.page = t
  renderPdf()
}
function setPdfMode(m) {
  pdfMode.value = m
  renderPdf()
}

/* ---------------- EPUB ---------------- */
// ⚠️ 必须用 ArrayBuffer 加载。epub.js 直接传 URL 会静默失败（openFailed:
//    Cannot load book at …），工作台的开发记录里也踩过这条，结论一致。
const book = ref(null)
const rendition = ref(null)
const toc = ref([])
const showToc = ref(false)
const spread = ref('none')
const dlPct = ref(0)

async function fetchArrayBuffer(url, onProgress) {
  const res = await fetch(url)
  if (!res.ok) throw new Error('取文件失败：HTTP ' + res.status)
  const total = Number(res.headers.get('content-length') || 0)
  if (!res.body || !total) return await res.arrayBuffer()
  const reader = res.body.getReader()
  const chunks = []
  let got = 0
  for (;;) {
    const { done, value } = await reader.read()
    if (done) break
    chunks.push(value)
    got += value.length
    if (onProgress) onProgress(Math.min(99, Math.round((got / total) * 100)))
  }
  const out = new Uint8Array(got)
  let off = 0
  for (const c of chunks) { out.set(c, off); off += c.length }
  return out.buffer
}

function withTimeout(promise, ms, tag) {
  return Promise.race([
    promise,
    new Promise((_, rej) => setTimeout(() => rej(new Error(tag + ' 超时（' + Math.round(ms / 1000) + 's）')), ms)),
  ])
}

async function initEpub() {
  const lib = window.ePub
  if (!lib) throw new Error('epub.js 未加载（frontend/public/epub.min.js）')

  const buf = await fetchArrayBuffer(fileUrl.value, (p) => { dlPct.value = p })
  dlPct.value = 100

  const b = lib(buf)
  book.value = b
  b.on('openFailed', (e) => { err.value = '打开失败：' + (e && e.message ? e.message : e) })
  await withTimeout(b.ready, 40000, '解析 EPUB')

  const r = b.renderTo(host.value, {
    width: '100%', height: '100%', spread: spread.value, flow: 'paginated',
  })
  rendition.value = r
  r.on('relocated', (loc) => {
    if (loc.start && typeof loc.start.percentage === 'number') {
      pct.value = Math.round(loc.start.percentage * 100)
    } else if (b.locations && b.locations.length()) {
      pct.value = Math.round(b.locations.percentageFromCfi(loc.start.cfi) * 100)
    }
    saveProgress({ cfi: loc.start.cfi, percent: pct.value })
  })
  r.on('keydown', onKey)

  const saved = props.material.progress && props.material.progress.cfi
  await withTimeout(r.display(saved || undefined), 40000, '渲染章节')
  ready.value = true
  // 容器尺寸可能因浮层层级/字体加载而变化，补一次重排
  await nextTick()
  try { r.resize() } catch (e) {}

  // 目录（后台生成，不阻塞显示）
  try {
    const nav = await b.loaded.navigation
    toc.value = (nav && nav.toc) || []
  } catch (e) { /* 无目录不影响阅读 */ }
  // 全书定位（用于百分比）；大书会慢，放后台
  setTimeout(() => { try { b.locations.generate(600) } catch (e) {} }, 1200)
}

function epubPrev() { rendition.value && rendition.value.prev() }
function epubNext() { rendition.value && rendition.value.next() }
async function toggleSpread() {
  spread.value = spread.value === 'none' ? 'always' : 'none'
  if (rendition.value) {
    rendition.value.spread(spread.value)
    await nextTick()
  }
}
function gotoHref(href) {
  showToc.value = false
  rendition.value && rendition.value.display(href)
}
const tocFlat = computed(() => {
  const out = []
  const walk = (items, depth) => {
    (items || []).forEach((it) => {
      out.push({ label: it.label ? String(it.label).trim() : '（无标题）', href: it.href, depth })
      if (it.subitems && it.subitems.length) walk(it.subitems, depth + 1)
    })
  }
  walk(toc.value, 0)
  return out
})

/* ---------------- 进度 / 键盘 / 全屏 ---------------- */
let pt = null
function saveProgress(data) {
  clearTimeout(pt)
  pt = setTimeout(() => {
    api.setReadProgress(props.material.id, data).catch(() => {})
  }, 700)
}

function onKey(e) {
  const k = e.key
  if (k === 'ArrowRight' || k === 'PageDown' || k === ' ') { e.preventDefault(); isPdf.value ? pdfGo(pdf.page + 1) : epubNext() }
  else if (k === 'ArrowLeft' || k === 'PageUp') { e.preventDefault(); isPdf.value ? pdfGo(pdf.page - 1) : epubPrev() }
  else if (k === 'Home') { isPdf.value ? pdfGo(1) : (rendition.value && rendition.value.display(undefined)) }
  else if (k === 'End') { if (isPdf.value) pdfGo(pdf.total) }
  else if (k === 'Escape' && document.fullscreenElement) document.exitFullscreen()
}
function toggleFullscreen() {
  const el = wrap.value
  if (!el) return
  if (document.fullscreenElement) document.exitFullscreen()
  else el.requestFullscreen && el.requestFullscreen()
}
function onFsChange() { fullscreen.value = !!document.fullscreenElement }
function onResize() { if (isPdf.value && ready.value) renderPdf() }

onMounted(async () => {
  document.addEventListener('fullscreenchange', onFsChange)
  window.addEventListener('resize', onResize)
  try {
    if (isPdf.value) { await initPdf(); ready.value = true }
    else if (isEpub.value) { await initEpub() }
    else err.value = '该素材没有可读的原版文件（' + type.value + '）'
  } catch (e) {
    err.value = String(e.message || e)
  }
  loading.value = false
})
onBeforeUnmount(() => {
  document.removeEventListener('fullscreenchange', onFsChange)
  window.removeEventListener('resize', onResize)
  try { rendition.value && rendition.value.destroy() } catch (e) {}
  try { book.value && book.value.destroy() } catch (e) {}
  try { pdf.doc && pdf.doc.destroy() } catch (e) {}
})
</script>

<template>
  <div class="card" style="padding:12px 14px">
    <div class="row">
      <span class="tag p">{{ isPdf ? 'PDF 原版' : 'EPUB 原版' }}</span>
      <span class="small muted">{{ material.title }}</span>
      <div class="sp" style="flex:1"></div>

      <template v-if="isPdf && ready">
        <button class="btn sm" @click="pdfGo(1)" title="首页">⤒</button>
        <button class="btn sm" @click="pdfGo(pdf.page - 1)">‹</button>
        <input class="input" style="width:62px;text-align:center;padding:4px 6px" :value="pdfPage"
               @change="pdfGo(Number($event.target.value))" />
        <span class="small muted">/ {{ pdfTotal }}</span>
        <button class="btn sm" @click="pdfGo(pdf.page + 1)">›</button>
        <button class="btn sm" @click="pdfGo(pdfTotal)" title="尾页">⤓</button>
        <span class="small muted" style="margin-left:6px">缩放</span>
        <button class="btn sm" :class="{ on: pdfMode === 'fitw' }" @click="setPdfMode('fitw')">适宽</button>
        <button class="btn sm" :class="{ on: pdfMode === 'fith' }" @click="setPdfMode('fith')">适高</button>
        <button class="btn sm" :class="{ on: pdfMode === '100' }" @click="setPdfMode('100')">100%</button>
      </template>

      <template v-if="isEpub && ready">
        <button class="btn sm" @click="epubPrev()">‹</button>
        <button class="btn sm" @click="epubNext()">›</button>
        <button class="btn sm" :class="{ on: spread === 'always' }" @click="toggleSpread">双页</button>
        <button class="btn sm" :class="{ on: showToc }" @click="showToc = !showToc">目录</button>
      </template>

      <button class="btn sm" @click="toggleFullscreen">{{ fullscreen ? '退出全屏' : '全屏' }}</button>
      <span class="small muted" style="min-width:44px;text-align:right">{{ pct }}%</span>
    </div>
    <div class="small muted" style="margin-top:6px">
      ← → / PageUp PageDown / 空格 翻页 · Home 首页 · Esc 退出全屏 · 划词批注请切到「精读」
    </div>
  </div>

  <!-- 容器必须常驻且可见：epub.js 在 display:none 的容器里会量到 0 高度而排版失败 -->
  <div ref="wrap" class="readerbox" :class="{ fs: fullscreen }" tabindex="0" @keydown="onKey">
    <div v-if="loading || err" class="readeroverlay">
      <div v-if="loading" class="loading">
        正在载入原版文件…
        <template v-if="isEpub && dlPct > 0 && dlPct < 100">下载 {{ dlPct }}%（大书首次打开会慢一些）</template>
        <template v-else-if="isEpub">解析中（大书首次打开会慢一些）</template>
      </div>
      <div v-else class="empty">
        <b>原版打不开</b>{{ err }}
      </div>
    </div>

    <div v-if="isPdf" class="pdfhost">
      <canvas ref="canvas"></canvas>
    </div>
    <div v-else ref="host" class="epubhost"></div>

    <div v-if="isEpub && showToc && ready" class="tocbox">
      <div class="row" style="justify-content:space-between;margin-bottom:6px">
        <b>目录</b><button class="btn gho sm" @click="showToc = false">×</button>
      </div>
      <div v-if="!tocFlat.length" class="small muted">这本书没有目录信息</div>
      <div v-for="(t, i) in tocFlat" :key="i" class="tocitem"
           :style="{ paddingLeft: (8 + t.depth * 12) + 'px' }" @click="gotoHref(t.href)">
        {{ t.label }}
      </div>
    </div>
  </div>
</template>

<style scoped>
.readerbox {
  position: relative; margin-top: 12px; background: var(--surface);
  border: 1px solid var(--border); border-radius: var(--r-lg); overflow: auto;
  height: calc(100vh - 260px); min-height: 420px; outline: none;
}
.readerbox.fs { height: 100vh; border-radius: 0; border: 0; }
.readeroverlay { position: absolute; inset: 0; z-index: 30; background: var(--surface);
  display: grid; place-items: center; padding: 24px; }
.readeroverlay .empty { border: 0; background: transparent; max-width: 520px; }
.pdfhost { display: flex; justify-content: center; padding: 14px; background: #eceef4; min-height: 100%; }
.pdfhost canvas { background: #fff; box-shadow: var(--sh-2); border-radius: 3px; }
.epubhost { height: 100%; }
.tocbox {
  position: absolute; top: 10px; right: 10px; width: 288px; max-height: 66%;
  overflow: auto; background: var(--surface); border: 1px solid var(--border-strong);
  border-radius: var(--r-md); box-shadow: var(--sh-2); padding: 10px 12px; z-index: 20;
}
.tocitem { padding: 5px 6px; border-radius: var(--r-sm); cursor: pointer; font-size: 13px; }
.tocitem:hover { background: var(--primary-soft); color: var(--primary-ink); }
</style>
