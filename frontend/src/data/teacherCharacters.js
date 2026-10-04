/**
 * 虚拟老师角色库 —— 想加/改角色，只动这个文件。
 *
 * ⭐ 两种形象类型（`kind`）：
 *
 *  1) kind: 'svg'（默认，不写就是它）
 *     矢量形象，共用一套 SVG 骨架（见 components/TeacherAvatar.vue），
 *     靠「配色 + 发型 + 配饰」区分。**零素材、零版权风险、随仓库走**，
 *     所以开源版默认全用它，clone 下来就能跑。
 *
 *  2) kind: 'image'
 *     图片形象（写实风 / 插画 / 任意来源）。一张 idle 就能用，
 *     缺哪个状态自动回退到 idle；状态靠光效与呼吸动效表达
 *     （写实图没法做逐帧口型，这是取舍）。
 *     图片放 frontend/public/avatars/<id>/ 下，详见 public/avatars/README.md。
 *
 * 字段速查
 *   id/name/role/tag  必填
 *   kind              'svg'（默认）| 'image'
 *   lang              这个角色**固定**的目标语言。点角色就自动切过去，不用再去顶栏单独选
 *   tts               这个角色**固定**的 { 引擎, 音色 }；界面里改过会存 localStorage，优先级更高
 *                     （音色 id 要跟引擎对得上：edge 用 en-US-AriaNeural，minimax 用 English_radiant_girl）
 *   —— svg 专用 ——
 *   palette           hair 主发色 / hairHi 高光 / skin 肤色 / eye 瞳色 / cloth 衣服 / cloth2 暗部 / accent 配饰 / blush 腮红
 *   hair              long 长发 / bob 波波头 / tail 马尾
 *   acc               earring 耳环 / clip 发夹 / glasses 眼镜 / none
 *   —— image 专用 ——
 *   images            { idle, listening?, thinking?, speaking? }，路径以 / 开头（对应 public 目录）
 *   tint              状态光效主色（不填则取 palette.accent）
 *
 * ⚠️ zh-CN 不是「教中文」，是「中文思维伙伴」——陪你想问题、给角度、追着往下想。
 */
/** 形象状态机 —— 加新状态时，TeacherAvatar.vue 里要补对应样式 */
export const AVATAR_STATES = ['idle', 'listening', 'thinking', 'speaking']

