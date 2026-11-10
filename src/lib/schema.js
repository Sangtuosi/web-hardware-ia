import { SITE } from './site.js';

export const abs = (path) => new URL(path, SITE.url).href;

export const breadcrumbs = (items) => ({
  '@context': 'https://schema.org',
  '@type': 'BreadcrumbList',
  itemListElement: items.map(([href, name], i) => ({ '@type': 'ListItem', position: i + 1, name, item: abs(href) })),
});

export const faqPage = (items) => ({
  '@context': 'https://schema.org',
  '@type': 'FAQPage',
  mainEntity: items.map(([q, a]) => ({ '@type': 'Question', name: q, acceptedAnswer: { '@type': 'Answer', text: a } })),
});

export const itemList = (productos) => ({
  '@context': 'https://schema.org',
  '@type': 'ItemList',
  itemListElement: productos.map((p, i) => ({ '@type': 'ListItem', position: i + 1, url: abs(`/hardware/${p.slug}/`), name: p.nombre })),
});
