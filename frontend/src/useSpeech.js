/**
 * 语音阅读：双引擎
 *
 *   ① browser —— 浏览器内置 speechSynthesis：离线、零配置，但系统音色质量一般（日语尤其差）
 *   ② server  —— 走 /api/tts 网关（Edge 在线 / OpenAI / Azure）：音色好，需联网；服务端带缓存
 *
 * 共用一套状态机：currentId / currentIndex / chunkIndex / chunkTotal / playing / paused。
 * 两个引擎的差异都收在 engineSpeak / enginePause / engineResume / engineStop 里。
 *
 * 设计要点（都是踩过坑才这么写的）：
 *  1. 浏览器引擎**不用原生 pause()**：Chrome 里暂停约 15 秒后会失效且恢复不了 → cancel + 记住读到第几块。
 *     服务端引擎有真正的音频元素，pause/resume 可以精确到句内，就用原生的。
 *  2. 长段落按句切块：浏览器引擎单条 utterance 过长会被截断；服务端引擎也顺带拿到了「第 i/j 句」的进度。
 *     切块时**必须记录每块在整段里的字符区间**，否则跟读横线画不到正确位置。
 *  3. 用递增 token 防串台：cancel / 换源会触发旧回调，旧回调必须认得出自己已过期。
 *  4. 服务端引擎要**预取**下一块：不预取的话每句之间会卡 1~2 秒（一次 HTTP + 合成）。
 *  5. 连读补渲染的触发点按「已渲染边界」判定，不能取整份素材的末尾 —— 大书上会退化成没有高亮可看。
 *  6. 「合成中」要**延迟显示**：缓存命中是几十毫秒的事，立刻闪一下反而像每次都在重算。
 */
import { ref, reactive, computed, onBeforeUnmount } from 'vue'

const LS_VOICE = 'langlab.voice'
const LS_RATE = 'langlab.rate'
const MAX_BLOB = 60          // 音频 blob 缓存条数上限
const LOADING_DELAY = 260    // 超过这么久还没好，才显示「合成中」

