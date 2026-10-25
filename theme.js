// ใส่ธีม (สว่าง/มืด) ตั้งแต่ก่อนหน้าเว็บแสดงผล กันภาพกะพริบ — ใช้ key เดิม 'site_theme'
(function () {
  var t = 'light';
  try { t = localStorage.getItem('site_theme') === 'dark' ? 'dark' : 'light'; } catch (e) {}
  var h = document.documentElement;
  h.setAttribute('data-theme', t);
  h.setAttribute('data-bs-theme', t);
})();
window.setSiteTheme = function (t) {
  t = (t === 'dark') ? 'dark' : 'light';
  var h = document.documentElement;
  h.setAttribute('data-theme', t);
  h.setAttribute('data-bs-theme', t);
  try { localStorage.setItem('site_theme', t); } catch (e) {}
  var icon = document.getElementById('themeIcon');
  if (icon) icon.className = (t === 'dark') ? 'fas fa-sun' : 'fas fa-moon';
  return t;
};
document.addEventListener('DOMContentLoaded', function () {
  var btn = document.getElementById('themeToggle');
  var cur = document.documentElement.getAttribute('data-theme');
  var icon = document.getElementById('themeIcon');
  if (icon) icon.className = (cur === 'dark') ? 'fas fa-sun' : 'fas fa-moon';
  if (btn) btn.addEventListener('click', function () {
    setSiteTheme(document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
  });
});
