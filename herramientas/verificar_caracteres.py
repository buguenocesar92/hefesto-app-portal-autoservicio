"""Barrido de caracteres que no deberian aparecer en este repositorio.

Busca, en todos los archivos de texto:

  - ideogramas CJK (han, hiragana, katakana, hangul y la extension A)
  - cirilico (alfabeto ruso y supplementos)

Motivo: este proyecto esta escrito en espanol de Chile. Si aparece un
caracter CJK o cirilico, casi siempre es un error: un fragmento de texto de
otro idioma que se coloco al escribir. Es exactamente el tipo de error que
pasa desapercibido en una revision a ojo.

No se evaluan los archivos binarios ni `.git`.

Uso:  python herramientas/verificar_caracteres.py [carpeta...]
Salida: 0 si no hay hallazgos, 1 si los hay.
"""

from __future__ import annotations

import sys
import unicodedata
from pathlib import Path

EXTENSIONES = {
    ".php", ".ts", ".js", ".mjs", ".vue", ".json", ".yml", ".yaml",
    ".md", ".py", ".sh", ".ini", ".conf", ".xml", ".env", ".example",
    ".txt", ".gitignore",
}

EXCLUIDOS = {".git", "node_modules", "vendor", ".nuxt", ".output", "__pycache__"}

RANGOS = [
    ("CJK", 0x4E00, 0x9FFF),      # ideogramas unificados
    ("CJK", 0x3400, 0x4DBF),      # extension A
    ("CJK", 0xF900, 0xFAFF),      # de compatibilidad
    ("hiragana", 0x3040, 0x309F),
    ("katakana", 0x30A0, 0x30FF),
    ("hangul", 0xAC00, 0xD7AF),
    ("cirilico", 0x0400, 0x04FF),
    ("cirilico", 0x0500, 0x052F),
]


def categoria_de(caracter: str) -> str | None:
    codigo = ord(caracter)

    for nombre, inicio, fin in RANGOS:
        if inicio <= codigo <= fin:
            return nombre

    return None


def main() -> int:
    # La consola de Windows no siempre puede imprimir el caracter suspecto, que
    # es justo el que hay que mostrar. Con `replace` se imprime algo, y el
    # archivo y la linea siguen siendo identificables.
    if hasattr(sys.stdout, "reconfigure"):
        sys.stdout.reconfigure(encoding="utf-8", errors="replace")

    carpetas = sys.argv[1:] or ["."]

    archivos: list[Path] = []
    for carpeta in carpetas:
        for ruta in Path(carpeta).rglob("*"):
            if not ruta.is_file() or EXCLUIDOS & set(ruta.parts):
                continue
            if ruta.suffix in EXTENSIONES or ruta.name.startswith(".env"):
                archivos.append(ruta)

    hallazgos: list[str] = []

    for archivo in sorted(archivos):
        try:
            texto = archivo.read_text(encoding="utf-8")
        except UnicodeDecodeError:
            continue

        for numero, linea in enumerate(texto.splitlines(), start=1):
            for posicion, caracter in enumerate(linea):
                categoria = categoria_de(caracter)
                if categoria is None:
                    continue
                contexto = linea[max(0, posicion - 25): posicion + 25]
                hallazgos.append(
                    f"{archivo}:{numero}: [{categoria}] "
                    f"U+{ord(caracter):04X} {unicodedata.name(caracter, '?')}\n"
                    f"      {contexto}"
                )

    print(f"Archivos revisados: {len(archivos)}")

    if not hallazgos:
        print("Sin ideogramas CJK ni caracteres cirilicos.")
        return 0

    print(f"HALLAZGOS: {len(hallazgos)}")
    for h in hallazgos:
        print(f"  - {h}")

    return 1


if __name__ == "__main__":
    raise SystemExit(main())