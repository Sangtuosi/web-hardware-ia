# TensorForja (nombre provisional)

Web de afiliación Amazon.es de hardware para IA. Astro estático, pensada para Cloudflare Pages.

    npm install
    npm run dev      # desarrollo
    npm run build    # genera dist/
    npm test         # pruebas de cálculos y datos (sin red)

## Cómo está organizada (patrón pedalada.es)

Una sola fuente de datos genera toda la web:

- `src/data/productos.json`: cada gráfica o equipo (ASIN, VRAM, ancho de banda, consumo, pros, **lo que no**, para quién). Añadir un producto = añadir una entrada.
- `src/data/categorias.json`: categorías por uso, con su intro y FAQ.
- `src/data/modelos.json`: modelos de IA de la calculadora.
- `src/lib/site.js`: nombre, dominio y **tag de afiliado de Amazon** (todo enlace sale de `amazonUrl()`).
- `src/lib/calc.js`: VRAM necesaria, tokens/s estimados, radar y nota IA (fórmulas publicadas en `/como-elegimos/`).

Páginas generadas: portada, `/<categoria>/`, `/hardware/<slug>/`, `/comparador/`, `/calculadora-vram/`, `/como-elegimos/`, `/aviso-afiliacion/`, `sitemap.xml` y `robots.txt`.

Estilo: neo-skeuomorphic táctil (carbón con relieve y acentos cobre), tokens en `src/styles/global.css`.

## Pendiente

- Tag de afiliado nuevo en `src/lib/site.js` (ahora `PENDIENTE-21`).
- ASIN de cada producto (sin ASIN el botón lleva a una búsqueda en Amazon con el tag).
- Dominio en `SITE.url` y fotos propias en WebP.
