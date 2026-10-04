<script setup>
import { ref, computed, onMounted, inject } from 'vue'

const api = inject('api')
const state = inject('state')
const say = inject('say')
const props = defineProps({ go: Function })

const d = ref(null)
const loading = ref(true)

const KIND = { contract: '合同语料', textbook: '教材', reader: '读物', article: '文章', custom: '自建' }

const list = computed(() => {
  if (!d.value) return []
  return d.value.by_course.filter((c) => state.lang === 'all' || c.lang_code === state.lang)
})

onMounted(async () => {
  try { d.value = await api.dashboard(state.lang) } catch (e) { say(e.message) }
  loading.value = false
})
</script>

<template>
  <div v-if="loading" class="loading">加载中…</div>
  <template v-else>
    <div class="empty" v-if="!list.length">
      <b>这门语言还没有课程</b>
      语言表已预留，添加课程后在 <span class="mono">courses</span> 表里登记即可。
    </div>

    <div class="grid g2">
      <div v-for="c in list" :key="c.id" class="card">
        <div class="row" style="justify-content:space-between;align-items:flex-start">
          <div>
            <h3 style="margin-bottom:4px">{{ c.name }}</h3>
            <div class="row">
              <span class="tag p">{{ KIND[c.kind] || c.kind }}</span>
              <span class="tag mono">{{ c.lang_code }}</span>
            </div>
          </div>
          <div style="text-align:right">
            <div class="small muted">掌握率</div>
            <div style="font-size:22px;font-weight:700;letter-spacing:-.5px">{{ c.rate }}%</div>
          </div>
        </div>

        <p class="small muted" style="margin:10px 0 12px">
          {{ (state.courses.find(x => x.id === c.id) || {}).description }}
        </p>

        <div class="bar"><i :style="{ width: c.rate + '%' }"></i></div>

        <div class="row" style="gap:18px;margin-top:12px">
          <div><div class="small muted">卡片</div><b>{{ c.cards_mastered }} / {{ c.cards_total }}</b></div>
          <div><div class="small muted">素材</div><b>{{ c.materials }}</b></div>
        </div>

        <div class="row mt">
          <button class="btn pri" @click="state.course = c.id; props.go('cards')">卡片学习</button>
          <button class="btn" @click="state.course = c.id; props.go('materials')">素材与阅读</button>
        </div>
      </div>
    </div>
  </template>
</template>
