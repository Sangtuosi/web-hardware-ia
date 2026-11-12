#!/usr/bin/env python3
"""Genera wordpress/data/guias.json a partir de los módulos g*.py de esta carpeta.
Cada módulo define un diccionario G con la guía. Uso: python3 wordpress/guias-src/build.py
Reglas (ver rutina de artículos): sin precios, sin la raya larga, datos de las fichas con {nota:slug}, {tps:slug}, {vram:slug}, {bw:slug}, {w:slug}, {mayor:slug}."""
import importlib, json, os, sys

AQUI = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, AQUI)

MODULOS = ['g01_vram', 'g02_ollama', 'g03_lmstudio', 'g04_gpu', 'g05_egpu', 'g06_nvidia_amd', 'g07_calidad_precio', 'g08_minipc', 'g09_mac']


def p(t): return {"p": t}
def h3(t): return {"h3": t}
def ul(*x): return {"ul": list(x)}
def ol(*x): return {"ol": list(x)}
def aviso(t, titulo="Ojo con esto"): return {"aviso": t, "titulo": titulo}
def stats(*x): return {"stats": [list(i) for i in x]}
def tabla(cab, filas, nota=None):
    d = {"cab": cab, "filas": filas}
    if nota: d["nota"] = nota
    return {"tabla": d}
def barras(titulo, datos, unidad="", nota=None):
    d = {"titulo": titulo, "datos": datos, "unidad": unidad}
    if nota: d["nota"] = nota
    return {"barras": d}
def pick(slug, rol, texto=None):
    d = {"pick": slug, "rol": rol}
    if texto: d["texto"] = texto
    return d
def cta(texto, boton, url): return {"cta": {"texto": texto, "boton": boton, "url": url}}
def sec(h2, *bloques): return {"h2": h2, "bloques": list(bloques)}


def main():
    guias = []
    for m in MODULOS:
        mod = importlib.import_module(m)
        guias.append(mod.G)
    salida = os.path.join(AQUI, '..', 'data', 'guias.json')
    with open(salida, 'w', encoding='utf-8') as f:
        json.dump(guias, f, ensure_ascii=False, indent=2)
        f.write('\n')
    print(len(guias), 'guías ->', os.path.normpath(salida))


if __name__ == '__main__':
    main()
