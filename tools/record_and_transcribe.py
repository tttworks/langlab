#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
对着麦克风录音 → 自动转写 → 停顿分析。

用途：复述/朗读的口语基线测量。
**停顿分析是关键指标** —— 「结巴」在文本上看不出来，但停顿位置和长度能测出来。

用法：
  python record_and_transcribe.py --seconds 60
  python record_and_transcribe.py --seconds 90 --lang en --tag 复述第4次
  python record_and_transcribe.py --file <已有音频> --lang en     # 不录音，只分析

前置：麦克风已就绪（本机默认 `麦克风 (PD200X Podcast Microphone)`）
"""
import argparse
import os
import subprocess
import sys
import time
import wave
from datetime import datetime

import numpy as np

FFMPEG = r'D:\Program Files\ffmpeg-7.1.1-full_build\bin\ffmpeg.exe'
MODEL_DIR = r'D:\WorkBuddyDatas\2026-09-18-17-45-50\tmp_junli\model_small'
MIC = r'麦克风 (PD200X Podcast Microphone)'
OUTDIR = r'/path/to/langlab\录音'

PAUSE_MIN = 1.2     # 超过这个秒数的间隔算「停顿」
LONG_PAUSE = 3.0    # 超过这个秒数算「长停顿（卡住）」


def record(seconds: int, out: str) -> bool:
    os.makedirs(os.path.dirname(out), exist_ok=True)
    print('🎙  开始录音 %d 秒 —— 现在请说话。' % seconds)
    for i in range(3, 0, -1):
        print('   %d…' % i, end='', flush=True)
        time.sleep(1)
    print(' 说！')
    cmd = [FFMPEG, '-hide_banner', '-loglevel', 'error', '-f', 'dshow',
           '-i', 'audio=' + MIC, '-t', str(seconds),
           '-ac', '1', '-ar', '16000', '-c:a', 'pcm_s16le', '-y', out]
    r = subprocess.run(cmd, capture_output=True, text=True, encoding='utf-8', errors='replace')
    if r.returncode != 0:
        print('录音失败:', (r.stderr or '')[-500:])
        return False
    print('✅ 录音完成: %s (%d 字节)' % (out, os.path.getsize(out)))
    return True


def read_wav(path):
    """必须自己读 wav 传 numpy：本机 faster-whisper 1.2.1 与 PyAV 19 不兼容
    （它调 av.open(..., metadata_errors=...) 会 TypeError）。"""
    with wave.open(path, 'rb') as w:
        if w.getframerate() != 16000 or w.getnchannels() != 1 or w.getsampwidth() != 2:
            raise ValueError('需要 16k/单声道/16bit wav')
        raw = w.readframes(w.getnframes())
    return np.frombuffer(raw, dtype=np.int16).astype(np.float32) / 32768.0


def normalize(src: str) -> str:
    """录音偏小时做增益归一化，否则 whisper 识别会变差。返回用于转写的文件。"""
    a = read_wav(src)
    peak = float(np.abs(a).max())
    print('   电平：峰值 %.3f / 均值 %.4f' % (peak, float(np.abs(a).mean())))
    if peak < 0.02:
        print('   ⚠️ 电平极低（几乎静音）—— 确认麦克风没静音、离得不太远')
    if peak >= 0.5:
        return src
    dst = src.replace('.wav', '.norm.wav')
    subprocess.run([FFMPEG, '-hide_banner', '-loglevel', 'error', '-i', src,
                    '-af', 'volume=%.2fdB' % (20 * np.log10(0.7 / max(peak, 1e-4))),
                    '-ac', '1', '-ar', '16000', '-c:a', 'pcm_s16le', '-y', dst],
                   check=True)
    print('   已做增益归一化（原峰值偏低）')
    return dst


def fmt(s):
    return '%02d:%02d:%02d' % (int(s) // 3600, int(s) % 3600 // 60, int(s) % 60)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--seconds', type=int, default=60)
    ap.add_argument('--lang', default='en')
    ap.add_argument('--tag', default=None)
    ap.add_argument('--file', default=None, help='分析已有音频，不录音')
    ap.add_argument('--model', default=MODEL_DIR)
    a = ap.parse_args()

    tag = a.tag or datetime.now().strftime('rec-%Y%m%d-%H%M%S')
    if a.file:
        src = a.file
        print('分析已有文件:', src)
    else:
        src = os.path.join(OUTDIR, tag + '.wav')
        if not record(a.seconds, src):
            sys.exit(1)

    wav_for_asr = normalize(src)

    from faster_whisper import WhisperModel
    print('   加载模型…')
    model = WhisperModel(a.model, device='cpu', compute_type='int8')
    audio = read_wav(wav_for_asr)
    total = len(audio) / 16000.0
    print('   转写中（音频 %.1f 秒）…' % total)
    t0 = time.time()
    segs, info = model.transcribe(audio, language=a.lang, beam_size=5)
    segs = list(segs)
    print('   完成，%d 段，用时 %.1fs' % (len(segs), time.time() - t0))

    srt = os.path.join(OUTDIR, tag + '.srt')
    txt = os.path.join(OUTDIR, tag + '.txt')
    with open(srt, 'w', encoding='utf-8', newline='') as fs, \
         open(txt, 'w', encoding='utf-8', newline='') as ft:
        for i, s in enumerate(segs, 1):
            fs.write('%d\r\n%s --> %s\r\n%s\r\n\r\n'
                     % (i, fmt(s.start).replace(':', ':'), fmt(s.end), s.text.strip()))
            ft.write('[%.1f] %s\r\n' % (s.start, s.text.strip()))

    # ---------- 停顿分析 ----------
    print('\n' + '=' * 62)
    print('  口语基线 · %s' % tag)
    print('=' * 62)
    print('总时长      : %.1f 秒' % total)
    if not segs:
        print('⚠️ 没转写出任何内容 —— 检查麦克风是否静音')
        return
    speech = sum(s.end - s.start for s in segs)
    print('说话时长    : %.1f 秒（%.0f%%）' % (speech, 100 * speech / total))
    print('静音占比    : %.0f%%' % (100 * (total - speech) / total))

    words = sum(len(s.text.split()) for s in segs)
    print('词数        : %d' % words)
    if speech > 0:
        print('语速        : %.0f 词/分钟（仅说话时段）' % (words / speech * 60))

    # 段间停顿
    gaps = []
    for i in range(1, len(segs)):
        g = segs[i].start - segs[i - 1].end
        if g >= PAUSE_MIN:
            gaps.append((g, segs[i - 1].end, segs[i - 1].text.strip()[-28:]))
    print('停顿次数    : %d 次（≥%.1f 秒）' % (len(gaps), PAUSE_MIN))
    if gaps:
        gaps_sorted = sorted(gaps, reverse=True)
        mx = gaps_sorted[0]
        print('最长停顿    : %.1f 秒（在 %s 之后，前文："…%s"）' % (mx[0], fmt(mx[1]), mx[2]))
        print('\n  停顿明细（按长度排序，前 10）')
        for g, t, prev in gaps_sorted[:10]:
            mark = '🔴' if g >= LONG_PAUSE else '🟡'
            print('   %s %.1f 秒 @ %s   前文："…%s"' % (mark, g, fmt(t), prev))

    # 逐句
    print('\n  逐句（⚠️ = 该句前后有长停顿）')
    for i, s in enumerate(segs):
        pre = ''
        if i > 0:
            g = s.start - segs[i - 1].end
            if g >= LONG_PAUSE:
                pre = '⚠️ 停 %.1fs → ' % g
        print('   %s%s' % (pre, s.text.strip()))

    print('\n文件：')
    print('  音频 %s' % src)
    print('  文本 %s' % txt)
    print('  字幕 %s' % srt)

    # ---------- 留存指标，供 progress.py 读 ----------
    try:
        import json as _json
        logp = os.path.join(OUTDIR, '_metrics.json')
        hist = []
        if os.path.exists(logp):
            hist = _json.load(open(logp, encoding='utf-8'))
        n_pause = len(sig)
        top = sig[0] if sig else (0, 0)
        inner_sil = sum(e - s for s, e in inner)
        rec = {
            'tag': tag, 'at': datetime.now().strftime('%Y-%m-%d %H:%M:%S'),
            'span_sec': round(span, 1), 'voiced_sec': round(vt, 1),
            'silence_pct': round(100 * (span - vt) / max(span, 0.1), 1),
            'pause_n': n_pause,
            'pause_avg_gap_sec': round(span / max(1, n_pause), 1),
            'pause_max_sec': round(top[1] - top[0], 1) if sig else 0,
            'words': words, 'wpm_voiced': round(words / max(vt, 0.1) * 60),
            'wpm_overall': round(words / max(span, 0.1) * 60),
            'first_word_delay_sec': round(first, 1),
        }
        hist.append(rec)
        _json.dump(hist, open(logp, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
        print('  指标已追加到 %s（共 %d 条）' % (logp, len(hist)))
    except Exception as e:
        print('  ⚠️ 指标留存失败（不影响本次分析）:', e)


if __name__ == '__main__':
    main()
