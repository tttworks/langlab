<script setup>
/**
 * 语音引擎设置面板 —— 阅读器和卡片页共用同一份设置（见 useTtsSettings）
 *
 * props:
 *   langCode   当前素材/课程的语言，如 en-US
 *   sampleText 试听用的句子（可选，默认按语言给一句）
 *   onTest     点了试听后由调用方去播（用各自的 useSpeech 实例）
 *   compact    紧凑模式（卡片页用）
 */
import { ref, computed, watch, inject, onMounted } from 'vue'
import { useTtsSettings } from '../useTtsSettings.js'

const props = defineProps({
  langCode: { type: String, default: 'en-US' },
  sampleText: { type: String, default: '' },
  onTest: { type: Function, default: null },
  compact: { type: Boolean, default: false },
})

const api = inject('api')
const tts = useTtsSettings()
const { conf, providers, loadProviders, curProvider, curProviderInfo, voiceScope, setScope,
  fellBack, fellBackReason, ensureVoices, entryFor, voiceFor, setVoice, voiceInfo,
  shownGroups, hiddenGroupCount, langShort, pickDefaultVoice } = tts

const open = ref(false)
const testMsg = ref('')

onMounted(() => { loadProviders() })
watch(curProvider, () => { ensureVoices(props.langCode) })
watch(() => props.langCode, () => { ensureVoices(props.langCode) })
ensureVoices(props.langCode)

const entry = computed(() => entryFor(props.langCode))
const groups = computed(() => shownGroups(props.langCode))
const hidden = computed(() => hiddenGroupCount(props.langCode))
const info = computed(() => voiceInfo(props.langCode))
const isBrowser = computed(() => curProvider.value === 'browser')

/** 这是英语素材吗（用于判断「该不该是美式」） */
const isEnglish = computed(() => String(props.langCode).toLowerCase().startsWith('en'))
/** 当前选中的音色不是美式 → 要给用户看得见的提醒 */
const accentWarn = computed(() => isEnglish.value && !!info.value
  && !String(info.value.accent || '').includes('美式'))

/** 一键切回美式标准发音（General American） */
function useAmerican() {
  const v = pickDefaultVoice(props.langCode, entry.value.voices)
  if (v) { setVoice(props.langCode, v); testMsg.value = '' }
}

async function test() {
  if (isBrowser.value) {
    testMsg.value = '浏览器内置引擎由系统音色决定，直接点朗读即可'
    props.onTest && props.onTest(defaultSample())
    return
  }
  const voice = voiceFor(props.langCode) || (entry.value.voices[0] || {}).id || ''
  if (!voice) { testMsg.value = '还没取到音色，稍后再试'; return }
  testMsg.value = '合成中…'
  try {
    const r = await api.ttsTest({ provider: curProvider.value, voice, lang: props.langCode })
    testMsg.value = `✓ ${r.voice} · ${(r.bytes / 1024).toFixed(0)} KB${r.cached ? '（缓存）' : ' · ' + r.ms + 'ms'}`
    props.onTest && props.onTest(defaultSample())
  } catch (e) {
    testMsg.value = '✗ ' + e.message + (e.hint ? '（' + e.hint + '）' : '')
  }
}

function defaultSample() {
  if (props.sampleText) return props.sampleText
  return String(props.langCode).startsWith('ja') ? 'これは音声のテストです。' : 'This is a voice test.'
}
</script>

<template>
  <button class="btn sm" :class="{ on: open }" title="语音引擎与音色" @click="open = !open">
    ⚙ 语音<template v-if="!compact && info"> · {{ info.name }}</template>
  </button>

  <div v-if="open" class="ttsset" :class="{ compact }">
    <div class="row" style="justify-content:space-between">
      <b>语音引擎</b>
      <span class="small muted">{{ langCode }}</span>
    </div>

    <div class="row" style="margin-top:8px">
      <button v-for="p in providers" :key="p.id" class="chip"
              :class="{ on: conf.provider === p.id, off: !p.configured }"
              :title="p.note + (p.missing && p.missing.length ? '（缺 ' + p.missing.join(' / ') + '）' : '')"
              @click="setProvider(p.id)">
        {{ p.label }}
        <span v-if="!p.configured" class="n">未配置</span>
      </button>
      <span v-if="!providers.length" class="small muted">
        {{ providersErr ? '取引擎清单失败：' + providersErr : '读取引擎清单…' }}
      </span>
    </div>

    <div class="small muted" style="margin-top:8px" v-if="curProviderInfo">{{ curProviderInfo.note }}</div>

    <!-- 音色 -->
    <div class="row" style="margin-top:9px" v-if="isBrowser">
      <span class="small muted">浏览器内置引擎用系统音色（由系统设置里的语音包决定）</span>
    </div>
    <template v-else>
      <div class="row" style="margin-top:9px">
        <select v-if="entry.voices.length" class="select" style="width:auto;max-width:280px;padding:4px 8px"
                :value="voiceFor(langCode)" @change="setVoice(langCode, $event.target.value)">
          <optgroup v-for="g in groups" :key="g.locale" :label="g.label">
            <option v-for="v in g.voices" :key="v.id" :value="v.id">
              {{ v.name }}（{{ v.gender === 'Female' ? '女' : '男' }} · {{ v.accent }}）
            </option>
          </optgroup>
        </select>
        <span v-else-if="entry.loading" class="small muted">取音色…</span>
        <span v-else class="small muted">没取到音色{{ entry.error ? '：' + entry.error : '' }}</span>

        <div class="sp" style="flex:1"></div>
        <button class="btn sm" :class="{ on: voiceScope === 'core' }" @click="setScope('core')">本口音</button>
        <button class="btn sm" :class="{ on: voiceScope === 'all' }" @click="setScope('all')">
          全部口音<template v-if="hidden > 0 && voiceScope === 'core'">（还有 {{ hidden }} 组）</template>
        </button>
      </div>

      <div class="row" style="margin-top:7px" v-if="info">
        <span class="tag p">{{ info.accent }}</span>
        <span class="small">
          {{ info.name }} {{ info.gender === 'Female' ? '女声' : '男声' }}
          <template v-if="info.multilingual"> · 多语言音色</template>
        </span>
      </div>

      <!-- 英语素材却选了非美式音色 → 明确提醒，并给一键修正 -->
      <div class="row" v-if="accentWarn" style="margin-top:8px;align-items:flex-start">
        <span class="small" style="color:var(--amber);line-height:1.7">
          ⚠ 当前音色是「{{ info.accent }}」，不是美式。英语素材建议用<b>美式标准发音</b>（General American）。
        </span>
        <div class="sp" style="flex:1"></div>
        <button class="btn sm pri" @click="useAmerican">切到美式标准发音</button>
      </div>
    </template>

    <div class="row" style="margin-top:9px">
      <button class="btn pri sm" @click="test">试听</button>
      <span class="small" :class="{ muted: !testMsg }">{{ testMsg || '选好引擎与音色后点试听' }}</span>
    </div>

    <div class="small muted" style="margin-top:8px">
      在线引擎会把朗读的文字发到语音服务商合成（结果缓存在本机，同一段第二次不再联网）。
      不想联网就切「浏览器内置」。<b>这里的设置对阅读器和卡片页同时生效。</b>
    </div>

    <div class="small" v-if="fellBack" style="margin-top:6px;color:var(--amber)">
      之前在线语音失败过，已临时切回浏览器内置<template v-if="fellBackReason">：{{ fellBackReason }}</template>。
    </div>
  </div>
</template>
