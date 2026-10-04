<script setup>
import { ref, computed, watch, inject } from 'vue'

const api = inject('api')
const state = inject('state')
const say = inject('say')

const d = ref(null)
const loading = ref(true)

async function load() {
  loading.value = true
  try { d.value = await api.dashboard(state.lang) } catch (e) { say(e.message) }
  loading.value = false
}
watch(() => state.lang, load)
load()

/* 热力图：把 182 天按周（周日起）分列 */
const weeks = computed(() => {
  if (!d.value) return []
  const days = d.value.heatmap.days
  const max = d.value.heatmap.max || 1
  const cols = []
  let cur = null
  days.forEach((x) => {
    const wd = new Date(x.day + 'T00:00:00').getDay()
    if (!cur || wd === 0) { cur = []; cols.push(cur) }
    const mins = x.minutes
    let lv = 0
    if (mins > 0) {
      const r = mins / max
      lv = r > 0.66 ? 4 : r > 0.33 ? 3 : r > 0.1 ? 2 : 1
    }
    cur.push({ ...x, lv, future: x.day > new Date().toISOString().slice(0, 10) })
  })
  return cols
})

const WEEKDAYS = ['日', '一', '二', '三', '四', '五', '六']
const MISSING = ['new_word_density', 'recurrence_rate']
</script>

