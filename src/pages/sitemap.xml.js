import productos from '../data/productos.json';
import categorias from '../data/categorias.json';
import { SITE } from '../lib/site.js';

export function GET() {
  const hoy = new Date().toISOString().slice(0, 10);
  const urls = [
    ['/', hoy], ['/comparador/', hoy], ['/calculadora-vram/', hoy], ['/como-elegimos/', hoy], ['/aviso-afiliacion/', hoy],
    ...categorias.map((c) => [`/${c.slug}/`, hoy]),
    ...productos.map((p) => [`/hardware/${p.slug}/`, p.revisado]),
  ];
  const body = `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n${urls
    .map(([u, d]) => `  <url><loc>${new URL(u, SITE.url).href}</loc><lastmod>${d}</lastmod></url>`)
    .join('\n')}\n</urlset>\n`;
  return new Response(body, { headers: { 'Content-Type': 'application/xml' } });
}
