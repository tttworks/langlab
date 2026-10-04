#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
形象去背：把「纯色/近平背景」的人像抠成带 alpha 的透明图，并裁成 3:4 竖版。

为什么不用生成平台的 transparent 参数：实测不生效（返回的还是 RGB）。
为什么不用 rembg 之类的分割模型：要额外装依赖 + 下模型；而这里的背景本来就够干净，
**色键 + 软边 + 去色边**足够，且完全可控。

关键三步：
  1. 背景色从「外圈中位色」估（比四角更稳，能容忍轻微渐变）
  2. alpha 用双阈值做软过渡（避免锯齿），不是硬切
  3. **去色边**：半透明边缘像素 = 前景与背景的混合，直接留着会有底色描边；
     用反预乘 (px - bg*(1-a)) / a 还原真实前景色 —— 这一步不做，抠图会有一圈粉边

用法：python avatar_cutout.py <原图> <输出webp> [--ratio 0.75] [--head-gap 0.07]
"""
import argparse
import os

import numpy as np
from PIL import Image


def estimate_bg(a, edge=0.06):
    """背景色：取外圈像素的中位数（容忍轻微渐变，比单取四角稳）"""
    h, w, _ = a.shape
    e = max(4, int(min(h, w) * edge))
    ring = np.concatenate([a[:e].reshape(-1, 3), a[-e:].reshape(-1, 3),
                           a[:, :e].reshape(-1, 3), a[:, -e:].reshape(-1, 3)])
    return np.median(ring, axis=0)


def matte(a, bg, t_lo, t_hi):
    """色键 alpha：< t_lo 全透、> t_hi 全实、中间线性过渡"""
    d = np.sqrt(((a - bg) ** 2).sum(axis=2))
    al = np.clip((d - t_lo) / max(1e-6, (t_hi - t_lo)), 0.0, 1.0)
    return al


def unpremultiply(a, bg, al, floor=0.03):
    """去色边：把半透明像素里混进来的背景色反算掉"""
    A = np.repeat(al[:, :, None], 3, axis=2)
    fg = (a - bg[None, None, :] * (1.0 - A)) / np.maximum(A, floor)
    out = np.where(A > floor, fg, a)
    return np.clip(out, 0, 255)


def subject_bbox(al, thr=0.35):
    ys, xs = np.where(al > thr)
    if not len(ys):
        return None
    return xs.min(), ys.min(), xs.max() + 1, ys.max() + 1


def alpha_from_rembg(src, tighten=24):
    """用 rembg（U²-Net）算 alpha。
    ⚠️ rembg 的不透明值是 **254** 不是 255，所以「中间值占比高」是假象 ——
       判断质量要看「背景条带是否为 0」和「人体内部是否 ≈254」，别只看直方图。
    tighten：把软边带往 0 拉一点（软边里混着背景色，收紧后描边感更弱）。"""
    from rembg import remove
    im = Image.open(src).convert('RGB')
    out = np.asarray(remove(im).convert('RGBA')).astype(np.float32)
    if tighten > 0:
        a = out[:, :, 3]
        out[:, :, 3] = np.clip((a - tighten) / (255.0 - tighten), 0, 1) * 255.0
    return out[:, :, :3], out[:, :, 3] / 255.0


def process(src, dst, ratio=2 / 3, head_gap=0.06, engine='rembg',
            out_w=380, t_lo=55, t_hi=115, quality=88, debug=False):
    im = Image.open(src).convert('RGB')
    a = np.asarray(im, dtype=np.float32)
    h, w, _ = a.shape

    if engine == 'rembg':
        rgb, al = alpha_from_rembg(src)
    else:
        bg = estimate_bg(a)
        al = matte(a, bg, t_lo, t_hi)
        rgb = unpremultiply(a, bg, al)

    bb = subject_bbox(al)
    if bb is None:
        raise RuntimeError('没找到主体（背景可能不纯）')
    x0, y0, x1, y1 = bb
    subj_h, subj_w = y1 - y0, x1 - x0

    # 目标窗口（源图坐标系）：以头顶为锚留白，按 ratio 定宽，**允许越界**（最后用透明补）
    win_h = max(int(round(subj_h / (1 - head_gap))), subj_h)
    win_w = int(round(win_h * ratio))
    top = y0 - int(round(head_gap * win_h))
    cx = (x0 + x1) // 2
    left = cx - win_w // 2

    rgba = np.dstack([rgb, al * 255.0]).astype(np.uint8)
    canvas = np.zeros((win_h, win_w, 4), dtype=np.uint8)      # 全透明底
    sx0, sy0 = max(0, left), max(0, top)
    sx1, sy1 = min(w, left + win_w), min(h, top + win_h)
    dx0, dy0 = sx0 - left, sy0 - top
    canvas[dy0:dy0 + (sy1 - sy0), dx0:dx0 + (sx1 - sx0)] = rgba[sy0:sy1, sx0:sx1]

    out = Image.fromarray(canvas, 'RGBA').resize(
        (out_w, int(round(out_w / ratio))), Image.LANCZOS)
    os.makedirs(os.path.dirname(dst), exist_ok=True)
    out.save(dst, 'WEBP', quality=quality, method=6)

    info = {
        'engine': engine, 'src': (w, h),
        'subject_bbox': (int(x0), int(y0), int(x1), int(y1)),
        'subject_center_x_pct': round(cx / w * 100, 1),
        'window': (left, top, win_w, win_h),
        'padded': (win_w > w or win_h > h),
        'out': out.size, 'kb': round(os.path.getsize(dst) / 1024, 1),
    }
    if debug:
        print(f"       引擎 {engine}  主体框 {info['subject_bbox']}  主体横向居中在 {info['subject_center_x_pct']}%")
        print(f"       窗口 {info['window']}  需补透明边: {info['padded']}")
    return info


if __name__ == '__main__':
    ap = argparse.ArgumentParser()
    ap.add_argument('src')
    ap.add_argument('dst')
    ap.add_argument('--ratio', type=float, default=3 / 4)
    ap.add_argument('--head-gap', type=float, default=0.07)
    ap.add_argument('--q', type=int, default=88)
    ap.add_argument('--engine', default='rembg', choices=['rembg', 'chroma'])
    ap.add_argument('--no-tighten', action='store_true')
    a = ap.parse_args()
    info = process(a.src, a.dst, ratio=a.ratio, head_gap=a.head_gap, engine=a.engine,
                   quality=a.q, debug=True)
    print(f"  窗口 {info['window']} → 输出 {info['out'][0]}x{info['out'][1]}  {info['kb']} KB")
