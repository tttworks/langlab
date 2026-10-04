# -*- coding: utf-8 -*-
"""
langlab 语音合成（可切换引擎）

用法：
    python tts.py --list --provider edge
    python tts.py --provider edge --voice ja-JP-NanamiNeural --text "こんにちは" --out a.mp3
    python tts.py --provider openai --voice alloy --text "hello" --out a.mp3
    python tts.py --provider azure --voice ja-JP-NanamiNeural --text "…" --out a.mp3
    python tts.py --provider cosyvoice --voice longanhuan --text "你好" --out a.mp3
    python tts.py --provider doubao --voice zh_female_vv_uranus_bigtts --text "你好" --out a.mp3

输出统一为 JSON：{"ok":true,"provider":"edge","voice":"…","bytes":12345,"ms":800}
失败返回 {"ok":false,"error":"…","hint":"…"}，由 PHP 侧决定降级（回退浏览器内置语音）。

引擎：
  edge   微软 Edge 在线朗读（Azure Neural 音色，免费、无需 Key）—— 日语推荐
  openai OpenAI 语音合成（需 OPENAI_API_KEY）
  azure  Azure 语音服务（需 AZURE_SPEECH_KEY + AZURE_SPEECH_REGION）
  minimax MiniMax 海螺（需 MINIMAX_API_KEY）—— 日/泰/中/英都自然，音色带性格
  cosyvoice 阿里云百炼 CosyVoice（需 DASHSCOPE_API_KEY）—— 中文自然度最好、最便宜
  doubao  火山·豆包语音 2.0（需 DOUBAO_API_KEY）—— 音色最多，很多是抖音/豆包里那个声音
  browser 不在这里实现：由前端用浏览器内置 speechSynthesis 兜底
"""

import argparse
import asyncio
import json
import os
import sys
import time

# 输出必须干净：库的告警一律压到 stderr，避免污染 JSON
try:
    sys.stdout.reconfigure(encoding="utf-8")
except Exception:
    pass


def out(obj):
    print(json.dumps(obj, ensure_ascii=False), flush=True)


# ---------------------------------------------------------------- edge

async def edge_list(lang=None):
    import edge_tts
    vs = await edge_tts.list_voices()
    rows = []
    for v in vs:
        if lang and not v.get("Locale", "").lower().startswith(lang.lower()):
            continue
        rows.append({
            "id": v.get("ShortName"),
            "lang": v.get("Locale"),
            "gender": v.get("Gender"),
            # 只留名字里最后一段做显示名：ja-JP-NanamiNeural → Nanami
            "name": (v.get("ShortName") or "").split("-")[-1].replace("Neural", ""),
            "friendly": v.get("FriendlyName") or "",
        })
    # 女声排前面：Azure 的日/英主推音色（Nanami / Aria）都是女声，当默认更合适
    rows.sort(key=lambda r: (r["lang"], 0 if r.get("gender") == "Female" else 1, r["name"]))
    return rows


async def edge_say(text, voice, out_path, rate="+0%", volume="+0%", pitch="+0Hz", timings_path=""):
    """合成音频；给了 timings_path 就同时吐一份词级时间戳。

    关键：edge_tts.Communicate 默认只回 SentenceBoundary，必须显式
    `boundary="WordBoundary"` 才有逐词的 offset/duration（单位 100 纳秒）。
    有了它前端才能画「读到哪个词」的跟读横线 —— 这是别的免费方案给不了的。
    """
    import edge_tts
    comm = edge_tts.Communicate(text, voice, rate=rate, volume=volume, pitch=pitch,
                                boundary="WordBoundary")
    words = []
    with open(out_path, "wb") as f:
        async for chunk in comm.stream():
            t = chunk.get("type")
            if t == "audio":
                f.write(chunk["data"])
            elif t == "WordBoundary":
                words.append({
                    "t": round(chunk.get("offset", 0) / 10_000_000, 3),      # 100ns → 秒
                    "d": round(chunk.get("duration", 0) / 10_000_000, 3),
                    "w": chunk.get("text", ""),
                })

    if timings_path:
        try:
            _write_timings(text, words, timings_path)
        except Exception as e:  # 时间戳坏了不该影响音频
            print("timings 写入失败：%s" % e, file=sys.stderr)
    return os.path.getsize(out_path) if os.path.exists(out_path) else 0


