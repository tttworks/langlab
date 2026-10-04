#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
发音诊断引擎 —— 本地 whisper 词级分析，零外部依赖、零成本。

为什么这样做：他在虚拟老师里说话，走的是浏览器 Web Speech API，只拿回文本、音频不保留，
所以老师无法判断「他是念错了，还是念对了但 ASR 听错」。本脚本就是把音频留下来，
用词级置信度 + 时长异常把「他说得吃力/含糊/卡住」的位置挑出来，交给大模型给针对性建议。

⚠️ 能力边界（不要对用户承诺做不到的）：
  能抓：念错词、含糊、吞音、卡壳、语速、漏读/多读（有参考文本时）
  抓不到：音标级细节（θ 发成 s、儿化、元音长度）—— 那需要音素声学模型或 Azure Speech。
  但对他（按声音记词、dew/due 混淆型错误）来说，词级信号恰好命中要害。

用法：
  python assess_pron.py --in <音频> [--lang en] [--ref "参考文本"] [--json 输出路径]
  stdout 输出 JSON（PHP 端截取第一个 { 到最后一个 } 解析，与 tts.py 同一套容错）

依赖：faster_whisper（已装）+ 本地 CT2 模型（/path/to/langlab/models/whisper-small-ct2）
      ffmpeg 或 PyAV 用于把 webm/opus 转 16k 单声道（浏览器 MediaRecorder 的默认输出）
