module.exports = {
  testDir: './tests',
  // 学生の index.html を http:// 経由で配信する（fetch を扱うので file:// は避ける）。
  // 占いAPI（中継サーバー /horoscope/）への通信は tests 側で page.route により
  // 横取りするため、実際のサーバーは不要。
  webServer: {
    command: 'npx http-server . -p 8080 -s -c-1',
    url: 'http://127.0.0.1:8080',
    reuseExistingServer: true,
    timeout: 30_000,
  },
  projects: [{ name: 'chromium', use: { browserName: 'chromium' } }],
  reporter: 'list',
};
