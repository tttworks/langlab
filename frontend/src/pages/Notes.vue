<script setup>
import { ref, reactive, onMounted, watch, inject } from 'vue'

const api = inject('api')
const state = inject('state')
const say = inject('say')

const rows = ref([])
const loading = ref(true)
const showNew = ref(false)
const form = reactive({ title: '', content: '' })

async function load() {
  loading.value = true
  try {
    const r = await api.notes(state.lang)
    rows.value = r.notes
  } catch (e) { say(e.message) }
  loading.value = false
}

async function save() {
  if (!form.content.trim()) return say('内容不能为空')
  try {
    await api.addNote({
      lang_code: state.lang === 'all' ? 'en-US' : state.lang,
      course_id: state.course || null,
      title: form.title, content: form.content,
    })
    say('已保存')
    form.title = ''; form.content = ''; showNew.value = false
    await load()
  } catch (e) { say(e.message) }
}

onMounted(load)
watch(() => state.lang, load)
</script>

<template>
  <div class="card" style="padding:14px 16px">
    <div class="row">
      <span class="small muted">
        这里的笔记与「阅读器 → 划词 → 写笔记」是同一条数据（<span class="mono">notes</span> 表），
        所以你在书里写的每一条都会自动出现在这儿。
      </span>
      <div class="sp" style="flex:1"></div>
      <button class="btn pri" @click="showNew = !showNew">{{ showNew ? '收起' : '+ 记一条' }}</button>
    </div>
  </div>

  <div class="card" v-if="showNew">
    <h3>新建学习笔记</h3>
    <input class="input" v-model="form.title" placeholder="标题（可选）" />
    <textarea class="textarea mt" v-model="form.content"
              placeholder="这条笔记想记什么？例如：为什么这个词在这里用 shall 不用 will。"></textarea>
    <div class="row mt"><button class="btn pri" @click="save">保存</button></div>
  </div>

  <div v-if="loading" class="loading">加载中…</div>
  <div v-else-if="!rows.length" class="empty" style="margin-top:14px">
    <b>还没有笔记</b>去「素材库 → 阅读」里选中一段文字，写下第一条；也可以点右上「+ 记一条」手录。
  </div>

  <div v-else class="grid g2" style="margin-top:14px">
    <div v-for="n in rows" :key="n.id" class="card">
      <div class="row" style="justify-content:space-between">
        <b v-if="n.title">{{ n.title }}</b>
        <span v-else class="muted small">（无标题）</span>
        <span class="small muted">{{ n.day }}</span>
      </div>
      <p style="margin:8px 0 0;white-space:pre-wrap">{{ n.content }}</p>
      <div class="row" style="margin-top:9px">
        <span class="tag mono">{{ n.lang_code }}</span>
        <span class="tag" v-if="n.material_id">素材 #{{ n.material_id }}</span>
        <span class="tag c" v-if="n.annotation_id">来自批注</span>
      </div>
    </div>
  </div>
</template>
