#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
TTS 预合成 —— 把卡片里所有「会播的文本」提前合成进服务端缓存，消除点击后 3–5 秒的现场合成等待。

背景：服务端本来就有文件缓存（`tts_path_for()` = sha1(provider|voice|rate|text).mp3），
      命中只要 0.07 秒；但 860 张卡片基本没预合成过，于是每张新卡首次点击都要等一次真合成。
      实测：一句例句首次 4–5.5 秒，命中 0.07 秒。

⚠️ 必须复刻前端的 sayOf / exampleOf（见 frontend/src/pages/Cards.vue），
   否则预热出来的是永远不会被播放的文本。两边改动要同步。

用法：
  python prewarm_tts.py                       # 干跑，只报告要合成多少条
  python prewarm_tts.py --apply               # 真正合成
  python prewarm_tts.py --apply --workers 4   # 并发数（默认 4；Edge 是 websocket，别开太高）
  python prewarm_tts.py --apply --voice en-US-AvaMultilingualNeural
"""
import argparse
import concurrent.futures as cf
import hashlib
import json
import os
import sqlite3
import subprocess
import sys
import time

HERE = os.path.dirname(os.path.abspath(__file__))
BACKEND = os.path.dirname(HERE)
DB = os.path.join(BACKEND, 'data', 'langlab.sqlite')
TTS_DIR = os.path.join(BACKEND, 'data', 'tts')
TTS_PY = os.path.join(HERE, 'tts.py')

# 与前端 useTtsSettings.PREFERRED 保持一致（美式优先）
DEFAULT_VOICE = {
    'en': 'en-US-AvaMultilingualNeural',
    'ja': 'ja-JP-NanamiNeural',
    'zh': 'zh-CN-XiaoxiaoNeural',
    'th': 'th-TH-PremwadeeNeural',
}


def say_of(kind, d):
    """⭐ 复刻 frontend/src/pages/Cards.vue 的 sayOf()"""
    if d.get('say'):
        return d['say']
    if kind in ('word', 'colloc', 'anno'):
        return d.get('w') or ''
    if kind == 'chinglish':
        good = str(d.get('good') or '')
        return good.split('/')[0].strip() or good
    if kind == 'pattern':
        return d.get('eg') or d.get('en') or ''
    if kind == 'concept':
        return d.get('en') or ''
    return d.get('w') or d.get('n') or ''


def example_of(kind, d):
    """⭐ 复刻 Cards.vue 的 exampleOf()：例句与朗读对象不同才值得单独给按钮"""
    en = d.get('en') or d.get('eg') or d.get('quote') or ''
    return en if (en and en != say_of(kind, d)) else ''


def cache_path(provider, voice, rate, text):
    """⭐ 必须与 backend/src/tts.php 的 tts_path_for() 完全一致"""
    key = '%s|%s|%s|%s' % (provider, voice, rate, text)
    return os.path.join(TTS_DIR, hashlib.sha1(key.encode('utf-8')).hexdigest() + '.mp3')


def collect(provider, voice_override, rate='+0%'):
    conn = sqlite3.connect(DB)
    conn.row_factory = sqlite3.Row
    jobs, seen = [], set()
    stats = {'cards': 0, 'say': 0, 'eg': 0, 'skip_short': 0}
    for r in conn.execute('SELECT kind, lang_code, data FROM cards').fetchall():
        try:
            d = json.loads(r['data'] or '{}')
        except Exception:
            continue
        stats['cards'] += 1
        short = str(r['lang_code'] or 'en-US')[:2]
        voice = voice_override or DEFAULT_VOICE.get(short) or DEFAULT_VOICE['en']
        for field, text in (('say', say_of(r['kind'], d)), ('eg', example_of(r['kind'], d))):
            t = str(text or '').strip()
            # 太短的（单个字母之类）合成没意义还占缓存
            if len(t) < 2:
                if t:
                    stats['skip_short'] += 1
                continue
            p = cache_path(provider, voice, rate, t)
            key = (p, t, voice)
            if key in seen:
                continue
            seen.add(key)
            stats[field] += 1
            jobs.append({'path': p, 'tj': timings_path(p), 'text': t, 'voice': voice})
    conn.close()
    return jobs, stats


def timings_path(mp3_path):
    """⭐ 与 PHP 的 tts_timings_path() 一致：xxx.mp3 → xxx.words.json"""
    return mp3_path[:-4] + '.words.json'


def run_one(job):
    # 音频 + 时间戳都在才算完成。
    # ⚠️ 必须一起生成：前端每次播放都会额外请求 /api/tts/timings 画跟读横线，
    #    只预热 mp3 的话，那次请求会触发服务端把整段重合成一遍（2.5–5 秒）。
    if (os.path.isfile(job['path']) and os.path.getsize(job['path']) > 512
            and os.path.isfile(job['tj'])):
        return 'cached', 0.0
    t0 = time.time()
    cmd = [sys.executable, TTS_PY, '--provider', 'edge', '--voice', job['voice'],
           '--text', job['text'], '--out', job['path'], '--rate', '+0%',
           '--timings-out', job['tj']]
    try:
        r = subprocess.run(cmd, capture_output=True, timeout=120)
    except subprocess.TimeoutExpired:
        return 'timeout', time.time() - t0
    if os.path.isfile(job['path']) and os.path.getsize(job['path']) > 512:
        return 'ok', time.time() - t0
    err = (r.stdout or b'')[-200:].decode('utf-8', 'ignore')
    return 'fail:' + err.replace('\n', ' '), time.time() - t0


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--apply', action='store_true')
    ap.add_argument('--provider', default='edge')
    ap.add_argument('--voice', default='')
    ap.add_argument('--workers', type=int, default=4)
    ap.add_argument('--limit', type=int, default=0)
    a = ap.parse_args()

    if not os.path.isdir(TTS_DIR):
        os.makedirs(TTS_DIR, exist_ok=True)
    jobs, stats = collect(a.provider, a.voice)
    def done(j):
        return os.path.isfile(j['path']) and os.path.getsize(j['path']) > 512 and os.path.isfile(j['tj'])
    todo = [j for j in jobs if not done(j)]
    if a.limit:
        todo = todo[:a.limit]

    print('卡片 %d 张 ｜ 词条文本 %d 条 ｜ 例句文本 %d 条 ｜ 太短跳过 %d 条'
          % (stats['cards'], stats['say'], stats['eg'], stats['skip_short']))
    print('去重后共 %d 条（音频+时间戳齐全的算已有）→ 本次要做 %d 条' % (len(jobs), len(todo)))
    if not a.apply:
        print('\n（干跑）加 --apply 真正合成。')
        return 0
    if not todo:
        print('\n全都已经缓存好了，无需合成。')
        return 0

    print('\n开始合成，并发 %d …\n' % a.workers)
    t0 = time.time()
    ok = fail = cached = 0
    fails = []
    with cf.ThreadPoolExecutor(max_workers=a.workers) as ex:
        futs = {ex.submit(run_one, j): j for j in todo}
        for i, f in enumerate(cf.as_completed(futs), 1):
            st, dt = f.result()
            j = futs[f]
            if st == 'ok':
                ok += 1
            elif st == 'cached':
                cached += 1
            else:
                fail += 1
                if len(fails) < 5:
                    fails.append((j['text'][:50], st[:120]))
            if i % 20 == 0 or i == len(todo):
                el = time.time() - t0
                eta = el / i * (len(todo) - i)
                print('  %d/%d  ok=%d fail=%d  已用 %.0fs  预计还要 %.0fs'
                      % (i, len(todo), ok, fail, el, eta), flush=True)

    print('\n完成：成功 %d，失败 %d，本来就有 %d，共 %.0f 秒' % (ok, fail, cached, time.time() - t0))
    for t, e in fails:
        print('  ✗ %s\n    %s' % (t, e))
    print('\n缓存目录 %s，现在共 %d 个文件，%s'
          % (TTS_DIR, len([f for f in os.listdir(TTS_DIR) if f.endswith('.mp3')]),
             _hsize(sum(os.path.getsize(os.path.join(TTS_DIR, f))
                        for f in os.listdir(TTS_DIR) if f.endswith('.mp3')))))
    return 0


def _hsize(n):
    for u in ('B', 'KB', 'MB', 'GB'):
        if n < 1024:
            return '%.1f %s' % (n, u)
        n /= 1024
    return '%.1f TB' % n


if __name__ == '__main__':
    sys.exit(main())
