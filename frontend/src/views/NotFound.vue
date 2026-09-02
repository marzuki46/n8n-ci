<template>
  <div class="not-found">
    <div class="bg-grid"></div>
    <div class="bg-glow a"></div>
    <div class="bg-glow b"></div>
    <div class="container">
      <div class="code">404</div>
      <div class="line"></div>
      <h1>Halaman Tidak Ditemukan</h1>
      <p>Sepertinya halaman yang kamu cari sudah dipindahkan atau tidak tersedia.</p>
      <div class="actions">
        <router-link to="/" class="btn btn-primary">
          <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-4 0a1 1 0 01-1-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 01-1 1"/></svg>
          Kembali ke Dashboard
        </router-link>
        <button class="btn btn-ghost" @click="$router.back()">Halaman Sebelumnya</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.not-found {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
  overflow: hidden;
  background: linear-gradient(135deg, #0f0f23 0%, #1a1a3e 50%, #0f0f23 100%);
  color: #e2e8f0;
  font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
}
.bg-grid {
  position: fixed; inset: 0;
  background-image:
    linear-gradient(rgba(99,102,241,.06) 1px, transparent 1px),
    linear-gradient(90deg, rgba(99,102,241,.06) 1px, transparent 1px);
  background-size: 60px 60px;
  pointer-events: none;
}
.bg-glow {
  position: fixed;
  width: 600px; height: 600px;
  border-radius: 50%;
  filter: blur(120px);
  opacity: .12;
  pointer-events: none;
}
.bg-glow.a { background: #6366f1; top: -200px; left: -100px; }
.bg-glow.b { background: #ec4899; bottom: -200px; right: -100px; }
.container {
  position: relative; z-index: 1;
  text-align: center;
  padding: 2rem;
  max-width: 560px;
}
.code {
  font-size: clamp(6rem, 15vw, 10rem);
  font-weight: 800;
  line-height: 1;
  background: linear-gradient(135deg, #6366f1, #a78bfa, #ec4899);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
  margin-bottom: 1rem;
  user-select: none;
}
.line {
  width: 80px; height: 3px;
  background: linear-gradient(90deg, #6366f1, #ec4899);
  border-radius: 2px;
  margin: 0 auto 1.5rem;
}
h1 {
  font-size: 1.5rem;
  font-weight: 700;
  margin-bottom: .5rem;
  color: #f8fafc;
}
p {
  font-size: 1rem;
  color: #94a3b8;
  line-height: 1.6;
  margin-bottom: 2rem;
}
.actions {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: .75rem;
  flex-wrap: wrap;
}
.btn {
  display: inline-flex;
  align-items: center;
  gap: .5rem;
  padding: .75rem 1.75rem;
  border-radius: 8px;
  text-decoration: none;
  font-weight: 600;
  font-size: .95rem;
  transition: all .2s;
  cursor: pointer;
  border: none;
}
.btn-primary {
  background: linear-gradient(135deg, #6366f1, #818cf8);
  color: #fff;
  box-shadow: 0 4px 15px rgba(99,102,241,.3);
}
.btn-primary:hover {
  transform: translateY(-1px);
  box-shadow: 0 6px 20px rgba(99,102,241,.4);
}
.btn-ghost {
  background: transparent;
  border: 1px solid rgba(148,163,184,.2);
  color: #94a3b8;
}
.btn-ghost:hover {
  border-color: rgba(148,163,184,.4);
  color: #e2e8f0;
}
</style>
