// 全页冒烟：App.vue 动了导航，确认各页仍能加载且高亮正确
import { createRequire } from 'node:module'
const require = createRequire('/path/to/materials')
const puppeteer = require('puppeteer-core')

const b = await puppeteer.launch({
  executablePath: '/path/to/materials',
  headless: 'new', args: ['--no-sandbox'],
})
const p = await b.newPage()
await p.setViewport({ width: 1600, height: 1000 })
const errs = []
p.on('pageerror', (e) => errs.push('PAGEERROR ' + e.message))
p.on('console', (m) => { if (m.type() === 'error' && !m.text().includes('404')) errs.push('CONSOLE ' + m.text().slice(0, 120)) })

const ROUTES = ['home', 'dashboard', 'courses', 'teacher', 'cards', 'reader', 'shows', 'materials', 'dict', 'notes']
for (const r of ROUTES) {
  await p.goto('http://127.0.0.1:5174/#/' + r, { waitUntil: 'networkidle2' })
  await new Promise((x) => setTimeout(x, 1600))
  const info = await p.evaluate(() => ({
    h1: (document.querySelector('h1') || {}).innerText || '',
    navOn: [...document.querySelectorAll('.nav.on')].map((x) => x.innerText.replace(/\s+/g, '').trim()).join(','),
    txt: document.body.innerText.replace(/\s+/g, ' ').length,
  }))
  console.log(`  ${r.padEnd(10)} h1=${String(info.h1).padEnd(12)} 高亮=${String(info.navOn).padEnd(12)} 文本 ${info.txt} 字`)
}
console.log('\n报错:', errs.length ? [...new Set(errs)].slice(0, 5).join('\n') : '(无)')
await b.close()
