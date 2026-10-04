<script setup>
import { ref, reactive, computed, onMounted, watch, inject } from 'vue'

const api = inject('api')
const state = inject('state')
const say = inject('say')
const props = defineProps({ go: Function })

const list = ref([])
const loading = ref(true)
const filterType = ref('all')
const showImport = ref(false)
const busy = ref(false)

const form = reactive({
  mode: 'paste',   // paste | url | file | path
  title: '',
  url: '',
  text: '',
  path: '',
})
const picked = ref(null)      // File 对象
const dragOver = ref(false)

const TYPES = [
  { k: 'all', label: '全部' },
  { k: 'epub', label: 'EPUB' },
  { k: 'pdf', label: 'PDF' },
  { k: 'docx', label: '教材 DOCX' },
  { k: 'script', label: '台词本' },
  { k: 'srt', label: '字幕' },
  { k: 'paste', label: '粘贴语料' },
  { k: 'url', label: '网文抓取' },
  { k: 'md', label: 'MD / TXT' },
]
const TYPE_LABEL = {
  paste: '粘贴语料', url: '网文', epub: 'EPUB', pdf: 'PDF', docx: 'DOCX',
  script: '台词本', dialogue: '台词本',
  subtitle: '字幕', srt: '字幕', vtt: '字幕', ass: '字幕', sub: '字幕',
  md: 'Markdown', txt: '纯文本', html: '网页', htm: '网页',
}
const FILE_TYPES = ['epub', 'pdf', 'docx', 'txt', 'md', 'html', 'htm', 'srt', 'vtt']

const shown = computed(() => list.value.filter((m) => filterType.value === 'all' || m.type === filterType.value))
const LANG = () => (state.courses.find((c) => c.id === state.course) || {}).lang_code || 'en-US'

// 影视类课程：提示走「影视剧集」三级浏览（剧名/季/集），比在这张平铺列表里找集号顺手
const curCourse = computed(() => state.courses.find((c) => c.id === state.course) || null)
const isDrama = computed(() => ['script', 'drama'].includes((curCourse.value || {}).kind))
const seriesCount = computed(() => new Set(list.value.filter((m) => m.series).map((m) => m.series + '|' + m.season)).size)

async function load() {
  loading.value = true
  try {
    const r = await api.materials({ lang: state.lang, course: state.course })
    list.value = r.materials
  } catch (e) { say(e.message) }
  loading.value = false
}

async function submit() {
  busy.value = true
  try {
    if (form.mode === 'paste') {
      if (!form.text.trim()) { busy.value = false; return say('正文不能为空') }
      const r = await api.importMaterial({
        lang_code: LANG(), course_id: state.course || null,
        type: 'paste', title: form.title, text: form.text,
      })
      say(r.message || '导入成功')
      form.text = ''
    } else if (form.mode === 'url') {
      if (!form.url.trim()) { busy.value = false; return say('请填网址') }
      const r = await api.importMaterial({
        lang_code: LANG(), course_id: state.course || null,
        type: 'url', title: form.title, url: form.url,
      })
      say(r.message || '导入成功')
      form.url = ''
    } else if (form.mode === 'file') {
      if (!picked.value) { busy.value = false; return say('先选一个文件（也可直接把文件拖进来）') }
      say('正在上传并解析《' + picked.value.name + '》…')
      const r = await api.uploadMaterial(picked.value, {
        lang_code: LANG(), course_id: state.course || '', title: form.title,
      })
      say(r.message || '导入成功')
      picked.value = null
    } else if (form.mode === 'path') {
      if (!form.path.trim()) { busy.value = false; return say('请填本地文件路径') }
      say('正在解析（原文件不会被动）…')
      const r = await api.linkMaterial({
        path: form.path.trim(), lang_code: LANG(), course_id: state.course || null, title: form.title,
      })
      say(r.message || '导入成功')
      form.path = ''
    }
    form.title = ''
    showImport.value = false
    await load()
  } catch (e) {
    say('导入失败：' + e.message)
  }
  busy.value = false
}