def _write_timings(text, words, path):
    """把词级时间戳落盘，并补上每个词在原文里的字符区间（cs/ce）。

    不直接信 WordBoundary 的切分与原文一致 —— 按顺序在原文里找，找不到就按词长往后推，
    保证 cs/ce 单调不回退（前端要靠它画线）。
    """
    out = []
    cursor = 0
    for w in words:
        word = (w.get("w") or "").strip()
        if not word:
            continue
        idx = text.find(word, cursor)
        if idx < 0:
            idx = text.find(word)            # 回退到全局找一次
        if idx < 0:
            idx = cursor                      # 实在找不到就顺推，别让进度乱跳
        cs = idx
        ce = min(len(text), idx + len(word))
        cursor = max(cursor, ce)
        out.append({"t": w["t"], "d": w["d"], "w": word, "cs": cs, "ce": ce})

    data = {"words": out, "chars": len(text), "count": len(out)}
    with open(path, "w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False)


# ---------------------------------------------------------------- openai

def openai_say(text, voice, out_path, speed=1.0):
    import urllib.request
    key = os.environ.get("OPENAI_API_KEY") or ""
    if not key:
        return None, "未配置 OPENAI_API_KEY", "在环境变量里设置 OPENAI_API_KEY 后重启后端"
    base = os.environ.get("OPENAI_BASE_URL") or "https://api.openai.com/v1"
    body = json.dumps({
        "model": os.environ.get("OPENAI_TTS_MODEL") or "gpt-4o-mini-tts",
        "voice": voice or "alloy",
        "input": text,
        "speed": speed,
        "response_format": "mp3",
    }).encode("utf-8")
    req = urllib.request.Request(base.rstrip("/") + "/audio/speech", data=body, headers={
        "Authorization": "Bearer " + key,
        "Content-Type": "application/json",
    })
    with urllib.request.urlopen(req, timeout=60) as r:
        data = r.read()
    with open(out_path, "wb") as f:
        f.write(data)
    return len(data), "", ""


# ---------------------------------------------------------------- azure

def azure_say(text, voice, out_path, rate="+0%"):
    import urllib.request
    key = os.environ.get("AZURE_SPEECH_KEY") or ""
    region = os.environ.get("AZURE_SPEECH_REGION") or ""
    if not key or not region:
        return None, "未配置 AZURE_SPEECH_KEY / AZURE_SPEECH_REGION", "在环境变量里设置后重启后端"
    lang = "-".join((voice or "ja-JP-NanamiNeural").split("-")[:2])
    ssml = (f"<speak version='1.0' xml:lang='{lang}'>"
            f"<voice name='{voice}'><prosody rate='{rate}'>{text}</prosody></voice></speak>")
    url = f"https://{region}.tts.speech.microsoft.com/cognitiveservices/v1"
    req = urllib.request.Request(url, data=ssml.encode("utf-8"), headers={
        "Ocp-Apim-Subscription-Key": key,
        "Content-Type": "application/ssml+xml",
        "X-Microsoft-OutputFormat": "audio-24khz-48kbitrate-mono-mp3",
        "User-Agent": "langlab",
    })
    with urllib.request.urlopen(req, timeout=60) as r:
        data = r.read()
    with open(out_path, "wb") as f:
        f.write(data)
    return len(data), "", ""


# ---------------------------------------------------------------- minimax
#
# MiniMax（海螺）语音：日 / 泰 / 中 / 英都很自然，而且每个音色自带「性格」，
# 很适合给虚拟老师配音（同一个角色换个音色就像换了个人）。
#
# 计费（2026-10 官方价，按字符数）：
#   国际站 api.minimax.io   speech-2.8-turbo $60/百万字符   speech-2.8-hd $100/百万字符
#   国内站（MINIMAX_BASE_URL 指向国内）通常约为国际站的一半
# 环境变量：MINIMAX_API_KEY（必填）、MINIMAX_GROUP_ID（可选）、
#          MINIMAX_BASE_URL（默认 https://api.minimax.io/v1）、MINIMAX_TTS_MODEL（默认 speech-2.8-hd）

# 音色 id 前缀 → locale（前端靠 locale 判断「和当前语言是否同一口音」）
MM_PREFIX_LOCALE = [
    ("japanese", "ja-JP"), ("thai", "th-TH"), ("korean", "ko-KR"),
    ("chinese (mandarin)", "zh-CN"), ("chinese (yue)", "zh-HK"), ("cantonese", "zh-HK"),
    ("chinese", "zh-CN"), ("english", "en-US"), ("spanish", "es-ES"), ("french", "fr-FR"),
    ("german", "de-DE"), ("portuguese", "pt-BR"), ("russian", "ru-RU"), ("italian", "it-IT"),
    ("arabic", "ar-SA"), ("vietnamese", "vi-VN"), ("indonesian", "id-ID"), ("hindi", "hi-IN"),
]


def _mm_base():
    return (os.environ.get("MINIMAX_BASE_URL") or "https://api.minimax.io/v1").rstrip("/")


def _mm_locale(voice_id):
    v = (voice_id or "").lower()
    for pre, loc in MM_PREFIX_LOCALE:
        if v.startswith(pre):
            return loc
    return ""


def _mm_lang_boost(voice_id, text):
    """language_boost 只影响识别增强，不会翻译文本"""
    v = (voice_id or "").lower()
    for pre, loc in MM_PREFIX_LOCALE:
        if v.startswith(pre):
            return {"ja-JP": "Japanese", "th-TH": "Thai", "zh-CN": "Chinese", "zh-HK": "Chinese,Yue",
                    "ko-KR": "Korean", "en-US": "English", "es-ES": "Spanish", "fr-FR": "French",
                    "de-DE": "German", "pt-BR": "Portuguese", "ru-RU": "Russian", "it-IT": "Italian",
                    "ar-SA": "Arabic", "vi-VN": "Vietnamese", "id-ID": "Indonesian",
                    "hi-IN": "Hindi"}.get(loc, "auto")
    # 兜底：按文字里的字符猜
    if any("\u3040" <= c <= "\u30ff" for c in text):
        return "Japanese"
    if any("\u0e00" <= c <= "\u0e7f" for c in text):
        return "Thai"
    if any("\u4e00" <= c <= "\u9fff" for c in text):
        return "Chinese"
    return "auto"


def minimax_list(lang=None):
    import urllib.request
    key = os.environ.get("MINIMAX_API_KEY") or ""
    if not key:
        return None, "未配置 MINIMAX_API_KEY", "在 backend/data/.env 里设置 MINIMAX_API_KEY 后重启后端"
    req = urllib.request.Request(_mm_base() + "/get_voice",
                                 data=json.dumps({"voice_type": "all"}).encode("utf-8"),
                                 headers={"Authorization": "Bearer " + key,
                                          "Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=60) as r:
        j = json.loads(r.read().decode("utf-8"))
    want = (lang or "").lower()[:2]
    rows = []
    for v in (j.get("system_voice") or []):
        vid = (v.get("voice_id") or "").strip()
        if not vid:
            continue
        loc = _mm_locale(vid)
        if want and not loc.lower().startswith(want):
            continue
        desc = v.get("description")
        if isinstance(desc, list):
            desc = " ".join(str(x) for x in desc)
        blob = (vid + " " + str(v.get("voice_name") or "") + " " + str(desc or "")).lower()
        gender = "Female" if ("female" in blob or "woman" in blob or "girl" in blob or "lady" in blob) \
            else ("Male" if ("male" in blob or "man" in blob or "boy" in blob or "gentleman" in blob) else "")
        rows.append({
            "id": vid,
            "name": v.get("voice_name") or vid,
            "lang": loc or "multi",
            "gender": gender,
            "friendly": str(desc or "")[:80],
        })
    # 女声优先，其次按名字：虚拟老师默认给女声更自然
    rows.sort(key=lambda r: (_mm_locale(r["id"]) or "zzz", 0 if r.get("gender") == "Female" else 1, r["name"]))
    return rows, "", ""


def minimax_say(text, voice, out_path, speed=1.0):
    import urllib.error
    import urllib.parse
    import urllib.request
    key = os.environ.get("MINIMAX_API_KEY") or ""
    if not key:
        return None, "未配置 MINIMAX_API_KEY", "在 backend/data/.env 里设置 MINIMAX_API_KEY 后重启后端"
    try:
        sp = float(speed or 1.0)
    except Exception:
        sp = 1.0
    body = json.dumps({
        "model": os.environ.get("MINIMAX_TTS_MODEL") or "speech-2.8-hd",
        "text": text,
        "stream": False,
        "language_boost": _mm_lang_boost(voice, text),
        "output_format": "hex",
        "voice_setting": {"voice_id": voice or "English_expressive_narrator",
                          "speed": max(0.5, min(2.0, sp)), "vol": 1, "pitch": 0},
        "audio_setting": {"sample_rate": 32000, "bitrate": 128000, "format": "mp3", "channel": 1},
    }).encode("utf-8")
    url = _mm_base() + "/t2a_v2"
    # 有些接口版本要求 GroupId 拼在 URL 上，有些只需要 header。
    # 默认不拼（新版 v2 通常不需要），调不通时设 MINIMAX_NEED_GROUP=1 再试。
    group = os.environ.get("MINIMAX_GROUP_ID") or ""
    if group and (os.environ.get("MINIMAX_NEED_GROUP") or "") == "1":
        url += "?GroupId=" + urllib.parse.quote(group)
    req = urllib.request.Request(url, data=body, headers={
        "Authorization": "Bearer " + key, "Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=90) as r:
            j = json.loads(r.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        detail = ""
        try:
            detail = e.read().decode("utf-8", "replace")[:300]
        except Exception:
            pass
        return None, "MiniMax 返回 HTTP %s：%s" % (e.code, detail), "检查 MINIMAX_API_KEY 是否有效、账户是否有余额"
    base = j.get("base_resp") or {}
    if base.get("status_code") not in (0, None):
        return None, "MiniMax 报错 %s：%s" % (base.get("status_code"), base.get("status_msg")), \
            "详见 MiniMax 控制台的错误说明"
    hexa = ((j.get("data") or {}).get("audio") or "")
    if not hexa:
        return None, "MiniMax 没返回音频数据", "换个音色或稍后重试"
    data = bytes.fromhex(hexa)
    with open(out_path, "wb") as f:
        f.write(data)
    return len(data), "", ""


# ---------------------------------------------------------------- cosyvoice（阿里云百炼）
#
# CosyVoice 是阿里通义的中文语音大模型，中文自然度公认第一梯队（开源实测 MOS 4.7），
# 价格也最便宜：v3.5-flash ¥0.8/万字符、v3-flash ¥1/万字符 ≈ ¥80~100 / 百万字符。
# 64 个系统音色，含粤语 3、方言 3（东北/陕西/闽南）、童声 4、台式 1，另有日 5 韩 2。
#
# 接口：POST {base}/services/audio/tts/SpeechSynthesizer   鉴权 Authorization: Bearer <Key>
# ⚠️ 非流式返回的是 output.audio.url（24 小时有效），data 字段是空的 —— 必须再下载一次。
# ⚠️ 音色绑定模型：下面这份是 cosyvoice-v3-flash 的，换模型要换音色表（v2 的音色带 _v2 后缀）。
# ⚠️ longanyang 是唯一不带 _v3 后缀的，别按规律给它补后缀。
#
# 环境变量：DASHSCOPE_API_KEY（必填）、
#          DASHSCOPE_BASE_URL（默认 https://dashscope.aliyuncs.com/api/v1）、
#          COSYVOICE_MODEL（默认 cosyvoice-v3-flash）

# (voice_id, 名称, 性别, locale, 特质)
COSY_VOICES = [
    ("longanhuan", "龙安欢", "Female", "zh-CN", "欢脱元气女·20~30岁"),
    ("longanling_v3", "龙安灵", "Female", "zh-CN", "思维灵动女·陪伴闲聊"),
    ("longanya_v3", "龙安雅", "Female", "zh-CN", "高雅气质女·25~35岁"),
    ("longanwen_v3", "龙安温", "Female", "zh-CN", "优雅知性女·语音助手"),
    ("longanqin_v3", "龙安亲", "Female", "zh-CN", "亲和活泼女·接地气"),
    ("longanli_v3", "龙安莉", "Female", "zh-CN", "利落从容女·25~35岁"),
    ("longantai_v3", "龙安台", "Female", "zh-CN", "嗲甜台湾女·20~25岁"),
    ("longhua_v3", "龙华", "Female", "zh-CN", "元气甜美女·20~25岁"),
    ("longyan_v3", "龙颜", "Female", "zh-CN", "温暖春风女·30~35岁"),
    ("longxing_v3", "龙星", "Female", "zh-CN", "温婉邻家女·20~25岁"),
    ("longwan_v3", "龙婉", "Female", "zh-CN", "细腻柔声女·有声书"),
    ("longwanjun_v3", "龙婉君", "Female", "zh-CN", "细腻柔声女·有声书青春"),
    ("longxiaochun_v3", "龙小淳", "Female", "zh-CN", "知性积极女·语音助手"),
    ("longxiaoxia_v3", "龙小夏", "Female", "zh-CN", "沉稳权威女·语音助手"),
    ("longyumi_v3", "YUMI", "Female", "zh-CN", "正经青年女·20~25岁"),
    ("longyingling_v3", "龙应聆", "Female", "zh-CN", "温和共情女·25~30岁"),
    ("longyingtao_v3", "龙应桃", "Female", "zh-CN", "温柔淡定女·客服"),
    ("longyingjing_v3", "龙应静", "Female", "zh-CN", "低调冷静女·25~35岁"),
    ("longyingmu_v3", "龙应沐", "Female", "zh-CN", "优雅知性女·电话助手"),
    ("longyingxiao_v3", "龙应笑", "Female", "zh-CN", "清甜推销女·20~25岁"),
    ("longdaiyu_v3", "龙黛玉", "Female", "zh-CN", "娇率才女音·短视频"),
    ("longanxuan_v3", "龙安宣", "Female", "zh-CN", "经典直播女·30~40岁"),
    ("longlaoyi_v3", "龙老姨", "Female", "zh-CN", "烟火从容阿姨·有声书"),
    ("loongbella_v3", "Bella3.0", "Female", "zh-CN", "精准干练女·25~30岁"),
    ("longhuhu_v3", "龙呼呼", "Female", "zh-CN", "天真烂漫女童·6~10岁"),
    ("longpaopao_v3", "龙泡泡", "Female", "zh-CN", "飞天泡泡音·6~15岁"),
    ("longshanshan_v3", "龙闪闪", "Female", "zh-CN", "戏剧化童声·儿童有声书"),
    ("longanmin_v3", "龙安闽", "Female", "zh-CN", "闽南话·清纯萝莉女"),
    ("longanyang", "龙安洋", "Male", "zh-CN", "阳光大男孩·20~30岁"),
    ("longanlang_v3", "龙安朗", "Male", "zh-CN", "清爽利落男·20~25岁"),
    ("longanyun_v3", "龙安昀", "Male", "zh-CN", "居家暖男·30~35岁"),
    ("longanshuo_v3", "龙安朔", "Male", "zh-CN", "干净清爽男·20~25岁"),
    ("longcheng_v3", "龙橙", "Male", "zh-CN", "智慧青年男·20~25岁"),
    ("longze_v3", "龙泽", "Male", "zh-CN", "温暖元气男·25~30岁"),
    ("longzhe_v3", "龙哲", "Male", "zh-CN", "呆板大暖男·25~30岁"),
    ("longtian_v3", "龙天", "Male", "zh-CN", "磁性理智男·30~35岁"),
    ("longfei_v3", "龙飞", "Male", "zh-CN", "热血磁性男·诗词朗诵"),
    ("longyichen_v3", "龙逸尘", "Male", "zh-CN", "洒脱活力男·有声书青春"),
    ("longyingxun_v3", "龙应询", "Male", "zh-CN", "年轻青涩男·客服"),
    ("longlaobo_v3", "龙老伯", "Male", "zh-CN", "沧桑岁月爷·有声书"),
    ("longlaotie_v3", "龙老铁", "Male", "zh-CN", "东北话·直率男"),
    ("longshange_v3", "龙陕哥", "Male", "zh-CN", "陕西话·原味陕北男"),
    ("longjielidou_v3", "龙杰力豆", "Male", "zh-CN", "阳光顽皮男·10岁童声"),
    ("longniuniu_v3", "龙牛牛", "Male", "zh-CN", "阳光男童声·儿童有声书"),
    ("longjiaxin_v3", "龙嘉欣", "Female", "zh-HK", "粤语·优雅女·30~35岁"),
    ("longjiayi_v3", "龙嘉怡", "Female", "zh-HK", "粤语·知性女·25~30岁"),
    ("longanyue_v3", "龙安粤", "Male", "zh-HK", "粤语·欢脱男·25~35岁"),
]


def _cosy_base():
    return (os.environ.get("DASHSCOPE_BASE_URL") or "https://dashscope.aliyuncs.com/api/v1").rstrip("/")


def cosyvoice_list(lang=None):
    """音色是本地写死的（百炼没有列音色的接口），不需要 Key 就能列。"""
    want = (lang or "").lower()[:2]
    rows = []
    for vid, name, gender, loc, desc in COSY_VOICES:
        if want and not loc.lower().startswith(want):
            continue
        rows.append({"id": vid, "name": name, "lang": loc, "gender": gender, "friendly": desc})
    # 女声优先：中文聊想法的角色默认给女声
    rows.sort(key=lambda r: (0 if r["gender"] == "Female" else 1, r["name"]))
    return rows, "", ""


def cosyvoice_say(text, voice, out_path, speed=1.0):
    import urllib.error
    import urllib.request
    key = os.environ.get("DASHSCOPE_API_KEY") or ""
    if not key:
        return None, "未配置 DASHSCOPE_API_KEY", \
            "去阿里云百炼控制台创建 API Key，填进 backend/data/.env 后重启后端"
    try:
        sp = float(speed or 1.0)
    except Exception:
        sp = 1.0
    body = json.dumps({
        "model": os.environ.get("COSYVOICE_MODEL") or "cosyvoice-v3-flash",
        "input": {
            "text": text,
            "voice": voice or "longanhuan",
            "format": "mp3",
            "sample_rate": 24000,
            "rate": max(0.5, min(2.0, sp)),
            "volume": 50,
        },
    }).encode("utf-8")
    req = urllib.request.Request(_cosy_base() + "/services/audio/tts/SpeechSynthesizer",
                                 data=body, headers={
                                     "Authorization": "Bearer " + key,
                                     "Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=90) as r:
            j = json.loads(r.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        detail = ""
        try:
            detail = e.read().decode("utf-8", "replace")[:300]
        except Exception:
            pass
        return None, "百炼返回 HTTP %s：%s" % (e.code, detail), \
            "检查 DASHSCOPE_API_KEY、账户余额，以及音色是否属于当前模型"

    audio = ((j.get("output") or {}).get("audio") or {})
    hexa = audio.get("data") or ""
    if hexa:
        try:
            data = bytes.fromhex(hexa) if all(c in "0123456789abcdefABCDEF" for c in hexa[:32]) \
                else __import__("base64").b64decode(hexa)
        except Exception:
            data = b""
    else:
        data = b""

    if not data:
        # 非流式模式给的是一个 24 小时有效的下载链接，data 字段是空的
        url = audio.get("url") or ""
        if not url:
            return None, "百炼没返回音频：" + json.dumps(j, ensure_ascii=False)[:200], \
                "确认音色 id 属于当前模型（换模型要换音色表）"
        try:
            with urllib.request.urlopen(url, timeout=90) as r:
                data = r.read()
        except Exception as e:
            return None, "下载百炼音频失败：%s" % e, "重试一次；仍失败检查网络能否访问 dashscope-result-bj.oss"

    if not data:
        return None, "百炼返回空音频", "换个音色或稍后重试"
    with open(out_path, "wb") as f:
        f.write(data)
    return len(data), "", ""


# ---------------------------------------------------------------- doubao（火山·豆包语音）
#
# 豆包语音合成大模型 2.0，中文自然度和情绪表现都不错，音色非常多（100+），
# 很多就是抖音/豆包/剪映里你听过的那个声音。价格约 ¥270~650/百万字符（按音色档位），
# 比 CosyVoice 贵，但音色个性和"熟悉感"是它最大的卖点。
#
# 接口：POST https://openspeech.bytedance.com/api/v3/tts/unidirectional
#   Header：X-Api-Key / X-Api-Resource-Id: seed-tts-2.0 / X-Api-Request-Id: uuid
#   响应是 chunked 的多段 JSON，data 字段是 base64 音频帧，要按顺序拼接。
# ⚠️ Key 来自「火山引擎 → 语音技术控制台 → 创建应用」的 App ID + Access Token，
#    和火山方舟（方舟大模型）的 API Key 不是一回事，别填错。
#
# 环境变量：DOUBAO_API_KEY（必填）、DOUBAO_APP_ID（可选，部分老账号需要）、
#          DOUBAO_RESOURCE_ID（默认 seed-tts-2.0；声音复刻音色填 seed-icl-2.0）

# (voice_id, 名称, 性别, locale, 特质)
DOUBAO_VOICES = [
    ("zh_female_vv_uranus_bigtts", "Vivi 2.0", "Female", "zh-CN", "多语种女声·中日印尼西+粤沪豫京津川陕东北方言"),
    ("zh_female_xiaohe_uranus_bigtts", "小何 2.0", "Female", "zh-CN", "通用女声·自然亲切"),
    ("zh_female_cancan_uranus_bigtts", "知性灿灿 2.0", "Female", "zh-CN", "知性女声·角色扮演"),
    ("zh_female_zhixingnv_uranus_bigtts", "知性女声 2.0", "Female", "zh-CN", "知性成熟女声"),
    ("zh_female_meilinvyou_uranus_bigtts", "魅力女友 2.0", "Female", "zh-CN", "魅力成熟女声"),
    ("zh_female_tianmeixiaoyuan_uranus_bigtts", "甜美小源 2.0", "Female", "zh-CN", "甜美女声"),
    ("zh_female_tianmeitaozi_uranus_bigtts", "甜美桃子 2.0", "Female", "zh-CN", "甜美女声"),
    ("zh_female_shuangkuaisisi_uranus_bigtts", "爽快思思 2.0", "Female", "zh-CN", "爽朗活泼女声"),
    ("zh_female_linjianvhai_uranus_bigtts", "邻家女孩 2.0", "Female", "zh-CN", "亲切邻家女声"),
    ("zh_female_qingxinnvsheng_uranus_bigtts", "清新女声 2.0", "Female", "zh-CN", "清新淡雅女声"),
    ("zh_female_wenroushunv_uranus_bigtts", "温柔淑女 2.0", "Female", "zh-CN", "温柔淑女·有声阅读"),
    ("zh_female_wenroumama_uranus_bigtts", "温柔妈妈 2.0", "Female", "zh-CN", "温柔妈妈音"),
    ("zh_female_qinqienv_uranus_bigtts", "亲切女声 2.0", "Female", "zh-CN", "亲切温和女声"),
    ("zh_female_tiexinnvsheng_uranus_bigtts", "贴心女声 2.0", "Female", "zh-CN", "贴心女声"),
    ("zh_female_kefunvsheng_uranus_bigtts", "暖阳女声 2.0", "Female", "zh-CN", "温暖专业·客服"),
    ("zh_female_yingyujiaoxue_uranus_bigtts", "Tina老师 2.0", "Female", "zh-CN", "中英双语·教学场景"),
    ("zh_female_liuchangnv_uranus_bigtts", "流畅女声 2.0", "Female", "zh-CN", "流畅自然·视频配音"),
    ("zh_female_gaolengyujie_uranus_bigtts", "高冷御姐 2.0", "Female", "zh-CN", "高冷御姐"),
    ("zh_female_gufengshaoyu_uranus_bigtts", "古风少御 2.0", "Female", "zh-CN", "古风少御"),
    ("zh_female_kailangjiejie_uranus_bigtts", "开朗姐姐 2.0", "Female", "zh-CN", "开朗大姐姐"),
    ("zh_female_wenjingmaomao_uranus_bigtts", "文静毛毛 2.0", "Female", "zh-CN", "文静女声"),
    ("zh_female_mengyatou_uranus_bigtts", "萌丫头 2.0", "Female", "zh-CN", "萌系女声"),
    ("zh_female_sajiaoxuemei_uranus_bigtts", "撒娇学妹 2.0", "Female", "zh-CN", "撒娇学妹·角色扮演"),
    ("zh_female_zhishuaiyingzi_uranus_bigtts", "直率英子 2.0", "Female", "zh-CN", "直率女声·角色扮演"),
    ("zh_female_xiaoxue_uranus_bigtts", "儿童绘本 2.0", "Female", "zh-CN", "儿童故事·绘本朗读"),
    ("zh_male_m191_uranus_bigtts", "云舟 2.0", "Male", "zh-CN", "通用男声"),
    ("zh_male_taocheng_uranus_bigtts", "小天 2.0", "Male", "zh-CN", "通用男声·年轻"),
    ("zh_male_wennuanahu_uranus_bigtts", "温暖阿虎 2.0", "Male", "zh-CN", "温暖亲切男声"),
    ("zh_male_ruyaqingnian_uranus_bigtts", "儒雅青年 2.0", "Male", "zh-CN", "儒雅青年"),
    ("zh_male_shenyeboke_uranus_bigtts", "深夜播客 2.0", "Male", "zh-CN", "深夜播客男声"),
    ("zh_male_qingshuangnanda_uranus_bigtts", "清爽男大 2.0", "Male", "zh-CN", "清爽大学生男声"),
    ("zh_male_youyoujunzi_uranus_bigtts", "悠悠君子 2.0", "Male", "zh-CN", "悠悠君子·豆包同款"),
]


def doubao_list(lang=None):
    """音色本地写死（官方没有列音色的接口，音色库在控制台看）。"""
    want = (lang or "").lower()[:2]
    rows = []
    for vid, name, gender, loc, desc in DOUBAO_VOICES:
        if want and not loc.lower().startswith(want):
            continue
        rows.append({"id": vid, "name": name, "lang": loc, "gender": gender, "friendly": desc})
    rows.sort(key=lambda r: (0 if r["gender"] == "Female" else 1, r["name"]))
    return rows, "", ""


def _doubao_chunks(raw):
    """豆包用 chunked 返回多段 JSON（也可能带 SSE 的 data: 前缀），逐段解出来。"""
    import base64
    dec = json.JSONDecoder()
    s = raw.strip()
    objs = []
    i, n = 0, len(s)
    while i < n:
        while i < n and s[i] in " \r\n\t":
            i += 1
        if i >= n:
            break
        if s.startswith("data:", i):
            i += 5
        try:
            obj, end = dec.raw_decode(s, i)
        except ValueError:
            j = s.find("{", i + 1)
            if j < 0:
                break
            i = j
            continue
        objs.append(obj)
        i = end
    return objs


def doubao_say(text, voice, out_path, speed=1.0):
    import base64
    import urllib.error
    import urllib.request
    import uuid
    key = os.environ.get("DOUBAO_API_KEY") or ""
    if not key:
        return None, "未配置 DOUBAO_API_KEY", \
            "火山引擎 → 语音技术控制台 → 创建应用 → 拿 Access Token，填进 backend/data/.env 后重启后端"
    try:
        sp = float(speed or 1.0)
    except Exception:
        sp = 1.0
    # 豆包语速是 [-50,100] 的整数（0 为默认），我们内部是 0.5~2.0 倍率
    speech_rate = int(round((max(0.5, min(2.0, sp)) - 1.0) * 100))
    body = json.dumps({
        "req_params": {
            "text": text,
            "speaker": voice or "zh_female_vv_uranus_bigtts",
            "audio_params": {"format": "mp3", "sample_rate": 24000, "speech_rate": speech_rate},
        }
    }).encode("utf-8")
    req = urllib.request.Request("https://openspeech.bytedance.com/api/v3/tts/unidirectional",
                                 data=body, headers={
                                     "X-Api-Key": key,
                                     "X-Api-Resource-Id": os.environ.get("DOUBAO_RESOURCE_ID") or "seed-tts-2.0",
                                     "X-Api-Request-Id": str(uuid.uuid4()),
                                     "Content-Type": "application/json"})
    if os.environ.get("DOUBAO_APP_ID"):
        req.add_header("X-Api-App-Key", os.environ.get("DOUBAO_APP_ID"))
    try:
        with urllib.request.urlopen(req, timeout=90) as r:
            raw = r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        detail = ""
        try:
            detail = e.read().decode("utf-8", "replace")[:300]
        except Exception:
            pass
        return None, "豆包返回 HTTP %s：%s" % (e.code, detail), \
            "检查 DOUBAO_API_KEY 是否来自「语音技术」应用（方舟大模型的 Key 不能用于语音）"

    objs = _doubao_chunks(raw)
    if not objs:
        return None, "豆包返回无法解析：" + raw[:200], "稍后重试"
    for o in objs:                      # 业务错误优先：code != 0 就别拼音频了
        code = o.get("code")
        if code not in (0, None):
            return None, "豆包报错 %s：%s" % (code, o.get("message") or o.get("Message") or ""), \
                "详见火山引擎语音技术文档的错误码说明"
    parts = []
    for o in objs:
        d = o.get("data")
        if d:
            try:
                parts.append(base64.b64decode(d))
            except Exception:
                pass
    data = b"".join(parts)
    if not data:
        return None, "豆包没返回音频数据", "换个音色或稍后重试"
    with open(out_path, "wb") as f:
        f.write(data)
    return len(data), "", ""


# ---------------------------------------------------------------- main

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--provider", default="edge")
    ap.add_argument("--voice", default="")
    ap.add_argument("--text", default="")
    ap.add_argument("--out", default="")
    ap.add_argument("--timings-out", default="")   # 词级时间戳输出路径（跟读横线用）
    ap.add_argument("--rate", default="+0%")      # edge/azure 用百分比字符串
    ap.add_argument("--speed", type=float, default=1.0)  # openai 用倍率
    ap.add_argument("--pitch", default="+0Hz")
    ap.add_argument("--list", action="store_true")
    ap.add_argument("--lang", default="")          # 列出音色时按语言过滤
    a = ap.parse_args()

    t0 = time.time()
    prov = (a.provider or "edge").lower()

    try:
        if a.list:
            if prov == "edge":
                rows = asyncio.run(edge_list(a.lang or None))
                out({"ok": True, "provider": "edge", "voices": rows, "count": len(rows)})
            elif prov == "openai":
                out({"ok": True, "provider": "openai", "voices": [
                    {"id": x, "name": x, "lang": "multi", "gender": ""}
                    for x in ("alloy", "echo", "fable", "onyx", "nova", "shimmer",
                              "coral", "sage", "ash", "ballad", "verse")
                ]})
            elif prov == "azure":
                out({"ok": True, "provider": "azure", "voices": [
                    {"id": "ja-JP-NanamiNeural", "name": "Nanami", "lang": "ja-JP", "gender": "Female"},
                    {"id": "ja-JP-KeitaNeural", "name": "Keita", "lang": "ja-JP", "gender": "Male"},
                ], "note": "完整列表请用 Azure 的 voices/list 接口"})
            elif prov == "minimax":
                rows, err, hint = minimax_list(a.lang or None)
                if rows is None:
                    out({"ok": False, "provider": prov, "error": err, "hint": hint})
                else:
                    out({"ok": True, "provider": "minimax", "voices": rows, "count": len(rows)})
            elif prov == "cosyvoice":
                rows, err, hint = cosyvoice_list(a.lang or None)
                if rows is None:
                    out({"ok": False, "provider": prov, "error": err, "hint": hint})
                else:
                    out({"ok": True, "provider": "cosyvoice", "voices": rows, "count": len(rows)})
            elif prov == "doubao":
                rows, err, hint = doubao_list(a.lang or None)
                if rows is None:
                    out({"ok": False, "provider": prov, "error": err, "hint": hint})
                else:
                    out({"ok": True, "provider": "doubao", "voices": rows, "count": len(rows)})
            else:
                out({"ok": False, "error": "未知引擎：" + prov})
            return

        if not a.text.strip():
            out({"ok": False, "error": "text 不能为空"})
            return
        if not a.out:
            out({"ok": False, "error": "out 不能为空"})
            return

        if prov == "edge":
            n = asyncio.run(edge_say(a.text, a.voice or "ja-JP-NanamiNeural", a.out,
                                     a.rate, "+0%", a.pitch, a.timings_out))
            hint = ""
        elif prov == "openai":
            n, err, hint = openai_say(a.text, a.voice, a.out, a.speed)
            if n is None:
                out({"ok": False, "provider": prov, "error": err, "hint": hint})
                return
        elif prov == "azure":
            n, err, hint = azure_say(a.text, a.voice or "ja-JP-NanamiNeural", a.out, a.rate)
            if n is None:
                out({"ok": False, "provider": prov, "error": err, "hint": hint})
                return
        elif prov == "minimax":
            n, err, hint = minimax_say(a.text, a.voice or "English_expressive_narrator", a.out, a.speed)
            if n is None:
                out({"ok": False, "provider": prov, "error": err, "hint": hint})
                return
        elif prov == "cosyvoice":
            n, err, hint = cosyvoice_say(a.text, a.voice or "longanhuan", a.out, a.speed)
            if n is None:
                out({"ok": False, "provider": prov, "error": err, "hint": hint})
                return
        elif prov == "doubao":
            n, err, hint = doubao_say(a.text, a.voice or "zh_female_vv_uranus_bigtts", a.out, a.speed)
            if n is None:
                out({"ok": False, "provider": prov, "error": err, "hint": hint})
                return
        else:
            out({"ok": False, "error": "未知引擎：" + prov})
            return

        if not n:
            out({"ok": False, "provider": prov, "error": "合成结果为空（0 字节）"})
            return

        out({
            "ok": True, "provider": prov, "voice": a.voice, "bytes": n,
            "ms": int((time.time() - t0) * 1000),
        })
    except Exception as e:
        msg = "%s: %s" % (type(e).__name__, e)
        hint = ""
        if "edge" in prov and ("Cannot connect" in msg or "Timeout" in msg or "getaddrinfo" in msg or "403" in msg):
            hint = "连不上微软语音服务（可能被网络/代理拦截）。可在设置里切回浏览器内置语音。"
        out({"ok": False, "provider": prov, "error": msg, "hint": hint})


if __name__ == "__main__":
    main()
