<script setup>
/**
 * 影视台词跟读器 —— 左侧台词（角色名 + 时间轴），右侧视频。
 *
 * 交互核心：点一句台词 → 视频 seek 到该句起点并播放这一段，播到句尾按模式决定是否停下。
 * 目的是打磨"口音和断句"，所以额外提供：
 *   - 片段循环（反复听同一句）
 *   - 慢速播放（0.5x / 0.75x，听清吞音与连读）
 *   - 播放时高亮并自动滚动到当前句
 *
 * 数据来源：api.material(id) 返回的 segments，每句带 start_sec / end_sec / speaker。
 * 视频源：/api/materials/{id}/video（后端支持 Range，几百 MB 也能拖动）。
 */
import { ref, computed, watch, onMounted, onBeforeUnmount, inject, nextTick } from 'vue'

const api = inject('api')
const say = inject('say')
const props = defineProps({ route: Object, go: Function })

const MID = computed(() => Number(props.route.args[0] || 0))

const m = ref(null)
const segs = ref([])
const loading = ref(true)
const video = ref(null)
const listEl = ref(null)

/* ---------------- 播放状态 ---------------- */
const curIdx = ref(-1)        // 当前选中/正在播放的台词下标
const playing = ref(false)
const clipMode = ref(true)    // true：播到句尾停下；false：继续往下播
const loopClip = ref(false)   // 是否循环当前句
const loopTimes = ref(3)      // 循环次数
const playedLoops = ref(0)
const rate = ref(1)

const hasVideo = computed(() => !!(m.value && m.value.video_path))
const videoSrc = computed(() => `/api/materials/${MID.value}/video`)

// 有时间轴的句子比例 —— 用于告诉用户这一集能用多少
const tcCount = computed(() => segs.value.filter((s) => s.start_sec != null).length)
const tcPct = computed(() => (segs.value.length ? Math.round(tcCount.value / segs.value.length * 100) : 0))

const cur = computed(() => (curIdx.value >= 0 ? segs.value[curIdx.value] : null))

function fmt(t) {
  if (t == null) return '--:--'
  const s = Math.floor(t % 60)
  const mm = Math.floor(t / 60)
  const hh = Math.floor(mm / 60)
  const ss = String(s).padStart(2, '0')
  if (hh) return `${hh}:${String(mm % 60).padStart(2, '0')}:${ss}`
  return `${mm}:${ss}`
}

/* ---------------- 取数据 ---------------- */
async function load() {
  if (!MID.value) { loading.value = false; return }
  loading.value = true
  try {
    const r = await api.material(MID.value)
    m.value = r.material
    segs.value = r.segments || []
    curIdx.value = -1
  } catch (e) {
    say('读取素材失败：' + e.message)
  }
  loading.value = false
}
onMounted(load)
watch(MID, load)

/* ---------------- 点击台词 = 跳播对应片段 ---------------- */
function seekTo(i) {
  const s = segs.value[i]
  if (!s) return
  if (s.start_sec == null) { say('这句还没有时间轴，暂不能跳播'); return }
  if (!video.value) return
  curIdx.value = i
  playedLoops.value = 0
  video.value.playbackRate = rate.value
  video.value.pause()
  video.value.currentTime = s.start_sec
  video.value.play().catch(() => { say('浏览器阻止了自动播放，点一下视频再试') })
  scrollTo(i)
}

function scrollTo(i) {
  nextTick(() => {
    const el = listEl.value?.querySelector(`.ln.on`)
    if (el && el.scrollIntoView) el.scrollIntoView({ block: 'center', behavior: 'smooth' })
  })
}

/* ---------------- 时间轴事件 ---------------- */
function onTimeUpdate() {
  if (!video.value) return
  const t = video.value.currentTime
  playing.value = !video.value.paused

  // 高亮：找到当前时间落在哪一句
  if (segs.value.length) {
    let hit = -1
    for (let i = 0; i < segs.value.length; i++) {
      const s = segs.value[i]
      if (s.start_sec == null) continue
      const end = s.end_sec != null ? s.end_sec : s.start_sec + 3
      if (t >= s.start_sec - 0.05 && t < end) { hit = i; break }
    }
    if (hit >= 0 && hit !== curIdx.value) {
      curIdx.value = hit
      // 只在"跟着视频走"时才抢滚动，避免用户手动滚被拉回
      if (!userScrolling.value) scrollTo(hit)
    }
  }

  // 片段结束：停下 / 循环
  if (clipMode.value && cur.value && cur.value.end_sec != null && video.value.currentTime >= cur.value.end_sec) {
    if (loopClip.value && playedLoops.value < loopTimes.value) {
      playedLoops.value++
      video.value.currentTime = cur.value.start_sec
    } else {
      video.value.pause()
    }
  }
}

// 用户手动滚动时暂停自动跟随滚动 1.5s，避免"抢滚轮"
const userScrolling = ref(false)
let scrollTimer = null
function onListScroll() {
  userScrolling.value = true
  clearTimeout(scrollTimer)
  scrollTimer = setTimeout(() => (userScrolling.value = false), 1500)
}
onBeforeUnmount(() => { clearTimeout(scrollTimer) })

function setRate(r) {
  rate.value = r
  if (video.value) video.value.playbackRate = r
}
function toggleLoop() { loopClip.value = !loopClip.value; playedLoops.value = 0 }
function prevLine() { if (curIdx.value > 0) seekTo(curIdx.value - 1) }
function nextLine() { if (curIdx.value < segs.value.length - 1) seekTo(curIdx.value + 1) }

