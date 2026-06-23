const { test, expect } = require('@playwright/test');

const STUDENT_FILE = process.env.STUDENT_FILE;
const BASE_URL = 'http://127.0.0.1:8080';

// 採点用のユニークな運勢メッセージ。
// 学生が「実際に fetch して、返ってきた値を表示している」ことを保証するための合言葉。
// （ハードコードでは絶対に一致しない文字列にしておく）
const SENTINEL =
  'AUTO_GRADE_HOROSCOPE_合言葉_今日のあなたは最高にラッキー_xK7q9z';

test.beforeAll(() => {
  if (!STUDENT_FILE) throw new Error('STUDENT_FILE 環境変数が設定されていません');
});

function resolveUrl() {
  return `${BASE_URL}/${STUDENT_FILE}`;
}

// 中継サーバー（先生のロリポップ /horoscope/）への fetch を横取りし、
// 固定のレスポンスを返す。
// → 外部の占いAPIは不安定／CORS でブロックされるため、CI では本物を叩かない。
//   こうすることで「学生の fetch コードが正しいか」だけを安定して判定できる。
function mockHoroscope(page, captured) {
  return page.route('**/horoscope/**', async (route) => {
    const url = new URL(route.request().url());
    captured.hit = true;
    captured.sign = url.searchParams.get('sign');
    captured.day = url.searchParams.get('day');
    await route.fulfill({
      status: 200,
      // fetch 元（http-server:8080）とオリジンが違うので CORS 許可を付ける
      headers: { 'Access-Control-Allow-Origin': '*' },
      contentType: 'application/json; charset=utf-8',
      body: JSON.stringify({
        data: {
          date: '2026-06-23',
          period: 'daily',
          sign: captured.sign || 'Aries',
          horoscope: SENTINEL,
        },
      }),
    });
  });
}

async function selectSignAndFetch(page, signValue) {
  // <select> を選ぶ（change イベント）だけで取得が走る
  await page.selectOption('#sign', signValue);
}

test('星座を選ぶと取得した運勢が .result に表示される', async ({ page }) => {
  const captured = {};
  await mockHoroscope(page, captured);
  await page.goto(resolveUrl());

  await selectSignAndFetch(page, 'Aries');

  // fetch → json → return → 描画 が動いていれば SENTINEL が表示される
  await expect(page.locator('.result')).toContainText(SENTINEL);
  expect(captured.hit, 'fetch が中継サーバーに飛んでいません').toBe(true);
});

test('選んだ星座が sign パラメータに、day=TODAY が付いて送られる', async ({ page }) => {
  const captured = {};
  await mockHoroscope(page, captured);
  await page.goto(resolveUrl());

  // 既定値(Aries)以外を選び、引数 sign がちゃんと使われているか確認
  await selectSignAndFetch(page, 'Leo');

  await expect(page.locator('.result')).toContainText(SENTINEL);
  expect(captured.sign, 'sign パラメータが選んだ星座になっていません').toBe('Leo');
  expect(captured.day, 'day=TODAY が付いていません').toBe('TODAY');
});

test('コンソールエラーが出ていない', async ({ page }) => {
  const captured = {};
  const errors = [];
  page.on('pageerror', (err) => errors.push(String(err)));
  page.on('console', (msg) => {
    if (msg.type() !== 'error') return;
    // 演出用の Motion（CDN）の読み込み失敗は課題と無関係なので無視する。
    // → CDN が落ちていても、学生の fetch コードの判定は影響を受けない。
    const url = (msg.location() && msg.location().url) || '';
    if (url.includes('cdn.jsdelivr.net') || /jsdelivr|motion/i.test(msg.text())) return;
    errors.push(msg.text());
  });
  await mockHoroscope(page, captured);
  await page.goto(resolveUrl());

  await selectSignAndFetch(page, 'Aries');
  await expect(page.locator('.result')).toContainText(SENTINEL);

  expect(errors, errors.join('\n')).toEqual([]);
});
