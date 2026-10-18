"""Verificaciones del proyecto que no necesitan PHP, Node ni Docker.

Hace cinco chequeos:

  1. JSON bien formado (package.json, composer.json, tsconfig.json)
  2. YAML bien formado (los dos docker-compose)
  3. Barrido de secretos: ninguna clave, contrasena o token con valor real
  4. Referencias rutas <-> controladores: toda ruta apunta a un controlador
     que exista, y todo controlador declarado tiene al menos una ruta
  5. Puertos publicados: lista los que quedan expuestos al host

Uso:  python herramientas/verificar_proyecto.py [raiz]
Salida: 0 si todo pasa, 1 si hay problemas.
"""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path

try:
    import yaml
except ImportError:  # pragma: no cover
    yaml = None

FALLOS: list[str] = []


def avisar(mensaje: str) -> None:
    FALLOS.append(mensaje)


# ---------------------------------------------------------------------------
# 1. JSON
# ---------------------------------------------------------------------------
def revisar_json(raiz: Path) -> int:
    objetivos = sorted(
        p
        for p in raiz.rglob("*.json")
        if "node_modules" not in p.parts and "vendor" not in p.parts
    )

    for archivo in objetivos:
        try:
            json.loads(archivo.read_text(encoding="utf-8"))
        except json.JSONDecodeError as e:
            avisar(f"JSON invalido: {archivo} ({e})")

    print(f"  JSON revisados: {len(objetivos)}")
    return len(objetivos)


# ---------------------------------------------------------------------------
# 2. YAML
# ---------------------------------------------------------------------------
def revisar_yaml(raiz: Path) -> int:
    if yaml is None:
        print("  YAML: PyYAML no esta instalado, no se pudo revisar.")
        return 0

    objetivos = sorted(
        p
        for p in raiz.rglob("*.yml")
        if "node_modules" not in p.parts and "vendor" not in p.parts
    )

    for archivo in objetivos:
        try:
            list(yaml.safe_load_all(archivo.read_text(encoding="utf-8")))
        except yaml.YAMLError as e:
            avisar(f"YAML invalido: {archivo} ({e})")

    print(f"  YAML revisados: {len(objetivos)}")
    return len(objetivos)


# ---------------------------------------------------------------------------
# 3. Secretos
# ---------------------------------------------------------------------------
# Nombres de variable que SI deben aparecer (en plantillas vacias), contra
# valores que parezcan reales.
PATRONES_SECRETOS = [
    (re.compile(r"sk-[A-Za-z0-9]{20,}"), "posible clave de API estilo OpenAI"),
    (re.compile(r"ghp_[A-Za-z0-9]{30,}"), "posible token de GitHub"),
    (re.compile(r"AKIA[0-9A-Z]{16}"), "posible clave de AWS"),
    (re.compile(r"-----BEGIN [A-Z ]*PRIVATE KEY-----"), "clave privada"),
    (re.compile(r"(?i)(contraseña|password|clave|secreto|secret|token)\s*[:=]\s*['\"]?[A-Za-z0-9/+_-]{16,}"), "valor que parece una clave"),
]

# Lineas de ejemplo que si son legitimas (comandos para generar un secreto).
PERMITIDAS = (
    "openssl rand",
    "php artisan key:generate",
    "password:",
    "DB_PASSWORD=",
    "REDIS_PASSWORD=",
    "AUTHENTIK_SECRET_KEY=",
    "AUTHENTIK_BOOTSTRAP__PASSWORD=",
    "AUTHENTIK_BOOTSTRAP__TOKEN=",
    "AUTHENTIK_POSTGRESQL__PASSWORD=",
    "AUTHENTIK_CLIENT_SECRET=",
)


def revisar_secretos(raiz: Path) -> int:
    revisados = 0

    for archivo in raiz.rglob("*"):
        if not archivo.is_file():
            continue
        if {"node_modules", "vendor", ".git", ".nuxt", ".output"} & set(archivo.parts):
            continue
        if archivo.suffix not in {".php", ".ts", ".js", ".vue", ".yml", ".yaml", ".json", ".md", ".py", ".sh", ".ini", ".conf", ".example"}:
            continue
        if not archivo.name.startswith(".env") and archivo.suffix not in {".example", ".md"}:
            # Solo interesan las plantillas y los archivos de configuracion.
            if archivo.suffix not in {".yml", ".yaml", ".conf", ".ini", ".env"}:
                continue

        try:
            texto = archivo.read_text(encoding="utf-8")
        except (UnicodeDecodeError, OSError):
            continue

        revisados += 1

        for numero, linea in enumerate(texto.splitlines(), start=1):
            for patron, descripcion in PATRONES_SECRETOS:
                if not patron.search(linea):
                    continue
                if any(p in linea for p in PERMITIDAS):
                    continue
                avisar(f"Posible secreto en {archivo}:{numero} ({descripcion}): {linea.strip()[:90]}")

    print(f"  Archivos revisados por secretos: {revisados}")
    return revisados


