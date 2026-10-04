<script setup>
import { ref, onMounted, inject } from 'vue'

const api = inject('api')
const state = inject('state')
const say = inject('say')
const props = defineProps({ go: Function })

const d = ref(null)
const loading = ref(true)

onMounted(async () => {
  try { d.value = await api.dashboard('all') } catch (e) { say('大盘数据读取失败：' + e.message) }
  loading.value = false
})

const KIND = { contract: '合同语料', textbook: '教材', reader: '读物', article: '文章', custom: '自建' }
function langCourses(code) {
  return state.courses.filter((c) => c.lang_code === code)
}
function pickLang(l) {
  state.lang = l.code
  localStorage.setItem('langlab.lang', l.code)
  const first = state.courses.find((c) => c.lang_code === l.code)
  if (first) { state.course = first.id; localStorage.setItem('langlab.course', first.id) }
  props.go('courses')
}
</script>

<template>
  <div v-if="loading" class="loading">加载中…</div>

  <template v-else-if="d">
    <!-- 继续学习 -->
    <div class="card" v-if="state.course">
      <h3>继续学习<span class="sub">上次的课程已记住</span></h3>
      <div class="row">
        <div>
          <div style="font-size:16px;font-weight:700">{{ (state.courses.find(c => c.id === state.course) || {}).name }}</div>
          <div class="small muted">
            {{ (state.courses.find(c => c.id === state.course) || {}).description }}
          </div>
        </div>
        <div class="sp" style="flex:1"></div>
        <button class="btn pri" @click="props.go('cards')">继续卡片学习</button>
        <button class="btn" @click="props.go('materials')">看素材</button>
      </div>
    </div>

    <!-- 语言 -->
    <div class="card">
      <h3>语种<span class="sub">点一门语言进入它的课程</span></h3>
      <div class="grid g-auto">
        <div v-for="l in d.by_lang" :key="l.code" class="kpi" style="cursor:pointer"
             @click="pickLang(l)">
          <div class="row" style="justify-content:space-between">
            <span class="k">{{ l.name_zh }}</span>
            <span class="tag" :class="l.cards_total ? 'p' : ''">{{ l.cards_total ? '已建' : '预留' }}</span>
          </div>
          <div class="row" style="gap:14px;margin-top:6px">
            <div><div class="k">课程</div><div style="font-size:17px;font-weight:700">{{ l.courses }}</div></div>
            <div><div class="k">素材</div><div style="font-size:17px;font-weight:700">{{ l.materials }}</div></div>
            <div><div class="k">卡片</div><div style="font-size:17px;font-weight:700">{{ l.cards_total }}</div></div>
          </div>
          <div style="margin-top:9px">
            <div class="row small" style="justify-content:space-between">
              <span class="muted">掌握率</span>
              <b>{{ l.rate }}%</b>
            </div>
            <div class="bar"><i :style="{ width: l.rate + '%' }"></i></div>
          </div>
          <div v-if="!l.courses" class="small muted" style="margin-top:7px">语言已预留，尚未添加课程</div>
        </div>
      </div>
    </div>

    <!-- 语言 × 课程 -->
    <div class="card">
      <h3>课程总览<span class="sub">两门外语各自独立，互不相关</span></h3>
      <table class="tb">
        <thead>
          <tr><th>语种</th><th>课程</th><th>类型</th><th class="n">卡片</th><th class="n">已掌握</th><th>进度</th><th class="n">素材</th><th></th></tr>
        </thead>
        <tbody>
          <tr v-for="c in d.by_course" :key="c.id">
            <td class="mono small">{{ c.lang_code }}</td>
            <td><b>{{ c.name }}</b></td>
            <td><span class="tag">{{ KIND[c.kind] || c.kind }}</span></td>
            <td class="n">{{ c.cards_total }}</td>
            <td class="n">{{ c.cards_mastered }}</td>
            <td style="min-width:110px">
              <div class="bar"><i :style="{ width: c.rate + '%' }"></i></div>
              <div class="small muted">{{ c.rate }}%</div>
            </td>
            <td class="n">{{ c.materials }}</td>
            <td>
              <div class="row" style="gap:6px">
                <button class="btn sm" @click="state.lang = c.lang_code; state.course = c.id; props.go('cards')">学习</button>
                <button class="btn sm" v-if="c.kind === 'script' || c.kind === 'drama'"
                        @click="state.lang = c.lang_code; state.course = c.id; props.go('shows', c.id)">看剧集</button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- 素材类型说明 -->
    <div class="card">
      <h3>可以导入的素材<span class="sub">导入后自动切段，供阅读、批注、查词、记笔记</span></h3>
      <div class="row">
        <span class="tag p">电子教材</span>
        <span class="tag p">电子书 EPUB</span>
        <span class="tag p">PDF 读物</span>
        <span class="tag v">手动粘贴语料</span>
        <span class="tag v">指定网上文章</span>
        <span class="tag c">视频逐字稿</span>
        <span class="tag c">影视剧本</span>
        <span class="tag a">知名演讲稿</span>
        <span class="tag a">自媒体文章 / 新闻</span>
      </div>
      <p class="small muted" style="margin:10px 0 0">
        已支持：手动粘贴、指定 URL 抓正文（自动去标签）、EPUB / PDF / DOCX / TXT / MD / 字幕文件导入与内嵌阅读。
        影视台词本与字幕类素材，可到「影视剧集」按 类别 → 剧名·第几季 → 每集 三级浏览。
      </p>
    </div>
  </template>
</template>
