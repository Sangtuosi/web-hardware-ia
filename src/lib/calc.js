// Cálculos puros compartidos por las páginas, la calculadora y las pruebas.

export const CUANTIZACIONES = {
  q4: { nombre: 'Q4 · 4 bits', bits: 4.5 },
  q8: { nombre: 'Q8 · 8 bits', bits: 8.5 },
  fp16: { nombre: 'FP16', bits: 16 },
};

/** GB de memoria que necesita un modelo: pesos (params × bits ÷ 8) + 20 % de contexto y caché. */
export function vramNecesaria(modelo, bits = 4.5) {
  if (modelo.fijo) return bits >= 16 ? modelo.fijo * 1.6 : modelo.fijo;
  return (modelo.params * bits) / 8 * 1.2;
}

export function cabe(modelo, vram, bits = 4.5) {
  return vramNecesaria(modelo, bits) <= vram;
}

/**
 * Tokens por segundo estimados al generar texto: cada token lee todos los pesos,
 * así que velocidad ≈ ancho de banda × 0,7 (eficiencia real) ÷ tamaño de los pesos.
 * Es una estimación propia, no un benchmark. Devuelve null si no cabe o no es de texto.
 */
export function tokensPorSegundo(producto, modelo, bits = 4.5) {
  if (modelo.tipo !== 'texto' || !cabe(modelo, producto.vram, bits)) return null;
  const pesosGB = (modelo.params * bits) / 8;
  return Math.round((producto.anchoBanda * 0.7) / pesosGB);
}

const ECOSISTEMA = { CUDA: 100, Metal: 70, ROCm: 60, oneAPI: 45 };

/**
 * Puntuaciones 0-100 del radar. Fórmulas publicadas en /como-elegimos/.
 * - VRAM: escala logarítmica de 4 GB (0) a 512 GB (100).
 * - Ancho de banda: GB/s ÷ 1792 (RTX 5090 = 100).
 * - Eficiencia: GB/s por vatio, relativo al mejor del catálogo.
 * - Ecosistema: CUDA 100, Metal 70, ROCm 60, oneAPI 45.
 * - Bajo consumo: 100 − vatios ÷ 6 (600 W = 0).
 */
export function puntuaciones(producto, catalogo) {
  const clamp = (v) => Math.max(0, Math.min(100, Math.round(v)));
  const eficiencia = (p) => p.anchoBanda / p.consumo;
  const mejorEf = Math.max(...catalogo.map(eficiencia));
  return {
    VRAM: clamp((Math.log2(producto.vram / 4) / Math.log2(512 / 4)) * 100),
    'Ancho de banda': clamp((producto.anchoBanda / 1792) * 100),
    Eficiencia: clamp((eficiencia(producto) / mejorEf) * 100),
    Ecosistema: ECOSISTEMA[producto.ecosistema] ?? 40,
    'Bajo consumo': clamp(100 - producto.consumo / 6),
  };
}

/** Modelos que caben en un producto (Q4 por defecto). */
export function modelosQueCaben(producto, modelos, bits = 4.5) {
  return modelos.filter((m) => cabe(m, producto.vram, bits));
}

/** Producto más modesto que tiene al menos esa VRAM (menos VRAM sobrante; a igualdad, más ancho de banda). */
export function recomendado(vram, catalogo) {
  return [...catalogo]
    .filter((p) => p.vram >= vram)
    .sort((a, b) => a.vram - b.vram || b.anchoBanda - a.anchoBanda)[0] ?? null;
}

/**
 * Nota IA (0-100): media de VRAM, ancho de banda y ecosistema, lo que más pesa para IA.
 * Eficiencia y consumo se ven en el radar pero no entran en la nota.
 */
export function notaIA(producto, catalogo) {
  const s = puntuaciones(producto, catalogo);
  return Math.round((s.VRAM + s['Ancho de banda'] + s.Ecosistema) / 3);
}
