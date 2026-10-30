// Runs before the app bundle so the saved theme is applied before first paint.
// Kept as an external file (not inline) because the CSP is `script-src 'self'`.
;(function () {
  try {
    var saved = localStorage.getItem('kb_theme')
    var dark =
      saved === 'dark' ||
      (!saved && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)
    if (dark) {
      document.documentElement.setAttribute('data-theme', 'dark')
      var meta = document.querySelector('meta[name="theme-color"]')
      if (meta) meta.setAttribute('content', '#151827')
    }
  } catch (e) {
    /* private mode / storage disabled — fall back to the light theme */
  }
})()
