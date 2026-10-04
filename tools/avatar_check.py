#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
形象图检查 + 处理：分析清晰度与主体位置，裁成 512 方形 WebP。

为什么要程序化检查：当前模型读不了图，没法用眼睛验收。
用「高频细节的能量分布」近似判断主体位置（人脸/人物是高频密集区），
用 Laplacian 方差判断清晰度，用四角颜色判断背景是否干净。
"""
import glob
import os
import sys

import numpy as np
from PIL import Image, ImageFilter


def lap_var(gray):
    """Laplacian 方差 —— 越大越清晰（>100 通常算清晰）"""
    a = gray.astype(np.float32)
    k = np.array([[0, 1, 0], [1, -4, 1], [0, 1, 0]], dtype=np.float32)
    # 用 PIL 的 FIND_EDGES 近似，避免手写卷积
    return float(np.var(np.asarray(Image.fromarray(gray).filter(ImageFilter.FIND_EDGES), dtype=np.float32)))


def detail_centroid(gray):
    """高频能量的质心（归一化 0~1）与包围盒"""
    g = Image.fromarray(gray).filter(ImageFilter.FIND_EDGES)
    e = np.asarray(g, dtype=np.float32)
    e = e - e.min()
    if e.sum() <= 0:
        return (0.5, 0.5), (0, 0, 1, 1)
    h, w = e.shape
    ys, xs = np.mgrid[0:h, 0:w]
    cy = float((e * ys).sum() / e.sum() / h)
    cx = float((e * xs).sum() / e.sum() / w)
    # 取能量高的区域（前 12% 像素）作包围盒
    thr = np.percentile(e, 88)
    m = e >= thr
    yy, xx = np.where(m)
    box = (float(xx.min() / w), float(yy.min() / h), float(xx.max() / w), float(yy.max() / h))
    return (cx, cy), box


def corners_rgb(im, n=48):
    a = np.asarray(im.convert('RGB'), dtype=np.float32)
    h, w, _ = a.shape
    cs = [a[:n, :n], a[:n, -n:], a[-n:, :n], a[-n:, -n:]]
    return np.mean([c.mean(axis=(0, 1)) for c in cs], axis=0)


def analyze(path):
    im = Image.open(path).convert('RGB')
    gray = np.asarray(im.convert('L'), dtype=np.uint8)
    (cx, cy), box = detail_centroid(gray)
    return {
        'file': os.path.basename(path),
        'size': im.size,
        'sharpness': round(lap_var(gray), 1),
        'brightness': round(float(gray.mean()), 1),
        'contrast': round(float(gray.std()), 1),
        'subject_center': (round(cx, 3), round(cy, 3)),
        'subject_bbox': tuple(round(v, 3) for v in box),
        'corner_rgb': tuple(int(v) for v in corners_rgb(im)),
        'corner_uniform': round(float(np.std(corners_rgb(im))), 1),
    }


def process(src, dst, out=512, quality=85):
    im = Image.open(src).convert('RGB')
    w, h = im.size
    # 目标方形：以画面中心为基准取正方形（生成时已按方形构图）
    s = min(w, h)
    box = ((w - s) // 2, (h - s) // 2, (w + s) // 2, (h + s) // 2)
    im = im.crop(box).resize((out, out), Image.LANCZOS)
    os.makedirs(os.path.dirname(dst), exist_ok=True)
    im.save(dst, 'WEBP', quality=quality, method=6)
    return os.path.getsize(dst)


if __name__ == '__main__':
    files = sorted(glob.glob(r'D:\tmp_avatars\*.png'))
    for f in files:
        a = analyze(f)
        print('=' * 64)
        for k, v in a.items():
            print(f'  {k:16s} {v}')
        good = (a['sharpness'] > 80 and 40 < a['brightness'] < 220
                and 0.3 < a['subject_center'][0] < 0.7 and 0.3 < a['subject_center'][1] < 0.7)
        print('  → 检查:', '✅ 清晰、主体居中' if good else '⚠️ 有一项不达标，需人工看一眼')
