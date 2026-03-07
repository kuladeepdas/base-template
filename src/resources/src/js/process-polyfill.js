const g = typeof globalThis !== 'undefined' ? globalThis : window;

if (!g.process) {
  g.process = { env: {} };
}

if (!g.process.env) {
  g.process.env = {};
}

if (!g.process.env.NODE_ENV) {
  g.process.env.NODE_ENV = 'production';
}
