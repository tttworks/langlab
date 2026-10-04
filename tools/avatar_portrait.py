#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
半身像处理：竖版原图 → 4:5 头像卡（保留头顶留白 + 肩胸）

为什么不能用「角落取背景色」：生成图的背景是**渐变**，角落色和中间对不上。
改用**逐行从左右边缘估背景**（左右 5% 几乎必是背景）→ 消掉竖向渐变 → 得到前景蒙版，
再取「头顶第一行」定位构图。

⚠️ 这套定位依赖「人物与背景有可分辨的色差」。如果背景与人物质感接近
   （比如奶油粉背景 + 浅肤色），检测会失真 —— 生成时请用与人物分离度高的背景色。
"""
import os

import numpy as np
from PIL import Image

TARGET_RATIO = 4 / 5     # 卡片宽:高，与 TeacherAvatar.vue 的 176×220 一致
HEAD_AT = 0.11           # 头顶落在卡片高度的 11% 处（上方留一点呼吸空间）
OUT_W = 440


def foreground_rows(im, thresh=42, edge=0.05, surface=False):
    """逐行前景占比。
    背景常是「竖向 + 横向」双向渐变。两级做法：
      · surface=False：对每行按左右边缘色拟合**线性**渐变（快，应付线性渐变够）
      · surface=True ：对整圈边缘像素拟合 **二维二次曲面**（能吃掉任意平滑渐变，
                        斜向渐变、径向渐变都能处理）
    """
    a = np.asarray(im.convert('RGB'), dtype=np.float32)
    h, w, _ = a.shape

    if surface:
        e = max(6, int(min(h, w) * edge))
        mask = np.zeros((h, w), dtype=bool)
        mask[:e, :] = mask[-e:, :] = True
        mask[:, :e] = mask[:, -e:] = True
        ys, xs = np.nonzero(mask)
        # 设计矩阵：1, x, y, x², xy, y²（归一化到 0~1，避免量级差太大）
        X = np.stack([np.ones_like(xs), xs / w, ys / h,
                      (xs / w) ** 2, (xs / w) * (ys / h), (ys / h) ** 2], axis=1).astype(np.float64)
        px = a[ys, xs].astype(np.float64)                       # (N,3)
        coef, *_ = np.linalg.lstsq(X, px, rcond=None)           # (6,3)
        yy, xx = np.mgrid[0:h, 0:w]
        Xf = np.stack([np.ones_like(xx), xx / w, yy / h,
                       (xx / w) ** 2, (xx / w) * (yy / h), (yy / h) ** 2], axis=-1).astype(np.float64)
        bg = (Xf @ coef).astype(np.float32)                     # (h,w,3)
    else:
        e = max(8, int(w * edge))
        bl = a[:, :e].mean(axis=1)
        br = a[:, -e:].mean(axis=1)
        t = np.linspace(0.0, 1.0, w)[None, :, None]
        bg = bl[:, None, :] * (1 - t) + br[:, None, :] * t

    dist = np.sqrt(((a - bg) ** 2).sum(axis=2))
    return (dist > thresh).mean(axis=1), a, w, h


def head_top(rows, thr=None):
    """头顶第一行。
    先做 1% 高度的滑动平均：真头是**持续**一片区域，而背景渐变造成的误检只是零星几行。
    阈值同时参考整条曲线的 95 分位，避免绝对阈值在不同构图上失准。"""
    h = len(rows)
    k = max(3, int(h * 0.01))
    ker = np.ones(k) / k
    sm = np.convolve(rows, ker, mode='same')
    t = thr if thr is not None else max(0.14, float(np.percentile(rows, 95)) * 0.30)
    for i, v in enumerate(sm):
        if v > t:
            return i
    return 0


def process(src, dst, quality=86, debug=False):
    im = Image.open(src).convert('RGB')
    rows, a, w, h = foreground_rows(im, surface=True)   # 二次曲面背景模型：能吃掉斜向/径向渐变
    top = head_top(rows)
    detected = top
    # 合理性钳制：人像的头顶不可能贴着画面上缘，也不可能低过 35%。
    # 超出范围说明检测被背景干扰 → 按经验值 10% 兜底，别盲目采信。
    if not (0.04 * h <= top <= 0.35 * h):
        top = int(0.10 * h)

    ch = int(round(w / TARGET_RATIO))          # 窗口高（4:5，宽取满）
    y0 = int(round(top - HEAD_AT * ch))        # 让头顶落在卡片 11% 处
    y0 = max(0, min(y0, h - ch))
    im = im.crop((0, y0, w, y0 + ch))
    im = im.resize((OUT_W, int(round(OUT_W / TARGET_RATIO))), Image.LANCZOS)

    os.makedirs(os.path.dirname(dst), exist_ok=True)
    im.save(dst, 'WEBP', quality=quality, method=6)
    info = {'src': (w, h), 'detected_pct': round(detected / h * 100, 1),
            'used_pct': round(top / h * 100, 1),
            'clamped': not (0.04 * h <= detected <= 0.35 * h),
            'crop_y': (y0, y0 + ch), 'head_in_card_pct': round((top - y0) / ch * 100, 1),
            'kb': round(os.path.getsize(dst) / 1024, 1), 'out': (OUT_W, int(round(OUT_W / TARGET_RATIO)))}
    if debug:
        print(f"       前景行占比 每5%: " + ' '.join(f'{rows[int(h*i/20):int(h*(i+1)/20)].mean():.2f}' for i in range(20)))
    return info


if __name__ == '__main__':
    jobs = [
        ('sofia', r'D:\tmp_avatars2\Vertical_portrait_photo__waist_2026-10-03T16-24-34.png'),
        ('ethan', r'D:\tmp_avatars2\Vertical_portrait_photo__waist_2026-10-03T16-24-48.png'),
    ]
    for cid, src in jobs:
        if not os.path.isfile(src):
            print(f'{cid}: 缺原图 {src}')
            continue
        info = process(src, rf'/path/to/langlab\frontend\public/avatars\{cid}/idle.webp', debug=True)
        tag = '（检测值不可靠，已用经验值）' if info['clamped'] else ''
        print(f"{cid:7s} 原图 {info['src'][0]}x{info['src'][1]}  "
              f"头顶检测 {info['detected_pct']}% → 采用 {info['used_pct']}%{tag}  "
              f"裁 y{info['crop_y']}  头顶落在卡片 {info['head_in_card_pct']}%  "
              f"输出 {info['out'][0]}x{info['out'][1]}  {info['kb']} KB")