export const CHARACTERS = [
  {
    id: 'aria',
    name: 'Aria',
    role: '英语口语老师',
    tag: '温柔 · 鼓励型 · 纠错细',
    lang: 'en-US',
    tts: { provider: 'edge', voice: 'en-US-AriaNeural' },
    hair: 'long',
    acc: 'earring',
    palette: {
      hair: '#8b5e3c', hairHi: '#c98b5e',
      skin: '#ffe1c9', eye: '#4b3a2f',
      cloth: '#e0e7ff', cloth2: '#c7d2fe',
      accent: '#f59e0b', blush: 'rgba(244,114,182,.42)',
    },
  },
  {
    id: 'yuki',
    name: 'Yuki',
    role: '日语会话老师',
    tag: '清爽 · 敬语与简体分得清',
    lang: 'ja-JP',
    tts: { provider: 'edge', voice: 'ja-JP-NanamiNeural' },
    hair: 'bob',
    acc: 'clip',
    palette: {
      hair: '#2f2a28', hairHi: '#5b524c',
      skin: '#ffe6d5', eye: '#3b2f28',
      cloth: '#fce7f3', cloth2: '#fbcfe8',
      accent: '#ec4899', blush: 'rgba(244,114,182,.45)',
    },
  },
  {
    id: 'noah',
    name: 'Noah',
    role: '商务英语教练',
    tag: '干练 · 合同/谈判场景',
    lang: 'en-US',
    tts: { provider: 'edge', voice: 'en-US-JennyNeural' },
    hair: 'tail',
    acc: 'glasses',
    palette: {
      hair: '#5b3a29', hairHi: '#a8703f',
      skin: '#ffdfc0', eye: '#334155',
      cloth: '#dbeafe', cloth2: '#93c5fd',
      accent: '#2563eb', blush: 'rgba(244,114,182,.35)',
    },
  },
  {
    id: 'mei',
    name: '玫 Mei',
    role: '中文思维伙伴',
    tag: '不教语言 · 接住想法 · 往下追',
    lang: 'zh-CN',
    tts: { provider: 'edge', voice: 'zh-CN-XiaoxiaoNeural' },
    hair: 'tail',
    acc: 'clip',
    palette: {
      hair: '#241f1d', hairHi: '#6b5a52',
      skin: '#ffe4cf', eye: '#3a2e28',
      cloth: '#fee2e2', cloth2: '#fca5a5',
      accent: '#b91c1c', blush: 'rgba(244,114,182,.40)',
    },
  },
  {
    id: 'mali',
    name: 'Mali',
    role: '泰语会话老师',
    tag: '茉莉 · 生活泰语 · 声调不放过',
    lang: 'th-TH',
    tts: { provider: 'edge', voice: 'th-TH-PremwadeeNeural' },
    hair: 'long',
    acc: 'earring',
    palette: {
      hair: '#3b2a1f', hairHi: '#8a5f3c',
      skin: '#f6d5b8', eye: '#33261d',
      cloth: '#d1fae5', cloth2: '#6ee7b7',
      accent: '#f59e0b', blush: 'rgba(244,114,182,.40)',
    },
  },

  /* ---------- 图片形象（示范） ----------
     素材来自 AI 生成的原创虚构人物，见 public/avatars/<id>/CREDIT.txt。
     只放了 idle 一张，另外三个状态自动回退 + 靠动效表达。 */
  {
    id: 'sofia',
    name: 'Sofia',
    role: '英语会话老师',
    tag: '写实照片 · 二十出头 · 干练亲和',
    kind: 'image',
    cutout: true,                       // 去背抠图：不画底板，人物直接浮在页面上
    lang: 'en-US',
    tts: { provider: 'edge', voice: 'en-US-AvaMultilingualNeural' },
    images: { idle: '/avatars/sofia/idle.webp' },   // 2:3 竖版到腰 · 写实照片 · 透明背景
    tint: '#3b82f6',
  },
  {
    id: 'aoi',
    name: 'Aoi 葵',
    role: '商务日语 · 精英 OL',
    tag: '写实照片 · 干练得体 · 谈判与商务场面',
    kind: 'image',
    cutout: true,
    lang: 'ja-JP',
    // ⚠️ Edge 的日语女声只有 Nanami 一个，所以和 Yuki 同音色（想区分要换引擎）
    tts: { provider: 'edge', voice: 'ja-JP-NanamiNeural' },
    images: { idle: '/avatars/aoi/idle.webp' },
    tint: '#0ea5e9',
  },
  {
    id: 'ploy',
    name: 'Ploy 普洛伊',
    role: '泰语会话老师',
    tag: '泰式古典美人 · 长发低髻 · 大城王朝贵族装扮',
    kind: 'image',
    cutout: true,
    lang: 'th-TH',
    // ⚠️ Edge 的泰语女声只有 Premwadee 一个，所以和 Mali 同音色
    tts: { provider: 'edge', voice: 'th-TH-PremwadeeNeural' },
    images: { idle: '/avatars/ploy/idle.webp' },
    tint: '#f59e0b',
  },
]


export const HAIR_PATHS = {
  long: 'M60 22C34 22 26 42 27 62C28 84 22 100 18 114L40 114C40 96 42 84 44 70C46 56 50 48 60 48C70 48 74 56 76 70C78 84 80 96 80 114L102 114C98 100 92 84 93 62C94 42 86 22 60 22Z',
  bob: 'M60 22C34 22 26 42 27 62C28 78 26 90 24 100L38 96C40 82 42 74 44 66C46 54 50 48 60 48C70 48 74 54 76 66C78 74 80 82 82 96L96 100C94 90 92 78 93 62C94 42 86 22 60 22Z',
  tail: 'M60 22C34 22 26 42 27 62C28 84 22 100 18 114L40 114C40 96 42 84 44 70C46 56 50 48 60 48C70 48 74 56 76 70C78 84 80 96 80 114L102 114C98 100 92 84 93 62C94 42 86 22 60 22Z',
}

export const BANG_PATHS = {
  long: 'M35 50C34 30 46 22 60 22C76 22 87 31 86 50C80 40 72 36 63 41C55 45 44 44 35 50Z',
  bob: 'M34 48C33 28 46 21 60 21C77 21 88 30 87 48C83 39 74 35 62 39C51 43 42 42 34 48Z',
  tail: 'M34 47C34 27 46 21 61 21C78 21 88 31 86 49C81 38 70 37 60 42C51 46 41 43 34 47Z',
}
