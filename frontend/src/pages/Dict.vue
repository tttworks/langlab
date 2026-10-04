<script setup>
import { ref, computed, onMounted, watch, inject } from 'vue'

const api = inject('api')
const state = inject('state')
const say = inject('say')

const rows = ref([])
const stats = ref({ total: 0, terms: 0, occurrences: 0 })
const loading = ref(true)
const scope = ref('general')   // general | professional
const q = ref('')
const sort = ref('freq')
const LIMIT = 300

const LANG = computed(() => (state.lang === 'all' ? 'en-US' : state.lang))

async function load() {
  loading.value = true
  try {
    const r = await api.dict({
      lang: LANG.value, scope: scope.value, q: q.value.trim(),
      sort: sort.value, limit: LIMIT, course: state.lang === 'all' ? '' : state.course,
    })
    rows.value = r.entries
    stats.value = r.stats
  } catch (e) { say(e.message) }
  loading.value = false
}
let qt = null
watch(q, () => { clearTimeout(qt); qt = setTimeout(load, 250) })
watch([scope, sort, LANG], load)
onMounted(load)

const POS_ZH = {
  n: '名词', v: '动词', adj: '形容词', adv: '副词', prep: '介词', conj: '连词',
  名詞: '名词', 動詞: '动词', 形容詞: '形容词', 副詞: '副词', 助詞: '助词', 助動詞: '助动词',
}
</script>

<template>
  <div class="card" style="padding:14px 16px">
    <div class="row">
      <button class="btn" :class="{ on: scope === 'general' }" @click="scope = 'general'">通用字典</button>
      <button class="btn" :class="{ on: scope === 'professional' }" @click="scope = 'professional'">专业 / 术语字典</button>
      <span class="small muted">
        同一份词库的两个视图 —— 通用看全部，专业只看术语或按当前课程切分
      </span>
    </div>
    <div class="row mt">
      <input class="input" style="max-width:300px;flex:1" v-model="q" placeholder="查词条：原形 / 词形 / 释义 / 读音" />
      <button class="btn" :class="{ on: sort === 'freq' }" @click="sort = 'freq'">按词频</button>
      <button class="btn" :class="{ on: sort === 'alpha' }" @click="sort = 'alpha'">按字母</button>
      <div class="sp" style="flex:1"></div>
      <span class="small muted">词条 {{ stats.total }} · 术语 {{ stats.terms }} · 出现记录 {{ stats.occurrences }}</span>
    </div>
  </div>

  <div v-if="loading" class="loading">加载中…</div>

  <div v-else-if="!rows.length" class="empty" style="margin-top:14px">
    <b>字典还是空的</b>
    字典不是手工录入的，它是「所有已导入素材」的派生物：素材进入 <span class="mono">segments</span> 后，
    由分词引擎（英语：分词 + 词形还原；日语：Sudachi 形态素解析）生成词条与出现位置索引。<br>
    先在「素材库」导入一段正文，词条就会开始生长。
  </div>

  <div v-else class="card" style="margin-top:14px">
    <table class="tb">
      <thead>
        <tr>
          <th style="width:34px" class="n">#</th>
          <th>词条</th>
          <th>读音 / 音标</th>
          <th>词性</th>
          <th>释义</th>
          <th class="n">词频</th>
          <th class="n">出现</th>
          <th>来源</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="(e, i) in rows" :key="e.id">
          <td class="n muted">{{ i + 1 }}</td>
          <td>
            <b style="font-size:14.5px">{{ e.display || e.lemma }}</b>
            <span class="small muted" v-if="e.display && e.display !== e.lemma"> ({{ e.lemma }})</span>
          </td>
          <td class="mono small">{{ e.reading || '—' }}</td>
          <td><span class="tag">{{ POS_ZH[e.pos] || e.pos || '—' }}</span></td>
          <td>{{ e.gloss_zh || e.gloss_en || '—' }}</td>
          <td class="n">{{ e.freq }}</td>
          <td class="n">{{ e.occurrences }}</td>
          <td>
            <span class="tag" :class="e.is_term ? 'v' : ''">{{ e.is_term ? '术语' : '通用' }}</span>
            <span class="tag" :class="e.source === 'auto' ? '' : 'c'">{{ e.source }}</span>
          </td>
        </tr>
      </tbody>
    </table>
    <p class="small muted" style="margin:10px 0 0">
      最多显示 {{ LIMIT }} 条。释义写入 <span class="mono">dict_entries.gloss_zh</span>；
      「出现」列来自 <span class="mono">dict_occurrences</span>，可回溯到具体素材与段落。
    </p>
  </div>
</template>
