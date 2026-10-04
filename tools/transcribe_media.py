#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
本地 faster-whisper 转写（音视频 → 带时间轴的 SRT + 纯文本）。

用途：把会议录音 / 演讲视频转成可入库的逐字稿，替换掉质量差的在线 ASR（如豆包）。

用法：
  python transcribe_media.py 输入文件 [更多文件...]
      --lang en|ja|zh       指定语言；不给则自动检测
      --model <目录或名>     默认用本地 CT2 模型（见 MODEL_DIR）
      --outdir <目录>        默认与输入同目录
      --beam 5
      --vad                 开启 VAD 静音过滤（会议录音推荐）

输出：<同名>.srt（带时间轴）、<同名>.txt（每行一句，便于切段入库）
"""
import argparse
import os
import subprocess
import sys
import time
import wave

import numpy as np

MODEL_DIR = r'D:\WorkBuddyDatas\2026-09-18-17-45-50\tmp_junli\model_small'
FFMPEG = r'D:\Program Files\ffmpeg-7.1.1-full_build\bin\ffmpeg.exe'


def ts(sec: float, srt: bool = True) -> str:
    h = int(sec // 3600)
    m = int(sec % 3600 // 60)
    s = sec % 60
    if srt:
        # ⚠️ 括号不能省：% 的优先级高于 .replace，否则 .replace 会绑到元组上
        return ('%02d:%02d:%06.3f' % (h, m, s)).replace('.', ',')
    return '%02d:%02d:%02d' % (h, m, int(s))


def read_wav_16k_mono(path: str) -> np.ndarray:
    """
    自己读 16k 单声道 wav → float32 numpy。
    ⚠️ 必须绕过 faster-whisper 的 decode_audio：本机 faster-whisper 1.2.1 调
    `av.open(..., metadata_errors="ignore")`，而已装的 PyAV 19 没有这个参数
    → TypeError: open() got an unexpected keyword argument 'metadata_errors'。
    传 numpy 数组时 faster-whisper 不会再走那条解码路径。
    """
    with wave.open(path, 'rb') as w:
        if w.getframerate() != 16000 or w.getnchannels() != 1 or w.getsampwidth() != 2:
            raise ValueError('需要 16k/单声道/16bit wav，实际 %d/%d/%d'
                             % (w.getframerate(), w.getnchannels(), w.getsampwidth()))
        raw = w.readframes(w.getnframes())
    return np.frombuffer(raw, dtype=np.int16).astype(np.float32) / 32768.0


def to_wav(src: str, out_dir: str) -> str:
    """统一转 16k 单声道 wav（whisper 最佳输入）"""
    base = os.path.splitext(os.path.basename(src))[0]
    dst = os.path.join(out_dir, base + '.16k.wav')
    if os.path.exists(dst) and os.path.getsize(dst) > 0:
        print('  [wav 已存在] %s' % dst)
        return dst
    cmd = [FFMPEG, '-v', 'error', '-y', '-i', src, '-vn', '-ac', '1', '-ar', '16000',
           '-c:a', 'pcm_s16le', dst]
    print('  抽取音频 →', os.path.basename(dst))
    subprocess.run(cmd, check=True)
    return dst


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('inputs', nargs='+')
    ap.add_argument('--lang', default=None)
    ap.add_argument('--model', default=MODEL_DIR)
    ap.add_argument('--outdir', default=None)
    ap.add_argument('--beam', type=int, default=5)
    ap.add_argument('--vad', action='store_true')
    a = ap.parse_args()

    from faster_whisper import WhisperModel
    print('加载模型: %s' % a.model)
    model = WhisperModel(a.model, device='cpu', compute_type='int8')

    for src in a.inputs:
        if not os.path.exists(src):
            print('!! 不存在:', src); continue
        out_dir = a.outdir or os.path.dirname(src) or '.'
        os.makedirs(out_dir, exist_ok=True)
        base = os.path.splitext(os.path.basename(src))[0]
        srt_path = os.path.join(out_dir, base + '.srt')
        txt_path = os.path.join(out_dir, base + '.txt')

        ext = os.path.splitext(src)[1].lower()
        wav = src if ext == '.wav' else to_wav(src, out_dir)

        print('转写: %s  (lang=%s, vad=%s)' % (os.path.basename(src), a.lang or 'auto', a.vad))
        t0 = time.time()
        audio = read_wav_16k_mono(wav)          # ← 传数组，绕开 av 解码的不兼容
        print('  音频 %.1f 秒' % (len(audio) / 16000.0))
        segs, info = model.transcribe(
            audio, language=a.lang, beam_size=a.beam,
            vad_filter=a.vad,
            vad_parameters=dict(min_silence_duration_ms=500) if a.vad else None,
        )
        print('  检测语言=%s 概率=%.2f 时长=%.1fs' % (info.language, info.language_probability, info.duration))

        n = 0
        with open(srt_path, 'w', encoding='utf-8', newline='') as fs, \
             open(txt_path, 'w', encoding='utf-8', newline='') as ft:
            for s in segs:
                n += 1
                txt = s.text.strip()
                fs.write('%d\r\n%s --> %s\r\n%s\r\n\r\n' % (
                    n, ts(s.start), ts(s.end), txt))
                ft.write('[%s] %s\r\n' % (ts(s.start, srt=False), txt))
                if n % 50 == 0:
                    print('   … %d 段 (%.0fs)' % (n, time.time() - t0))
        print('  完成：%d 段，用时 %.1fs' % (n, time.time() - t0))
        print('   SRT: %s' % srt_path)
        print('   TXT: %s' % txt_path)


if __name__ == '__main__':
    main()
