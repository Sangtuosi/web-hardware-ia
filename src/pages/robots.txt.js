import { SITE } from '../lib/site.js';
export function GET() {
  return new Response(`User-agent: *\nAllow: /\n\nSitemap: ${new URL('/sitemap.xml', SITE.url).href}\n`);
}