# ---------------------------------------------------------------------------
# 4. Rutas <-> controladores
# ---------------------------------------------------------------------------
RUTAS = re.compile(r"\[([A-Za-z]+)::class,\s*'([a-zA-Z]+)'\]")
CLASES = re.compile(r"^namespace\s+([A-Za-z\\]+);", re.MULTILINE)


def revisar_rutas(raiz: Path) -> int:
    archivo_rutas = raiz / "api" / "routes" / "web.php"

    if not archivo_rutas.exists():
        avisar("No se encontro api/routes/web.php")
        return 0

    texto = archivo_rutas.read_text(encoding="utf-8")

    # Namespaces de los controladores.
    namespaces: dict[str, str] = {}
    for archivo in (raiz / "api" / "app" / "Http" / "Controllers").rglob("*.php"):
        contenido = archivo.read_text(encoding="utf-8")
        coincidencia = CLASES.search(contenido)
        if coincidencia:
            namespaces[archivo.stem] = coincidencia.group(1)

    # Imports que usa el archivo de rutas: `use App\Http\Controllers\X;`
    imports: dict[str, str] = {}
    for completo in re.findall(r"^\s*use\s+([A-Za-z0-9_\\]+)\s*;", texto, re.MULTILINE):
        imports[completo.rsplit("\\", 1)[-1]] = completo

    referencias = RUTAS.findall(texto)

    for clase, metodo in referencias:
        namespace = imports.get(clase)

        if namespace is None:
            avisar(f"La ruta usa {clase}::class pero no hay import de esa clase.")
            continue

        destino = raiz / "api" / "app" / "Http" / "Controllers"
        esperado = destino / (namespace.split("Controllers\\")[-1].replace("\\", "/") + ".php")

        if not esperado.exists():
            avisar(f"El controlador {namespace}\\{clase} referenciado en rutas no existe ({esperado}).")
            continue

        contenido = esperado.read_text(encoding="utf-8")

        if not re.search(rf"function\s+{metodo}\s*\(", contenido):
            avisar(f"{clase}::class llama a '{metodo}' pero ese metodo no existe en {esperado.name}.")

    # Controladores sin ninguna ruta.
    for archivo in (raiz / "api" / "app" / "Http" / "Controllers").rglob("*.php"):
        if archivo.name == "Controller.php":
            continue
        if f"{archivo.stem}::class" not in texto:
            avisar(f"El controlador {archivo.stem} no esta referenciado en ninguna ruta.")

    print(f"  Referencias ruta-controlador revisadas: {len(referencias)}")
    return len(referencias)


# ---------------------------------------------------------------------------
# 5. Puertos publicados
# ---------------------------------------------------------------------------
def revisar_puertos(raiz: Path) -> int:
    encontrados = 0

    for archivo in sorted(raiz.glob("deploy/docker-compose*.yml")):
        datos = yaml.safe_load(archivo.read_text(encoding="utf-8")) or {}
        servicios = datos.get("services", {})
        publicados = {
            nombre: spec.get("ports", [])
            for nombre, spec in servicios.items()
            if isinstance(spec, dict) and spec.get("ports")
        }
        encontrados += len(publicados)
        print(f"  {archivo.name}: {', '.join(sorted(publicados)) or '(sin puertos publicados)'}")

    return encontrados


# ---------------------------------------------------------------------------
def main() -> int:
    raiz = Path(sys.argv[1] if len(sys.argv) > 1 else ".")

    print("1) JSON")
    revisar_json(raiz)
    print("2) YAML")
    revisar_yaml(raiz)
    print("3) Secretos")
    revisar_secretos(raiz)
    print("4) Rutas y controladores")
    revisar_rutas(raiz)
    print("5) Puertos publicados")
    revisar_puertos(raiz)

    print()
    if FALLOS:
        print(f"PROBLEMAS ENCONTRADOS: {len(FALLOS)}")
        for f in FALLOS:
            print(f"  - {f}")
        return 1

    print("Sin problemas.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())