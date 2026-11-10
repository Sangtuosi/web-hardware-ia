import { defineConfig } from 'astro/config';
import { SITE } from './src/lib/site.js';

export default defineConfig({ site: SITE.url, trailingSlash: 'ignore' });
