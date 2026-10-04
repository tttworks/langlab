#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
把 card_sets.cats 从「手写的意图表」改成「以真实 data.cat 为 key 的标签字典」。
原则：key 必须与 cards.data.cat 的实际值逐一对上；对不上的历史 key 直接丢掉。
这样前端（按真实数据出 chip）+ 后端的标签查表就能永远一致。
用法：python fix_set_cats.py            # 干跑，只报告
     python fix_set_cats.py --apply   # 真正写库
"""
import sqlite3
import json
import os
import sys
import collections

DB = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'data', 'langlab.sqlite')

# 人工定好的标签字典（key = 真实 data.cat 值）
LABELS = {
    'dd-questions': {
        'financials': '财务',
        'market': '市场',
        'product': '产品',
        'governance': '治理',
        'strategy': '战略',
        'legal': '法务',
        'exit': '退出',
    },
    'phrases-deal': {
        'pitch': '路演陈述',
        'contract': '合同 / 条款',
        'pushback': '异议与推回',
        'question': '提问',
        'meeting': '会议',
        'numbers': '数字与指标',
    },
    'phrases-ja': {
        'speech': '演讲 / 致辞',
    },
    'frames-retell': {
        '通知类': '通知 / 条款更新',
        '邀请类': '展会 / 会议',
        '账单 / 报表类': '月报 / 发票',
        '安全提醒类': '2FA / 登录告警',
        '要求 / 请求类': '客户需求 / 催办',
        '⭐ 回复框架': '借对方句式回复',
        '通用「复述骨架」': '兜底骨架',
    },
    'cn-one-to-many': {
        '中文一词多形': '中文一词 → 英文多形',
    },
    'chunks-purdy': {
        '抗压应答': '抗压应答 / 争取时间',
    },
    'terms-asd': {
        '金钱与支付': '金钱与支付',
        '服务与义务': '服务与义务',
        '责任、赔偿与免责（重点组）': '责任、赔偿与免责（重点）',
        '商业与行业术语（这部分才是 Frankie 用的语言）': '商业与行业术语',
        '保密与知识产权': '保密与知识产权',
        '主体与合同结构': '主体与合同结构',
        '争议解决、适用法律与送达': '争议解决 / 适用法律 / 送达',
        '期限、终止与存续': '期限、终止与存续',
        '法律技术词与古英语副词': '法律技术词与古英语副词',
    },
}


def main():
    apply = '--apply' in sys.argv
    conn = sqlite3.connect(DB)
    conn.row_factory = sqlite3.Row

    # 每组的真实 data.cat 分布
    buckets = collections.defaultdict(collections.Counter)
    for r in conn.execute('SELECT set_id, data FROM cards').fetchall():
        try:
            d = json.loads(r['data'])
        except Exception:
            d = {}
        k = d.get('cat')
        if k:
            buckets[r['set_id']][k] += 1

    changed = 0
    for sid, labels in LABELS.items():
        real = buckets.get(sid, collections.Counter())
        # 只保留真实存在的 key
        keep = {k: labels.get(k, k) for k in real}
        for k in labels:
            if k not in real:
                print(f'  [跳过] {sid}: 标签 {k!r} 在数据里 0 条，丢弃')
        # 若真实值没给标签，用原值兜底
        for k in real:
            if k not in keep:
                keep[k] = k
                print(f'  [兜底] {sid}: {k!r} 没有标签，直接用原值')

        old = conn.execute('SELECT cats FROM card_sets WHERE id=?', (sid,)).fetchone()
        oldv = old['cats'] if old else None
        newv = json.dumps(keep, ensure_ascii=False)
        same = (oldv or '') == newv
        print(f'{sid:16s} {"不变" if same else "更新"}  {oldv}  →  {newv}')
        if not same:
            if apply:
                conn.execute('UPDATE card_sets SET cats=? WHERE id=?', (newv, sid))
            changed += 1

    # 顺带报告：哪些组有真实 cat 但 cats 为 null（前端仍能出 chip，只是标签是原值）
    print('\n--- 其余组（不含在 LABELS 里的）真实 cat 分布 ---')
    for sid, cnt in buckets.items():
        if sid in LABELS:
            continue
        row = conn.execute('SELECT cats FROM card_sets WHERE id=?', (sid,)).fetchone()
        if row and row['cats']:
            continue
        if cnt:
            print(f'  {sid:16s} 无标签字典，前端将直接显示原值: {dict(cnt)}')

    if apply:
        conn.commit()
        print(f'\n已提交，改动 {changed} 个卡组')
    else:
        print(f'\n（干跑）预计改动 {changed} 个卡组；加 --apply 真正写入')
    conn.close()


if __name__ == '__main__':
    main()