function onPick(e) {
  const f = e.target.files && e.target.files[0]
  if (f) { picked.value = f; form.mode = 'file'; showImport.value = true }
}
function onDrop(e) {
  dragOver.value = false
  const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]
  if (f) {
    picked.value = f
    form.mode = 'file'
    showImport.value = true
    say('已选中《' + f.name + '》，点「导入」开始解析')
  }
}
function fmtSize(n) {
  if (!n) return ''
  return n > 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.round(n / 1024) + ' KB'
}

async function del(m) {
  if (!confirm('确定删除《' + m.title + '》？切段、批注与该素材产生的词条一并清理。')) return
  try { await api.delMaterial(m.id); say('已删除'); await load() } catch (e) { say(e.message) }
}

const indexing = ref(0)
async function index(m) {
  indexing.value = m.id
  try {
    const r = await api.indexMaterial(m.id)
    say(r.message || '已生成词条')
  } catch (e) { say('生成失败：' + e.message) }
  indexing.value = 0
}

onMounted(load)
watch(() => [state.lang, state.course], load)
</script>

<template>
  <div class="card" style="padding:14px 16px"
       :class="{ dropzone: true, over: dragOver }"
       @dragover.prevent="dragOver = true" @dragleave.prevent="dragOver = false" @drop.prevent="onDrop">
    <div class="row">
      <span v-for="t in TYPES" :key="t.k" class="chip" :class="{ on: filterType === t.k }"
            @click="filterType = t.k">{{ t.label }}</span>
      <div class="sp" style="flex:1"></div>
      <label class="btn" style="margin-right:6px">
        选择文件…
        <input type="file" style="display:none" :accept="'.' + FILE_TYPES.join(',.')" @change="onPick" />
      </label>
      <button class="btn pri" @click="showImport = !showImport">{{ showImport ? '收起导入' : '+ 导入素材' }}</button>
    </div>
    <div class="small muted" style="margin-top:7px">
      直接把 EPUB / PDF / DOCX / TXT / MD / 字幕文件拖到这块面板上也可以。
    </div>
  </div>

  <!-- 影视类课程的引导：这类素材按「剧名·第几季 → 每集」看更顺手 -->
  <div class="card" v-if="isDrama" style="border-color:var(--primary-line);background:var(--primary-soft)">
    <div class="row" style="justify-content:space-between">
      <div>
        <b style="font-size:13.5px">{{ curCourse.name }} 是整季剧集</b>
        <div class="small" style="color:var(--primary-ink);margin-top:3px">
          已识别 {{ seriesCount }} 个「剧名 · 季」组合，建议按 类别 → 剧名·第几季 → 每集 浏览。
        </div>
      </div>
      <button class="btn pri sm" @click="props.go('shows', state.course)">去影视剧集 →</button>
    </div>
  </div>

  <!-- 导入面板 -->
  <div class="card" v-if="showImport">
    <h3>导入素材</h3>
    <div class="row">
      <button class="btn" :class="{ on: form.mode === 'file' }" @click="form.mode = 'file'">上传文件（复制进来）</button>
      <button class="btn" :class="{ on: form.mode === 'path' }" @click="form.mode = 'path'">登记本地路径（不动原文件）</button>
      <button class="btn" :class="{ on: form.mode === 'paste' }" @click="form.mode = 'paste'">粘贴正文</button>
      <button class="btn" :class="{ on: form.mode === 'url' }" @click="form.mode = 'url'">指定网址</button>
    </div>
    <div class="small muted" style="margin-top:6px">
      支持：<span class="mono">{{ FILE_TYPES.join(' / ') }}</span>
      · 导入后自动抽正文、切段、建词典索引，原版文件可在「原版」模式下内嵌阅读
    </div>

    <div class="row mt">
      <input class="input" style="max-width:340px" v-model="form.title" placeholder="标题（可留空，自动取文件名/正文开头）" />
      <span class="small muted">归到课程：<b>{{ (state.courses.find(c => c.id === state.course) || {}).name || '未指定' }}</b></span>
    </div>

    <template v-if="form.mode === 'file'">
      <div class="filepick" :class="{ over: dragOver }"
           @dragover.prevent="dragOver = true" @dragleave.prevent="dragOver = false"
           @drop.prevent="onDrop" @click="$el.querySelector('input[type=file]').click()">
        <template v-if="picked">
          已选中：<b>{{ picked.name }}</b>（{{ fmtSize(picked.size) }}）
        </template>
        <template v-else>点这里选文件，或把文件拖进来</template>
      </div>
    </template>

    <input v-else-if="form.mode === 'path'" class="input mt" v-model="form.path"
           placeholder="/path/to/your/materials" />
    <input v-else-if="form.mode === 'url'" class="input mt" v-model="form.url"
           placeholder="https://…（抓正文并去标签；抓不到会自动提示改为粘贴）" />
    <textarea v-else class="textarea mt" v-model="form.text"
              placeholder="把电子教材段落、文章正文、视频逐字稿、影视剧本、演讲稿粘到这里。空行分段。"></textarea>

    <div class="row mt">
      <button class="btn pri" :disabled="busy" @click="submit">{{ busy ? '处理中…' : '导入' }}</button>
      <span class="small muted" v-if="form.mode === 'file'">上传会复制一份到 backend\data\files\</span>
      <span class="small muted" v-else-if="form.mode === 'path'">原文件原地不动（沿用工作台的「旧文件不动」原则）</span>
    </div>
  </div>

  <div v-if="loading" class="loading">加载中…</div>
  <div v-else-if="!shown.length" class="empty" style="margin-top:14px">
    <b>还没有素材</b>点右上「+ 导入素材」，粘一段正文或给一个网址就能开始。
  </div>

  <div v-else class="grid g2" style="margin-top:14px">
    <div v-for="m in shown" :key="m.id" class="card">
      <div class="row" style="justify-content:space-between;align-items:flex-start">
        <div style="min-width:0">
          <h3 style="margin-bottom:5px;word-break:break-word">{{ m.title }}</h3>
          <div class="row">
            <span class="tag p">{{ TYPE_LABEL[m.type] || m.type }}</span>
            <span class="tag" v-if="m.series">剧集 {{ m.series }} · 第 {{ m.season }} 季</span>
            <span class="tag mono">{{ m.lang_code }}</span>
            <span class="tag">{{ m.segment_count }} 段</span>
            <span class="tag">{{ m.word_count }} 词</span>
            <span class="tag c" v-if="m.has_file">原版 {{ fmtSize(m.file_size) }}</span>
            <span class="tag" v-if="m.author">{{ m.author }}</span>
          </div>
        </div>
      </div>
      <p class="small muted" style="margin:9px 0 0" v-if="m.source_url">
        来源：<a :href="m.source_url" target="_blank" rel="noreferrer">{{ m.source_url.slice(0, 60) }}</a>
      </p>
      <div style="margin-top:10px">
        <div class="row small" style="justify-content:space-between">
          <span class="muted">阅读进度</span>
          <b>{{ m.progress && m.progress.read_seq ? m.progress.read_seq : 0 }} / {{ m.segment_count }} 段</b>
        </div>
        <div class="bar g"><i :style="{ width: (m.segment_count ? ((m.progress && m.progress.read_seq) || 0) / m.segment_count * 100 : 0) + '%' }"></i></div>
      </div>
      <div class="row mt">
        <button class="btn pri sm" @click="props.go('reader', m.id)">
          {{ m.has_file && ['epub', 'pdf'].includes(m.type) ? '阅读 / 原版' : '阅读' }}
        </button>
        <button class="btn sm" :disabled="indexing === m.id" @click="index(m)">
          {{ indexing === m.id ? '分词中…' : '生成词条' }}
        </button>
        <button class="btn gho sm" @click="del(m)">删除</button>
      </div>
    </div>
  </div>
</template>
