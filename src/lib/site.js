// Configuración central de la web. Cambiar aquí el nombre, el dominio o el tag de afiliado.
export const SITE = {
  nombre: 'TensorForja',
  url: 'https://example.com',
  autor: 'Ernesto Santos',
  idioma: 'es-ES',
};

// Tag de Amazon Associates de ESTA web (distinto de pedalada-21).
// Pendiente: Ernesto debe crearlo en su cuenta de Associates y ponerlo aquí.
export const AMAZON_TAG = 'PENDIENTE-21';

/**
 * Enlace de afiliado a amazon.es. Con ASIN va a la ficha (/dp/); sin ASIN, a una búsqueda.
 * Siempre con el tag de la web.
 */
export function amazonUrl(producto) {
  const tag = encodeURIComponent(AMAZON_TAG);
  if (producto.asin) return `https://www.amazon.es/dp/${producto.asin}?tag=${tag}`;
  return `https://www.amazon.es/s?k=${encodeURIComponent(producto.nombre)}&tag=${tag}`;
}

export const AMAZON_REL = 'sponsored nofollow noopener';