export function useSpeech(options = {}) {
  // 注意：这里必须是 ref。写成普通布尔的话，调用方 `supported.value` 恒为 undefined，
  // 会把「支持语音」误判成不支持、把按钮全禁用掉（踩过）。
  const supported = ref(typeof window !== 'undefined'
    && ('speechSynthesis' in window || typeof Audio !== 'undefined'))
  const browserOK = ref(typeof window !== 'undefined' && 'speechSynthesis' in window
    && typeof window.SpeechSynthesisUtterance !== 'undefined')

  const getLang = () => (typeof options.lang === 'function' ? options.lang() : options.lang) || 'en-US'
  const getProvider = () => (typeof options.provider === 'function' ? options.provider() : options.provider) || 'browser'
  const getVoice = () => (typeof options.voice === 'function' ? options.voice() : options.voice) || ''
  const onNeedMore = typeof options.onNeedMore === 'function' ? options.onNeedMore : null
  const needMoreAt = typeof options.needMoreAt === 'function' ? options.needMoreAt : null


  const voices = ref([])
  const voiceURI = ref('')
  const rate = ref(1)
  const currentId = ref(null)
  const currentIndex = ref(-1)
  const chunkIndex = ref(0)
  const chunkTotal = ref(0)
  const playing = ref(false)
  const paused = ref(false)
  const error = ref('')
  const hint = ref('')
  const loadingAudio = ref(false)

  /**
   * 跟读进度（字符区间，相对**整段文本**，供前端画横线）
   *   charFrom 恒为当前句子起点；charTo 随播放推进
   *   wordFrom / wordTo 是当前正在读的那个词
   */
  const charFrom = ref(-1)
  const charTo = ref(-1)
  const curWord = reactive({ from: -1, to: -1 })

  let activeList = []
  let continuous = true
  let token = 0
  let pausedChunk = 0
  let pausedChar = -1          // 服务端引擎暂停时记住的播放位置（秒）

  const langKey = computed(() => String(getLang()).slice(0, 2).toLowerCase())
  const isServer = computed(() => getProvider() !== 'browser')
  const currentVoice = computed(() => voices.value.find((v) => v.voiceURI === voiceURI.value) || null)

  const norm = (l) => String(l || '').toLowerCase().replace('_', '-')

  try {
    voiceURI.value = localStorage.getItem(LS_VOICE) || ''
    const r = Number(localStorage.getItem(LS_RATE) || 1)
    if (r >= 0.5 && r <= 1.6) rate.value = r
  } catch (e) {}

  /* ---------------- 浏览器音色 ---------------- */
  // 他要「美式纽约 / 美式标准发音」：优先精确匹配，退而求其次时也要避开英式/澳式等口音
  const US_HINT = ['david', 'zira', 'aria', 'jenny', 'guy', 'ava', 'emma', 'andrew', 'brian',
    'mark', 'michelle', 'eric', 'christopher', 'roger', 'steffan', 'ana', 'tony', 'nancy']
  const NON_US_EN = /^en-(gb|au|ie|nz|in|za|ng|ke|tz|ph|sg|hk)$/
  /** 在当前语言的候选里挑一个最合适的系统音色 */
  function pickBrowserVoice(want) {
    const list = voices.value || []
    if (!list.length) return null
    const w = norm(want)
    const exact = list.find((v) => norm(v.lang) === w)
    if (exact) return exact
    if (w.startsWith('en')) {
      const us = list.find((v) => norm(v.lang) === 'en-us')
      if (us) return us
      const named = list.find((v) => US_HINT.some((n) => String(v.name || '').toLowerCase().includes(n)))
      if (named) return named
      const notGbAu = list.find((v) => !NON_US_EN.test(norm(v.lang)))
      if (notGbAu) return notGbAu
    }
    return list[0]
  }
  function loadVoices() {
    if (!browserOK.value) return
    const all = window.speechSynthesis.getVoices() || []
    const k = langKey.value
    voices.value = all.filter((v) => norm(v.lang).startsWith(k))
    if (!voices.value.some((v) => v.voiceURI === voiceURI.value)) {
      setVoice((pickBrowserVoice(getLang()) || {}).voiceURI || '')
    }
  }
  function setVoice(uri) {
    voiceURI.value = uri || ''
    try { localStorage.setItem(LS_VOICE, voiceURI.value) } catch (e) {}
  }
  function setRate(r) {
    const v = Number(r)
    rate.value = Number.isFinite(v) ? v : 1
    try { localStorage.setItem(LS_RATE, String(rate.value)) } catch (e) {}
  }

  /* ---------------- 按句切块（带字符偏移） ---------------- */

  /** 切成句子，保留每句在原文里的 [start, end) */
  function sentences(s) {
    const out = []
    const re = /[^.!?。！？；;：:\n]*[.!?。！？；;：:]+[\s]*|[^.!?。！？；;：:\n]+$|[\s]+/gu
    let m
    while ((m = re.exec(s)) !== null) {
      if (!m[0]) { re.lastIndex++; continue }
      out.push({ text: m[0], start: m.index, end: m.index + m[0].length })
      if (re.lastIndex <= m.index) re.lastIndex = m.index + 1
    }
    return out
  }

  /** 把句子打包成 ≤max 的块；每块记住 [start, end) —— 跟读横线靠它定位 */
  function chunk(text, max = 180) {
    const s = String(text == null ? '' : text)
    if (!s.trim()) return []
    const sents = sentences(s)
    if (!sents.length) return [{ text: s, start: 0, end: s.length }]
    const out = []
    let cur = null
    for (const sn of sents) {
      if (!sn.text.trim()) {
        if (cur) cur.end = sn.end
        continue
      }
      if (cur && (sn.end - cur.start) > max) {
        out.push({ text: s.slice(cur.start, cur.end), start: cur.start, end: cur.end })
        cur = null
      }
      if (!cur) cur = { start: sn.start, end: sn.end }
      else cur.end = sn.end
    }
    if (cur) out.push({ text: s.slice(cur.start, cur.end), start: cur.start, end: cur.end })
    return out
  }

  /* ---------------- 服务端引擎：音频 / 时间戳 缓存 + 预取 ---------------- */
  const blobCache = new Map()      // key -> { promise, url, t }
  const timingCache = new Map()    // key -> { promise, words, t }
  let audio = null
  let loadTimer = null

  function markLoading(on) {
    clearTimeout(loadTimer)
    if (!on) { loadingAudio.value = false; return }
    // 延迟一会儿再显示：缓存命中几十毫秒就回来了，不该闪「合成中」
    loadTimer = setTimeout(() => { loadingAudio.value = true }, LOADING_DELAY)
  }

  function evict(cache, max) {
    if (cache.size <= max) return
    const arr = [...cache.entries()].sort((a, b) => a[1].t - b[1].t)
    arr.slice(0, arr.length - max).forEach(([k, v]) => {
      cache.delete(k)
      if (v.url) { try { URL.revokeObjectURL(v.url) } catch (e) {} }
    })
  }

  function cacheKey(text) {
    // 语速走 playbackRate，不进缓存键 —— 换语速不用重新合成，缓存命中率也更高
    return [getProvider(), getVoice(), getLang(), text].join('\u0001')
  }

  /** 取音频 URL；返回 Promise（缓存命中也是 Promise，两条路径类型必须一致） */
  function fetchAudioUrl(text) {
    const key = cacheKey(text)
    const hit = blobCache.get(key)
    if (hit) {
      hit.t = Date.now()
      return hit.url ? Promise.resolve(hit.url) : hit.promise
    }
    const p = (async () => {
      const res = await fetch('/api/tts', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ provider: getProvider(), voice: getVoice(), lang: getLang(), text, rate: '+0%' }),
      })
      if (!res.ok) {
        let msg = 'HTTP ' + res.status
        let h = ''
        try { const j = await res.json(); msg = j.error || msg; h = j.hint || '' } catch (e) {}
        const err = new Error(msg)
        err.hint = h
        throw err
      }
      const blob = await res.blob()
      const url = URL.createObjectURL(blob)
      const rec = blobCache.get(key) || {}
      rec.url = url
      rec.t = Date.now()
      delete rec.promise
      blobCache.set(key, rec)
      evict(blobCache, MAX_BLOB)
      return url
    })()
    blobCache.set(key, { promise: p, t: Date.now() })
    p.catch(() => { blobCache.delete(key) })
    return p
  }

  /** 取词级时间戳（只有 edge 有）；失败就返回空数组，前端退回按时间线性插值 */
  function fetchTimings(text) {
    if (getProvider() !== 'edge') return Promise.resolve([])
    const key = cacheKey(text)
    const hit = timingCache.get(key)
    if (hit) {
      hit.t = Date.now()
      return hit.promise || Promise.resolve(hit.words || [])
    }
    const p = fetch('/api/tts/timings', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ provider: getProvider(), voice: getVoice(), lang: getLang(), text, rate: '+0%' }),
    }).then((r) => r.json()).then((j) => (j && j.ok ? (j.words || []) : [])).catch(() => [])
    const rec = { promise: p, t: Date.now() }
    timingCache.set(key, rec)
    p.then((w) => { rec.words = w; delete rec.promise; evict(timingCache, MAX_BLOB) })
    return p
  }

  /** 预取后面几块，消除连读时句与句之间的停顿 */
  function warmAhead(i, k, chunks) {
    const items = []
    for (let c = k + 1; c < chunks.length && items.length < 2; c++) items.push(chunks[c].text)
    if (continuous && items.length < 2 && i + 1 < activeList.length) {
      const nxt = chunk(activeList[i + 1].text)
      if (nxt[0]) items.push(nxt[0].text)
    }
    items.forEach((t) => {
      try {
        // 同样串行：先音频后时间戳，避免两个请求同时触发合成
        fetchAudioUrl(t).then(() => fetchTimings(t)).catch(() => {})
      } catch (e) {}
    })
  }

  function ensureAudio() {
    if (!audio) {
      audio = new Audio()
      audio.preload = 'auto'
    }
    return audio
  }

  /* ---------------- 跟读进度：靠 rAF 从 currentTime 推 ---------------- */
  let rafId = 0
  let curChunk = null        // { text, start, end }
  let curWords = []          // 当前块的词级时间戳

  function stopProgress() {
    if (rafId) { cancelAnimationFrame(rafId); rafId = 0 }
    charFrom.value = -1
    charTo.value = -1
    curWord.from = -1
    curWord.to = -1
  }

  function beginProgress() {
    stopProgress()
    if (!curChunk) return
    charFrom.value = curChunk.start
    charTo.value = curChunk.start
    if (isServer.value) rafId = requestAnimationFrame(tickServer)
  }

  function tickServer() {
    rafId = requestAnimationFrame(tickServer)
    if (!audio || !curChunk) return
    const t = audio.currentTime
    const len = curChunk.end - curChunk.start
    if (curWords.length) {
      // 命中第 k 个词 → 在该词内部按时间比例插值，线走得才顺
      let k = -1
      for (let i = 0; i < curWords.length; i++) {
        const w = curWords[i]
        if (t >= w.t) k = i
        if (t >= w.t && t < w.t + w.d) break
      }
      if (k >= 0) {
        const w = curWords[k]
        const frac = w.d > 0 ? Math.min(1, Math.max(0, (t - w.t) / w.d)) : 1
        const mid = w.cs + (w.ce - w.cs) * frac
        curWord.from = curChunk.start + w.cs
        curWord.to = curChunk.start + w.ce
        charTo.value = curChunk.start + mid
        return
      }
    }
    // 没有词级时间戳：按音频总时长线性映射到字符数（够用，只是不够贴字）
    const dur = audio.duration
    if (dur && isFinite(dur) && dur > 0) {
      const r = Math.min(1, t / dur)
      curWord.from = -1
      curWord.to = -1
      charTo.value = curChunk.start + len * r
    }
  }

  /* ---------------- 引擎接口 ---------------- */
  function engineStop() {
    if (isServer.value) {
      if (audio) {
        try { audio.pause() } catch (e) {}
        audio.onended = null
        audio.onerror = null
        audio.removeAttribute('src')
      }
    }
    if (browserOK.value) {
      try { window.speechSynthesis.cancel() } catch (e) {}
    }
  }

  function engineSpeak(text, my, onEnd, onFail) {
    if (!isServer.value) {
      if (!browserOK.value) { onFail('这个浏览器不支持语音合成'); return }
      const u = new window.SpeechSynthesisUtterance(text)
      u.lang = getLang()
      if (currentVoice.value) u.voice = currentVoice.value
      u.rate = rate.value
      u.pitch = 1
      u.onend = () => { if (my === token) onEnd() }
      u.onerror = (e) => {
        const t = (e && (e.error || e.name)) || ''
        if (t === 'interrupted' || t === 'canceled') return
        if (my === token) onFail(t || 'speech-error')
      }
      // 系统语音大多会逐词回 boundary，能直接拿到「读到第几个字」
      u.onboundary = (e) => {
        if (my !== token || !curChunk) return
        const cs = typeof e.charIndex === 'number' ? e.charIndex : -1
        if (cs < 0) return
        const cl = typeof e.charLength === 'number' && e.charLength ? e.charLength : 1
        curWord.from = curChunk.start + cs
        curWord.to = curChunk.start + cs + cl
        charTo.value = curWord.to
      }
      try {
        window.speechSynthesis.speak(u)
        if (typeof window.speechSynthesis.resume === 'function') window.speechSynthesis.resume()
        beginProgress()          // 必须调：它负责把 charFrom 设成本句起点，不调的话线永远不出现
      } catch (err) {
        onFail(String(err.message || err))
      }
      return
    }

    // 服务端引擎：**先取音频、再取时间戳**。
    //   并行取会撞上「时间戳请求发现 json 还没生成」→ 自己又合成一遍（实测多花 6 秒）。
    //   串行时时间戳请求只是读一个已存在的文件，几毫秒。
    markLoading(true)
    const done = () => markLoading(false)
    fetchAudioUrl(text).then(async (url) => {
      if (my !== token) { done(); return }
      curWords = (await fetchTimings(text).catch(() => [])) || []
      if (my !== token) { done(); return }
      const a = ensureAudio()
      a.src = url
      a.playbackRate = Math.min(2, Math.max(0.5, rate.value))
      a.onended = () => { if (my === token) onEnd() }
      a.onerror = () => { if (my === token) onFail('音频播放失败') }
      const pr = a.play()
      if (pr && pr.catch) pr.catch((e) => { if (my === token) onFail(String(e.message || e)) })
      done()
      beginProgress()
    }).catch((e) => {
      done()
      if (my !== token) return
      onFail(String(e.message || e), e.hint || '')
    })
  }

  function enginePause() {
    if (isServer.value) {
      if (audio) {
        pausedChar = audio.currentTime          // 记住秒数，继续时精确续播
        try { audio.pause() } catch (e) {}
      }
    } else {
      token++
      if (browserOK.value) { try { window.speechSynthesis.cancel() } catch (e) {} }
    }
    if (rafId) { cancelAnimationFrame(rafId); rafId = 0 }
  }

  function engineResume() {
    if (!isServer.value) return false       // 浏览器引擎一律按「当前块重播」续
    if (audio && audio.src) {
      try { if (pausedChar > 0 && pausedChar < (audio.duration || Infinity)) audio.currentTime = pausedChar } catch (e) {}
      const my = token
      audio.onended = () => { if (my === token) advanceChunk() }
      audio.onerror = () => { if (my === token) fail('音频播放失败') }
      const pr = audio.play()
      if (pr && pr.catch) pr.catch((e) => fail(String(e.message || e)))
      if (!rafId) rafId = requestAnimationFrame(tickServer)
      return true
    }
    return false
  }

  /* ---------------- 状态机 ---------------- */
  let curI = 0
  let curChunkIdx = 0
  let tokenAtStep = 0

  function stop() {
    token++
    playing.value = false
    paused.value = false
    currentId.value = null
    currentIndex.value = -1
    chunkIndex.value = 0
    chunkTotal.value = 0
    pausedChunk = 0
    pausedChar = -1
    curChunk = null
    markLoading(false)
    stopProgress()
    engineStop()
  }

  function pause() {
    if (!playing.value) return
    pausedChunk = chunkIndex.value
    enginePause()
    playing.value = false
    paused.value = true
  }

  function resume() {
    if (!paused.value) return
    paused.value = false
    playing.value = true
    if (isServer.value && engineResume()) return   // 服务端：精确续播
    stepTo(curI, pausedChunk)                     // 否则：当前块重播
    pausedChunk = 0
  }

  function fail(msg, h) {
    error.value = String(msg || '语音失败')
    if (h) hint.value = String(h)
    token++
    playing.value = false
    paused.value = false
    markLoading(false)
    stopProgress()
    engineStop()
  }

  function advanceChunk() { step(curChunkIdx + 1) }

  function step(k) {
    if (tokenAtStep !== token) return     // 已经被取消/换段/换源，旧回调作废
    curChunkIdx = k
    chunkIndex.value = k
    if (k >= curChunks.length) {
      chunkIndex.value = 0
      if (continuous) stepTo(curI + 1, 0)
      else { playing.value = false; paused.value = false; stopProgress() }
      return
    }
    curChunk = curChunks[k]
    curWords = []
    if (isServer.value) warmAhead(curI, k, curChunks)
    engineSpeak(curChunks[k].text, tokenAtStep, advanceChunk, fail)
  }

  let curChunks = []

  function stepTo(i, startChunk = 0) {
    if (i < 0 || i >= activeList.length) { stop(); return }
    if (continuous && onNeedMore && needMoreAt && i >= needMoreAt()) onNeedMore()
    curI = i
    currentIndex.value = i
    currentId.value = activeList[i].id
    playing.value = true
    paused.value = false
    error.value = ''
    hint.value = ''
    pausedChar = -1
    tokenAtStep = ++token
    curChunks = chunk(activeList[i].text)
    chunkTotal.value = curChunks.length
    if (!curChunks.length) { stepTo(i + 1, 0); return }
    step(Math.max(0, Math.min(startChunk, curChunks.length - 1)))
  }

  function play(listInput, start = 0, loop = true) {
    activeList = (listInput || []).filter((x) => x && String(x.text || '').trim())
    continuous = loop
    if (!activeList.length) { stop(); return }
    stepTo(start)
  }

  function playOne(text, id) {
    play([{ id: id == null ? '__one__' : id, text }], 0, false)
  }

  function next() { stepTo(Math.min(activeList.length - 1, currentIndex.value + 1)) }
  function prev() { stepTo(Math.max(0, currentIndex.value - 1)) }

  function clearAudioCache() {
    blobCache.forEach((v) => { if (v.url) { try { URL.revokeObjectURL(v.url) } catch (e) {} } })
    blobCache.clear()
    timingCache.clear()
  }

  if (browserOK.value) {
    loadVoices()
    try { window.speechSynthesis.onvoiceschanged = loadVoices } catch (e) {}
    setTimeout(loadVoices, 400)
  }

  onBeforeUnmount(() => { stop(); clearAudioCache() })

  return {
    supported, browserOK, error, hint, loadingAudio, langKey, isServer,
    voices, voiceURI, currentVoice, setVoice,
    rate, setRate,
    currentId, currentIndex, chunkIndex, chunkTotal, playing, paused,
    charFrom, charTo, curWord,
    play, playOne, next, prev, pause, resume, stop,
    clearAudioCache,
  }
}
