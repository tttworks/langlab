#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
通用素材导入器：把 SRT / TXT 逐字稿导入 langlab 的 materials + segments。

用法：
  python import_material.py <文件> --course ja-business --lang ja-JP --title "标题"
      [--video <视频路径>] [--meta '{"k":"v"}'] [--type srt|txt] [--dry]

特性：
  - 幂等：同名同语言的素材已存在 → 删除旧 segments 后重建
  - 自动从 SRT 解析时间轴；TXT 支持 `[mm:ss] 内容` 或 `[hh:mm:ss] 内容` 前缀
  - **同时补建 card_progress**（此处不建卡，仅素材；卡由批注/卡片流程产生）

⚠️ 已知坑：`backend/scripts/` 下不要放名为 tokenize.py 的脚本（会遮蔽 stdlib）。
"""
import argparse
import os
import re
import sqlite3
import json
from datetime import datetime

DB = r'/path/to/langlab\backend/data/langlab.sqlite'
NOW = datetime.now().strftime('%Y-%m-%d %H:%M:%S')


def parse_srt(path):
    raw = open(path, encoding='utf-8-sig', errors='ignore').read().replace('\r\n', '\n')
    out = []
    for blk in re.split(r'\n\s*\n', raw):
        lines = [l.strip() for l in blk.splitlines() if l.strip()]
        if len(lines) < 2:
            continue
        mt = next((l for l in lines if '-->' in l), None)
        if not mt:
            continue
        a, b = [x.strip() for x in mt.split('-->')]

        def sec(t):
            t = t.replace(',', '.')
            p = t.split(':')
            return int(p[0]) * 3600 + int(p[1]) * 60 + float(p[2]) if len(p) == 3 else float(p[0]) * 60 + float(p[1])

        txt = ' '.join(l for l in lines if '-->' not in l and not re.match(r'^\d+$', l))
        if txt:
            out.append((sec(a), sec(b), txt))
    return out


def parse_txt(path):
    """支持 `[00:12] 句子` / `[00:01:02] 句子` / 纯行"""
    out = []
    for ln in open(path, encoding='utf-8-sig', errors='ignore'):
        ln = ln.strip()
        if not ln:
            continue
        m = re.match(r'^\[(\d{1,2}):(\d{2})(?::(\d{2}))?\]\s*(.+)$', ln)
        if m:
            a, b, c, t = m.groups()
            sec = int(a) * 3600 + int(b) * 60 + int(c) if c else int(a) * 60 + int(b)
            out.append((float(sec), None, t.strip()))
        else:
            out.append((None, None, ln))
    return out


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('file')
    ap.add_argument('--course', required=True)
    ap.add_argument('--lang', required=True)
    ap.add_argument('--title', required=True)
    ap.add_argument('--video', default=None)
    ap.add_argument('--meta', default=None)
    ap.add_argument('--type', default=None)
    ap.add_argument('--author', default=None)
    ap.add_argument('--dry', action='store_true')
    a = ap.parse_args()

    if not os.path.exists(a.file):
        raise SystemExit('文件不存在: ' + a.file)
    ext = (a.type or os.path.splitext(a.file)[1].lstrip('.')).lower()
    segs = parse_srt(a.file) if ext == 'srt' else parse_txt(a.file)
    if not segs:
        raise SystemExit('未解析出任何段落')

    total_chars = sum(len(s[2]) for s in segs)
    con = sqlite3.connect(DB)
    cur = con.cursor()

    cur.execute('select id from materials where title=? and lang_code=?', (a.title, a.lang))
    row = cur.fetchone()
    if row:
        mid = row[0]
        cur.execute('delete from segments where material_id=?', (mid,))
        cur.execute('update materials set course_id=?, type=?, video_path=?, meta=?,'
                    ' word_count=?, segment_count=? where id=?',
                    (a.course, ext, a.video, a.meta, total_chars, len(segs), mid))
        action = 'updated'
    else:
        cur.execute('insert into materials (course_id,lang_code,type,title,author,source,status,'
                    'word_count,segment_count,created_at,video_path,meta)'
                    ' values (?,?,?,?,?,?,?,?,?,?,?,?)',
                    (a.course, a.lang, ext, a.title, a.author, 'file', 'ready',
                     total_chars, len(segs), NOW, a.video, a.meta))
        mid = cur.lastrowid
        action = 'created'

    rows = []
    for i, (st, en, txt) in enumerate(segs, 1):
        rows.append((mid, i, 's%d' % i, txt, len(txt), '', st, en, None, None))
    cur.executemany('insert into segments (material_id,seq,locator,text,char_count,tokens,'
                    'start_sec,end_sec,speaker,translation) values (?,?,?,?,?,?,?,?,?,?)', rows)

    if a.dry:
        con.rollback()
        print('[DRY] %s material#%s  %d 段 / %d 字' % (action, mid, len(segs), total_chars))
    else:
        con.commit()
        print('%s material#%d  %d 段 / %d 字  | 课程=%s 语言=%s' %
              (action, mid, len(segs), total_chars, a.course, a.lang))
        print('  标题: %s' % a.title)
        if a.video:
            print('  视频: %s' % a.video)
    con.close()


if __name__ == '__main__':
    main()