/* ---------------- 老师/字典联动 ---------------- */
async function addNote() {
  if (!cur.value) return
  try {
    await api.addNote({ lang: m.value.lang_code, text: cur.value.text, source: 'drama', material_id: MID.value })
    say('已加入笔记')
  } catch (e) { say('加入笔记失败：' + e.message) }
}
</script>

<template>
  <div v-if="loading" class="loading">加载中…</div>

  <div v-else-if="!m" class="empty">找不到这份素材</div>

  <div v-else class="dp">
    <!-- 顶部条 -->
    <div class="card dphead">
      <div class="row" style="justify-content:space-between;align-items:flex-start">
        <div>
          <h3 style="margin-bottom:4px">{{ m.title }}</h3>
          <div class="small muted">
            {{ m.series }} · 第 {{ m.season }} 季 · 第 {{ m.ep }} 集 ·
            {{ segs.length.toLocaleString() }} 句 ·
            时间轴覆盖 {{ tcPct }}%
            <template v-if="tcCount < segs.length">（{{ segs.length - tcCount }} 句暂无）</template>
          </div>
        </div>
        <div class="row" style="gap:6px">
          <button class="btn sm" @click="props.go('shows', m.course_id)">← 剧集</button>
          <button class="btn sm" @click="props.go('reader', m.id)">文本精读</button>
        </div>
      </div>

      <!-- 播放控制 -->
      <div class="row ctrl">
        <button class="btn sm" @click="prevLine">上一句</button>
        <button class="btn sm" @click="nextLine">下一句</button>
        <span class="sep"></span>
        <button class="btn sm" :class="{ pri: clipMode }" @click="clipMode = !clipMode"
                :title="clipMode ? '播完当前句就停' : '播完继续往下'">
          {{ clipMode ? '只播这句' : '连续播放' }}
        </button>
        <button class="btn sm" :class="{ pri: loopClip }" @click="toggleLoop">
          循环{{ loopClip ? `(${loopTimes})` : '' }}
        </button>
        <select class="select sm" v-if="loopClip" v-model.number="loopTimes" style="width:auto">
          <option v-for="n in [3, 5, 10]" :key="n" :value="n">{{ n }} 次</option>
        </select>
        <span class="sep"></span>
        <label class="small muted">速度</label>
        <select class="select sm" :value="rate" @change="setRate(Number($event.target.value))" style="width:auto">
          <option :value="0.5">0.5x</option>
          <option :value="0.75">0.75x</option>
          <option :value="1">1.0x</option>
        </select>
      </div>
    </div>

    <div class="dpsplit">
      <!-- 左：台词 -->
      <div class="card dplist" ref="listEl" @scroll="onListScroll">
        <div v-if="!segs.length" class="small muted">这一集还没有台词</div>
        <div v-for="(s, i) in segs" :key="s.id" class="ln" :class="{ on: i === curIdx, notc: s.start_sec == null }"
             @click="seekTo(i)">
          <div class="lnmeta">
            <span v-if="s.speaker" class="spk">{{ s.speaker }}</span>
            <span class="tc mono">{{ fmt(s.start_sec) }}</span>
          </div>
          <div class="lntx">{{ s.text }}</div>
        </div>
      </div>

      <!-- 右：视频 -->
      <div class="card dpvideo">
        <video v-if="hasVideo" ref="video" :src="videoSrc" controls preload="metadata"
               @timeupdate="onTimeUpdate" @play="playing = true" @pause="playing = false"
               class="vtag"></video>
        <div v-else class="empty small">
          这一集还没绑定视频文件。<br>
          需要先把可用的 <code class="mono">.mp4</code> 写进材料的 <code class="mono">video_path</code>。
        </div>

        <div v-if="cur" class="curbtn mono">
          {{ cur.speaker ? cur.speaker + '：' : '' }}{{ cur.text }}
        </div>
        <div class="row" style="gap:6px;margin-top:8px">
          <button class="btn sm" :disabled="!cur" @click="addNote">收藏这句</button>
          <button class="btn sm" v-if="cur" @click="seekTo(curIdx)">重播这句</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.dp { display: flex; flex-direction: column; gap: 12px; }
.dphead h3 { margin: 0; }
.ctrl { flex-wrap: wrap; gap: 6px; margin-top: 10px; align-items: center; }
.sep { width: 1px; height: 20px; background: var(--line, #e5e7eb); margin: 0 4px; }
.select.sm { padding: 3px 6px; font-size: 12px; }

.dpsplit { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.15fr); gap: 12px; align-items: start; }
@media (max-width: 980px) { .dpsplit { grid-template-columns: 1fr; } }

.dplist { max-height: 62vh; overflow-y: auto; padding: 6px 10px; }
.ln { padding: 7px 8px; border-radius: 8px; cursor: pointer; border: 1px solid transparent; }
.ln:hover { background: rgba(0, 0, 0, .035); }
.ln.on { background: rgba(59, 130, 246, .10); border-color: rgba(59, 130, 246, .5); }
.ln.notc { opacity: .5; cursor: default; }
.lnmeta { display: flex; align-items: center; gap: 8px; margin-bottom: 2px; }
.spk { font-size: 11px; font-weight: 700; letter-spacing: .3px; color: #2563eb;
       background: rgba(37, 99, 235, .1); padding: 1px 6px; border-radius: 999px; }
.tc { font-size: 11px; color: #9ca3af; }
.lntx { font-size: 14px; line-height: 1.55; }

.dpvideo { position: sticky; top: 8px; }
.vtag { width: 100%; border-radius: 8px; background: #000; aspect-ratio: 16/9; }
.curbtn { margin-top: 8px; font-size: 13px; padding: 8px 10px; border-radius: 8px;
          background: rgba(0, 0, 0, .04); min-height: 34px; }
</style>
