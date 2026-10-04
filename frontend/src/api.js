const BASE = '/api'

async function req(method, path, body) {
  const opts = { method, headers: {} }
  if (body !== undefined) {
    opts.headers['Content-Type'] = 'application/json'
    opts.body = JSON.stringify(body)
  }
  const res = await fetch(BASE + path, opts)
  const txt = await res.text()
  let data
  try { data = JSON.parse(txt) } catch (e) { throw new Error('返回不是 JSON：' + txt.slice(0, 200)) }
  if (!res.ok || data.ok === false) throw new Error(data.error || ('HTTP ' + res.status))
  return data
}

export const api = {
  health: () => req('GET', '/health'),

  languages: () => req('GET', '/languages'),
  courses: (lang) => req('GET', '/courses' + (lang && lang !== 'all' ? '?lang=' + encodeURIComponent(lang) : '')),

  dashboard: (lang) => req('GET', '/dashboard?lang=' + encodeURIComponent(lang || 'all')),

  cardSets: (course) => req('GET', '/card-sets' + (course ? '?course=' + encodeURIComponent(course) : '')),
  cards: (p = {}) => {
    const q = new URLSearchParams()
    Object.entries(p).forEach(([k, v]) => { if (v !== undefined && v !== null && v !== '') q.set(k, v) })
    return req('GET', '/cards?' + q.toString())
  },
  setProgress: (items) => req('POST', '/cards/progress', { items }),
  // 就地建卡：卡片页点词发现没卡时，直接建一张（释义空则让后端用 AI 补）
  quickCard: (data) => req('POST', '/cards/quick', data),
  // 删自己建的卡（后端只允许 my-* 卷）
  delCard: (id) => req('DELETE', '/cards/' + id),
  // SM-2 复习调度
  cardsDue: (course) => req('GET', '/cards/due' + (course ? '?course=' + encodeURIComponent(course) : '')),
  review: (id, grade) => req('POST', `/cards/${id}/review`, { grade }),

  materials: (p = {}) => {
    const q = new URLSearchParams()
    Object.entries(p).forEach(([k, v]) => { if (v) q.set(k, v) })
    return req('GET', '/materials' + (q.toString() ? '?' + q.toString() : ''))
  },
  material: (id) => req('GET', '/materials/' + id),
  importMaterial: (data) => req('POST', '/materials', data),
  linkMaterial: (data) => req('POST', '/materials/link', data),
  indexMaterial: (id) => req('POST', `/materials/${id}/index`),
  delMaterial: (id) => req('DELETE', '/materials/' + id),
  setReadProgress: (id, data) => req('POST', `/materials/${id}/progress`, typeof data === 'number' ? { read_seq: data } : data),

  // 文件上传：multipart，不能用 JSON 封装
  async uploadMaterial(file, fields = {}) {
    const fd = new FormData()
    fd.append('file', file)
    Object.entries(fields).forEach(([k, v]) => { if (v) fd.append(k, v) })
    const res = await fetch('/api/materials/upload', { method: 'POST', body: fd })
    const txt = await res.text()
    let data
    try { data = JSON.parse(txt) } catch (e) { throw new Error('返回不是 JSON：' + txt.slice(0, 200)) }
    if (!res.ok || data.ok === false) throw new Error(data.error || ('HTTP ' + res.status))
    return data
  },

  annotations: (materialId) => req('GET', '/annotations?material_id=' + materialId),
  addAnnotation: (data) => req('POST', '/annotations', data),
  delAnnotation: (id) => req('DELETE', '/annotations/' + id),

  dict: (p = {}) => {
    const q = new URLSearchParams()
    Object.entries(p).forEach(([k, v]) => { if (v !== undefined && v !== null && v !== '') q.set(k, v) })
    return req('GET', '/dict?' + q.toString())
  },

  notes: (lang) => req('GET', '/notes' + (lang && lang !== 'all' ? '?lang=' + encodeURIComponent(lang) : '')),
  addNote: (data) => req('POST', '/notes', data),

  // 语音合成
  ttsProviders: () => req('GET', '/tts/providers'),
  // locale = 当前素材的语言（如 en-US），后端据此标注「同口音」并把对应分组排前面
  ttsVoices: (provider, lang, locale) => req('GET',
    `/tts/voices?provider=${encodeURIComponent(provider)}&lang=${encodeURIComponent(lang || '')}` +
    `&locale=${encodeURIComponent(locale || '')}`),
  ttsTest: (data) => req('POST', '/tts/test', data),

  addSession: (data) => req('POST', '/sessions', data),

  // 虚拟老师：自由对话 + 实时纠错（后端代理 DeepSeek，可调用本系统学习数据）
  teacherStatus: () => req('GET', '/teacher/status'),
  teacherChat: (data) => req('POST', '/teacher/chat', data),

  /**
   * 虚拟老师：发音诊断（multipart 上传音频）
   * 走旁路 —— 录音一停就上传，与对话请求并行，不阻塞老师回话。
   * @param {Blob} audio 录音（webm/opus）
   * @param {string} lang 'en' | 'ja' | 'zh' | 'th'
   * @param {string} ref 可选：参考文本（有则能判漏读/多读）
   */
  async teacherAssess(audio, lang = 'en', ref = '') {
    const fd = new FormData()
    fd.append('audio', audio, 'rec.' + (audio.type.includes('ogg') ? 'ogg' : 'webm'))
    fd.append('lang', lang)
    if (ref) fd.append('ref', ref)
    const res = await fetch('/api/teacher/assess', { method: 'POST', body: fd })
    const txt = await res.text()
    let data
    try { data = JSON.parse(txt) } catch (e) { throw new Error('返回不是 JSON：' + txt.slice(0, 200)) }
    if (!res.ok || data.ok === false) throw new Error(data.error || ('HTTP ' + res.status))
    return data
  },

  // 虚拟老师：发音点评（把诊断挑出的词变成可执行的纠正建议；无状态、不落库）
  teacherPronounce: (data) => req('POST', '/teacher/pronounce', data),

  // 虚拟老师：多会话历史
  teacherSessions: () => req('GET', '/teacher/sessions'),
  teacherNewSession: (data) => req('POST', '/teacher/sessions', data),
  teacherSession: (id) => req('GET', '/teacher/sessions/' + id),
  teacherRenameSession: (id, title) => req('PATCH', '/teacher/sessions/' + id, { title }),
  teacherDelSession: (id) => req('DELETE', '/teacher/sessions/' + id),
}
