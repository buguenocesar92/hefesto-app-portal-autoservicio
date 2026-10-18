"""Verificador de balance de delimitadores en codigo sin ejecutarlo.

No reemplaza a `php -l` ni a `tsc`, pero detecta el error mas comun de este
tipo de trabajo: llaves, parentesis y corchetes desbalanceados.

Entiende lo necesario para no dar falsos positivos:
  - comentarios de linea (`//`, `#`) y de bloque (`/* */`)
  - cadenas con comillas simples, dobles y acentos graves, que en PHP y en
    JavaScript pueden ocupar varias lineas
  - comillas escapadas con barra invertida y comillas dobladas de PHP (`''`)
  - heredocs y nowdocs de PHP (`<<<SQL` / `<<<'SQL'`)

Uso:  python herramientas/balanceo.py <carpeta> [carpeta...]
"""

from __future__ import annotations

import sys
from pathlib import Path

PARES = {"{": "}", "(": ")", "[": "]"}
CIERRES = {v: k for k, v in PARES.items()}
COMILLAS = ("'", '"', "`")
EXTENSIONES = {".php", ".ts", ".js", ".mjs", ".vue", ".json", ".yaml", ".yml"}


def revisar(ruta: Path) -> list[str]:
    texto = ruta.read_text(encoding="utf-8")
    pila: list[tuple[str, int]] = []
    linea = 1
    i = 0
    n = len(texto)
    problemas: list[str] = []

    while i < n:
        c = texto[i]

        if c == "\n":
            linea += 1
            i += 1
            continue

        # Heredoc / nowdoc de PHP.
        if texto.startswith("<<<", i):
            j = i + 3
            while j < n and texto[j] in " \t":
                j += 1
            if j < n and texto[j] in ("'", '"'):
                cierre = texto.find(texto[j], j + 1)
                etiqueta = texto[j + 1 : cierre]
                j = cierre + 1
            else:
                k = j
                while k < n and (texto[k].isalnum() or texto[k] == "_"):
                    k += 1
                etiqueta = texto[j:k]
                j = k

            fin = _fin_de_heredoc(texto, etiqueta, j)
            if fin == -1:
                problemas.append(f"{ruta.name}:{linea} heredoc <<<{etiqueta} sin cerrar")
                return problemas
            linea += texto.count("\n", i, fin)
            i = fin
            continue

        # Comentarios de linea. El `#` es un comentario en PHP, en YAML, en
        # shell y en conf: se trata igual en todos. No hay riesgo de que una
        # `#` dentro de una cadena llegue aqui, porque las cadenas se consumen
        # enteras antes.
        #
        # Para `//` hace falta mirar atras: en YAML y en configuracion hay
        # URLs sin comillas (`http://api:8080`), y un `//` a mitad de palabra
        # NO es el inicio de un comentario. Solo se toma como comentario si
        # empieza la linea o viene precedido por un espacio.
        es_comentario_slash = texto.startswith("//", i) and (
            i == 0 or texto[i - 1] in " \t\n"
        )

        if es_comentario_slash or c == "#":
            while i < n and texto[i] != "\n":
                i += 1
            continue

        # Comentarios de bloque.
        if texto.startswith("/*", i):
            fin = texto.find("*/", i + 2)
            if fin == -1:
                problemas.append(f"{ruta.name}:{linea} comentario de bloque sin cerrar")
                return problemas
            linea += texto.count("\n", i, fin)
            i = fin + 2
            continue

        # Cadenas. Pueden ocupar varias lineas en PHP y en JavaScript.
        if c in COMILLAS:
            comilla = c
            i += 1
            while i < n:
                if texto[i] == "\\":
                    i += 2
                    continue
                if texto.startswith("${", i):
                    i += 2
                    continue
                if texto[i] == comilla:
                    # Comilla doblada de PHP: '' equivale a una comilla.
                    if texto.startswith(comilla * 2, i) and comilla != "`":
                        i += 2
                        continue
                    break
                if texto[i] == "\n":
                    linea += 1
                i += 1
            else:
                problemas.append(f"{ruta.name}:{linea} cadena sin cerrar")
                return problemas
            i += 1
            continue

        if c in PARES:
            pila.append((c, linea))
        elif c in CIERRES:
            if not pila:
                problemas.append(f"{ruta.name}:{linea} sobra un '{c}'")
                return problemas
            abierto, linea_abierto = pila.pop()
            if PARES[abierto] != c:
                problemas.append(
                    f"{ruta.name}:{linea} se abrio '{abierto}' en la linea {linea_abierto} "
                    f"y se cerro con '{c}'"
                )
                return problemas

        i += 1

    if pila:
        abierto, linea_abierto = pila[-1]
        problemas.append(f"{ruta.name}:{linea_abierto} queda abierto '{abierto}'")

    return problemas


def _fin_de_heredoc(texto: str, etiqueta: str, desde: int) -> int:
    """Posicion justo despues de la ETIQUETA del terminador del heredoc.

    No se salta la linea entera a proposito: el terminador suele ir seguido de
    `);` y ese parentesis de PHP tiene que seguir contandose.
    """
    i = desde

    while i < len(texto):
        fin_de_linea = texto.find("\n", i)
        if fin_de_linea == -1:
            return -1
        linea_texto = texto[i:fin_de_linea]
        sin_espacios = linea_texto.lstrip()
        # El terminador puede ir indentado y seguido de `;`, `)` o `,`
        # (por ejemplo `SQL);`). Lo que NO puede es seguido de otra letra.
        if sin_espacios == etiqueta or (
            sin_espacios.startswith(etiqueta)
            and sin_espacios[len(etiqueta):][:1] in ("", ";", ")", ",")
        ):
            return i + (len(linea_texto) - len(sin_espacios)) + len(etiqueta)
        i = fin_de_linea + 1

    return -1


def main() -> int:
    if len(sys.argv) < 2:
        print("Uso: python herramientas/balanceo.py <carpeta> [carpeta...]")
        return 2

    archivos: list[Path] = []
    for carpeta in sys.argv[1:]:
        raiz = Path(carpeta)
        archivos.extend(
            sorted(
                p
                for p in raiz.rglob("*")
                if p.is_file()
                and p.suffix in EXTENSIONES
                and "vendor" not in p.parts
                and "node_modules" not in p.parts
            )
        )

    problemas: list[str] = []
    for archivo in archivos:
        problemas.extend(revisar(archivo))

    print(f"Archivos revisados: {len(archivos)}")

    if problemas:
        print("DESBALANCES ENCONTRADOS:")
        for p in problemas:
            print(f"  - {p}")
        return 1

    print("Sin desbalances de delimitadores.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())