"""
import argparse
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import wave

MODEL_DIR = os.environ.get(
    'WHISPER_MODEL_DIR',
    os.path.join(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))),
                 'models', 'whisper-small-ct2'),
)
FFMPEG_CANDIDATES = [
    os.environ.get('FFMPEG_EXE', ''),
    r'D:\Program Files\ffmpeg-7.1.1-full_build\bin\ffmpeg.exe',
    'ffmpeg',
]

# 判定阈值（基于他 150 秒真实复述样本的实测分布调的）
P_STRUGGLE = 0.55      # 词置信度低于此 → 疑似念得含糊/念错
DUR_RATIO = 2.8        # 单词时长超过中位数的倍数
DUR_MIN = 0.75         # 且绝对时长超过这个（秒）才算，避免把极短句误判


def find_ffmpeg():
    for c in FFMPEG_CANDIDATES:
        if not c:
            continue
        if c == 'ffmpeg':
            p = shutil.which('ffmpeg')
            if p:
                return p
        elif os.path.isfile(c):
            return c
    return None


def to_wav_16k_mono(src, dst):
    """把任意格式（webm/opus/mp3/m4a/wav）转成 16k 单声道 wav。"""
    ff = find_ffmpeg()
    if ff:
        cmd = [ff, '-y', '-hide_banner', '-loglevel', 'error', '-i', src,
               '-ac', '1', '-ar', '16000', '-c:a', 'pcm_s16le', dst]
        r = subprocess.run(cmd, capture_output=True)
        if r.returncode == 0 and os.path.isfile(dst) and os.path.getsize(dst) > 44:
            return True, 'ffmpeg'
        err = (r.stderr or b'').decode('utf-8', 'ignore')[:200]
        return False, 'ffmpeg 失败：' + err
    # 退路：PyAV 直接解码（不传 metadata_errors —— 那正是 faster-whisper 踩到的坑）
    try:
        import av
        import numpy as np
        with av.open(src) as c:
            st = next(s for s in c.streams if s.type == 'audio')
            res = av.AudioResampler(format='s16', layout='mono', rate=16000)
            chunks = []
            for frame in c.decode(st):
                for f in res.resample(frame):
                    chunks.append(f.to_ndarray().tobytes())
        raw = b''.join(chunks)
        with wave.open(dst, 'wb') as w:
            w.setnchannels(1)
            w.setsampwidth(2)
            w.setframerate(16000)
            w.writeframes(raw)
        return True, 'pyav'
    except Exception as e:
        return False, '没有可用的解码器（ffmpeg 未找到，PyAV 也失败：%s）' % e


def read_wav_mono16k(path):
    """读 16k 单声道 wav → float32 numpy。不把路径交给 transcribe()，绕开 PyAV 兼容坑。"""
    import numpy as np
    with wave.open(path, 'rb') as w:
        ch, sr, n = w.getnchannels(), w.getframerate(), w.getnframes()
        raw = w.readframes(n)
    a = np.frombuffer(raw, dtype=np.int16).astype(np.float32) / 32768.0
    if ch == 2:
        a = a.reshape(-1, 2).mean(axis=1)
    if sr != 16000:
        # 线性重采样够了 —— 我们只做词级分析，不做声学建模
        idx = np.linspace(0, len(a) - 1, int(len(a) * 16000 / sr))
        a = np.interp(idx, np.arange(len(a)), a).astype(np.float32)
    return a, len(a) / 16000.0


def norm_word(s):
    return re.sub(r"[^a-z0-9']", '', (s or '').lower())


def analyze(words, ref_text=''):
    """产出 flags。words: [{w,start,end,dur,p}]"""
    flags = []
    durs = sorted(x['dur'] for x in words) or [0]
    median = durs[len(durs) // 2] if durs else 0

    for i, x in enumerate(words):
        w = x['w']
        if not norm_word(w):
            continue
        if x['p'] < P_STRUGGLE:
            flags.append({'w': w, 'i': i, 'reason': 'low_conf',
                          'p': round(x['p'], 2), 'dur': x['dur']})
        elif median > 0 and x['dur'] > max(DUR_MIN, median * DUR_RATIO):
            flags.append({'w': w, 'i': i, 'reason': 'long_dur',
                          'p': round(x['p'], 2), 'dur': x['dur']})

    # 有参考文本时，再补「漏读 / 多读」
    missing, extra = [], []
    if ref_text.strip():
        import difflib
        a = [norm_word(w) for w in ref_text.split()]
        b = [norm_word(x['w']) for x in words]
        a = [x for x in a if x]
        b = [x for x in b if x]
        for tag, i1, i2, j1, j2 in difflib.SequenceMatcher(None, a, b).get_opcodes():
            if tag in ('delete', 'replace'):
                missing.extend(a[i1:i2])
            if tag in ('insert', 'replace'):
                extra.extend(b[j1:j2])
    return flags, missing, extra, median


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--in', dest='src', required=True)
    ap.add_argument('--lang', default='en')
    ap.add_argument('--ref', default='')
    ap.add_argument('--json', default='')
    ap.add_argument('--model', default=MODEL_DIR)
    a = ap.parse_args()

    if not os.path.isfile(a.src):
        return out({'ok': False, 'error': '音频文件不存在'}, a.json)

    tmpdir = tempfile.mkdtemp(prefix='langlab_pron_')
    wav = os.path.join(tmpdir, 'a.wav')
    try:
        okc, how = to_wav_16k_mono(a.src, wav)
        if not okc:
            return out({'ok': False, 'error': how}, a.json)

        audio, dur = read_wav_mono16k(wav)
        if dur < 0.4:
            return out({'ok': False, 'error': '录音太短（%.1f 秒）' % dur}, a.json)

        import warnings
        warnings.filterwarnings('ignore')
        from faster_whisper import WhisperModel
        model = WhisperModel(a.model, device='cpu', compute_type='int8')

        # vad_filter 会掐掉停顿 —— 但我们需要看「卡壳」，所以关掉，只保留词级切分
        segs, info = model.transcribe(
            audio, language=a.lang, word_timestamps=True,
            beam_size=5, vad_filter=False,
            condition_on_previous_text=False,     # 避免前文把他的错词"带偏"成对的
        )
        words, text_parts = [], []
        for s in segs:
            text_parts.append(s.text.strip())
            for wd in (s.words or []):
                words.append({
                    'w': wd.word.strip(),
                    'start': round(wd.start, 2),
                    'end': round(wd.end, 2),
                    'dur': round(max(0.0, wd.end - wd.start), 2),
                    'p': round(float(wd.probability), 3),
                })

        flags, missing, extra, median = analyze(words, a.ref)
        speech_sec = (words[-1]['end'] - words[0]['start']) if len(words) > 1 else dur
        wpm = round(len(words) / speech_sec * 60) if speech_sec > 0.5 else 0

        return out({
            'ok': True,
            'engine': 'local-whisper-small',
            'decoder': how,
            'duration': round(dur, 2),
            'speech_seconds': round(speech_sec, 2),
            'text': ' '.join(text_parts).strip(),
            'words': words,
            'flags': flags,
            'missing': missing[:20],
            'extra': extra[:20],
            'median_word_dur': round(median, 2),
            'metrics': {'words': len(words), 'flags': len(flags), 'wpm': wpm},
        }, a.json)
    except Exception as e:
        return out({'ok': False, 'error': '%s: %s' % (type(e).__name__, e)}, a.json)
    finally:
        shutil.rmtree(tmpdir, ignore_errors=True)


def out(data, path=''):
    s = json.dumps(data, ensure_ascii=False)
    if path:
        try:
            with open(path, 'w', encoding='utf-8') as f:
                f.write(s)
        except Exception:
            pass
    sys.stdout.write(s)
    sys.stdout.flush()
    return 0


if __name__ == '__main__':
    sys.exit(main())