<template>
  <div v-if="loading" class="loading">加载中…</div>

  <template v-else-if="d">
    <div class="card">
      <h3>核心指标<span class="sub">{{ d.scope_lang === 'all' ? '全部语言' : d.scope_lang }}</span></h3>
      <div class="grid g4">
        <div class="kpi alt">
          <div class="k">卡片掌握率</div>
          <div class="v">{{ d.overall.mastery_rate }}<span class="u">%</span></div>
          <div class="s">{{ d.overall.cards_mastered }} / {{ d.overall.cards_total }} 张</div>
        </div>
        <div class="kpi">
          <div class="k">连续学习</div>
          <div class="v">{{ d.overall.streak }}<span class="u">天</span></div>
          <div class="s">累计活跃 {{ d.overall.active_days }} 天</div>
        </div>
        <div class="kpi">
          <div class="k">素材推进率</div>
          <div class="v">{{ d.overall.material_progress_rate }}<span class="u">%</span></div>
          <div class="s">{{ d.overall.materials }} 份素材 · {{ d.overall.material_segments }} 段</div>
        </div>
        <div class="kpi">
          <div class="k">累计学习时长</div>
          <div class="v">{{ Math.round(d.overall.minutes_total) }}<span class="u">分钟</span></div>
          <div class="s">≈ {{ (d.overall.minutes_total / 60).toFixed(1) }} 小时</div>
        </div>

        <div class="kpi">
          <div class="k">生词密度</div>
          <div class="v">{{ d.overall.new_word_density === null ? '—' : d.overall.new_word_density }}
            <span class="u" v-if="d.overall.new_word_density !== null">词/百词</span></div>
          <div class="s">
            <template v-if="d.overall.new_word_density === null">需先导入素材并生成字典</template>
            <template v-else-if="d.overall.new_word_density > 8">
              <span class="tag a">偏高</span> 这本书对你可能太难
            </template>
            <template v-else><span class="tag g">合适</span> 在可理解输入区间</template>
          </div>
        </div>
        <div class="kpi">
          <div class="k">复现率</div>
          <div class="v">{{ d.overall.recurrence_rate === null ? '—' : d.overall.recurrence_rate }}</div>
          <div class="s">每个词条的平均出现次数（输入量够不够）</div>
        </div>
        <div class="kpi">
          <div class="k">字典规模</div>
          <div class="v">{{ d.overall.dict_entries }}</div>
          <div class="s">词条（导入素材后自动生长）</div>
        </div>
        <div class="kpi">
          <div class="k">学习笔记</div>
          <div class="v">{{ d.overall.notes }}</div>
          <div class="s">条</div>
        </div>
      </div>
    </div>

    <!-- 分阶段 -->
    <div class="card">
      <h3>分阶段对比<span class="sub">看趋势，不只看总量</span></h3>
      <table class="tb">
        <thead>
          <tr>
            <th>阶段</th><th class="n">新增掌握</th><th class="n">掌握/天</th>
            <th class="n">活跃天数</th><th class="n">活跃度</th><th class="n">学习时长</th>
            <th class="n">卡片数</th><th class="n">活跃日均</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="s in d.stages" :key="s.key">
            <td>
              <b>{{ s.label }}</b>
              <div class="small muted" v-if="s.from">{{ s.from }} 起</div>
            </td>
            <td class="n">{{ s.mastered_added }}</td>
            <td class="n">{{ s.mastered_per_day }}</td>
            <td class="n">{{ s.active_days }}</td>
            <td class="n">
              <span class="tag" :class="s.intensity >= 60 ? 'g' : s.intensity >= 25 ? 'p' : ''">{{ s.intensity }}%</span>
            </td>
            <td class="n">{{ s.minutes }}</td>
            <td class="n">{{ s.items }}</td>
            <td class="n">{{ s.per_active_day }}</td>
          </tr>
        </tbody>
      </table>
      <p class="small muted" style="margin:10px 0 0">
        「活跃度」= 该阶段内有学习行为的天数占比；「掌握/天」按自然日摊平。
        学习时长与卡片数来自 <span class="mono">study_sessions</span>，卡片学习器与阅读器会自动埋点。
      </p>
    </div>

    <!-- 热力图 -->
    <div class="card">
      <h3>学习日历<span class="sub">近 182 天 · 每格一天，颜色深浅 = 当天学习时长</span></h3>
      <div class="row" style="align-items:flex-start;gap:10px">
        <div style="display:flex;flex-direction:column;gap:3px;padding-top:1px">
          <div v-for="w in WEEKDAYS" :key="w" class="small muted"
               style="height:11px;line-height:11px;font-size:10px">{{ w }}</div>
        </div>
        <div class="heat">
          <div v-for="(col, i) in weeks" :key="i" class="col">
            <div v-for="(c, j) in col" :key="j" class="cell" :class="[c.future ? 'future' : (c.lv ? 'l' + c.lv : '')]"
                 :title="c.day + ' · ' + c.minutes + ' 分钟 / ' + c.items + ' 卡片'"></div>
          </div>
        </div>
      </div>
      <div class="row" style="margin-top:10px;justify-content:space-between">
        <span class="small muted">{{ d.heatmap.start }} → 今天</span>
        <span class="heatlegend">
          少
          <i class="cell" style="width:11px;height:11px;border-radius:3px;display:inline-block;background:var(--chart-track)"></i>
          <i class="cell l1" style="width:11px;height:11px;border-radius:3px;display:inline-block"></i>
          <i class="cell l2" style="width:11px;height:11px;border-radius:3px;display:inline-block"></i>
          <i class="cell l3" style="width:11px;height:11px;border-radius:3px;display:inline-block"></i>
          <i class="cell l4" style="width:11px;height:11px;border-radius:3px;display:inline-block"></i>
          多
        </span>
      </div>
    </div>

    <!-- 课程 & 语言 -->
    <div class="grid g2">
      <div class="card">
        <h3>课程进度</h3>
        <div v-for="c in d.by_course" :key="c.id" style="margin-bottom:13px">
          <div class="row" style="justify-content:space-between">
            <span><b>{{ c.name }}</b> <span class="tag mono">{{ c.lang_code }}</span></span>
            <span class="small muted">{{ c.cards_mastered }}/{{ c.cards_total }} · {{ c.rate }}%</span>
          </div>
          <div class="bar" style="margin-top:5px"><i :style="{ width: c.rate + '%' }"></i></div>
        </div>
        <div v-if="!d.by_course.length" class="empty">没有课程</div>
      </div>

      <div class="card">
        <h3>语言概览</h3>
        <table class="tb">
          <thead><tr><th>语言</th><th class="n">课程</th><th class="n">素材</th><th class="n">卡片</th><th class="n">掌握率</th><th>进度</th></tr></thead>
          <tbody>
            <tr v-for="l in d.by_lang" :key="l.code">
              <td><b>{{ l.name_zh }}</b><div class="small muted mono">{{ l.code }}</div></td>
              <td class="n">{{ l.courses }}</td>
              <td class="n">{{ l.materials }}</td>
              <td class="n">{{ l.cards_total }}</td>
              <td class="n">{{ l.rate }}%</td>
              <td style="min-width:80px"><div class="bar"><i :style="{ width: l.rate + '%' }"></i></div></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </template>
</template>
