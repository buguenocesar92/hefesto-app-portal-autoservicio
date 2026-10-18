<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\EstadoSolicitud;
use App\Enums\PrioridadSolicitud;
use App\Enums\RolUsuario;
use App\Enums\TipoDocumento;
use App\Models\CategoriaServicio;
use App\Models\Documento;
use App\Models\Solicitud;
use App\Models\SolicitudEvento;
use App\Models\Tenant;
use App\Models\Usuario;
use App\Services\Solicitudes\GeneradorDeFolio;
use App\Services\Tenancy\ContextoTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Datos de desarrollo: DOS Pymes de ejemplo.
 *
 * Se crean dos y no una a proposito: con una sola no se puede demostrar nada
 * del aislamiento. Las pruebas de `tests/Feature/Aislamiento` usan datos
 * propios, pero tener dos Pymes en la base de desarrollo ayuda a probar a
 * mano que un cliente no ve lo de la otra.
 *
 * NOTA IMPORTANTE SOBRE LA RLS
 * ----------------------------
 * Las tablas de negocio tienen Row Level Security, asi que insertar filas
 * desde un seeder "a pelo" falla: `public.app_tenant_id()` no encuentra
 * contexto y lanza excepcion. Por eso TODO lo de este seeder corre dentro de
 * `ContextoTenant::ejecutarAislado()`. No es un rodeo: es la misma via que
 * usa la aplicacion de verdad. La tabla `tenants` si se puede escribir
 * directamente, porque no lleva RLS.
 *
 * ADVERTENCIA
 * -----------
 * Los usuarios de ejemplo tienen `authentik_sub` ficticios: NO pueden entrar
 * al portal hasta que sus identidades existan en Authentik con ese mismo
 * `sub`, o hasta que se borre el seeder. Ver docs/04, seccion "Datos de
 * desarrollo".
 */
class TenantDemoSeeder extends Seeder
{
    public function run(): void
    {
        $contexto = new ContextoTenant();

        foreach ($this->pymesDeEjemplo() as $datos) {
            $tenant = Tenant::create([
                'slug' => $datos['slug'],
                'nombre' => $datos['nombre'],
                'razon_social' => $datos['razon_social'],
                'rut' => $datos['rut'],
                'dominio_correo' => $datos['dominio'],
                'correo_contacto' => 'contacto@' . $datos['dominio'],
                'telefono_contacto' => '+56 2 2' . substr(hash('crc32b', $datos['slug']), 0, 3) . ' 0000',
                'activo' => true,
            ]);

            $contexto->ejecutarAislado($tenant->id, function () use ($tenant, $datos): void {
                $this->cargarPyme($tenant, $datos);
            });

            $this->command?->info(sprintf(
                '  PyME "%s" creada con slug "%s".',
                $tenant->nombre,
                $tenant->slug
            ));
        }
    }

    /**
     * @param  array<string, string>  $datos
     */
    private function cargarPyme(Tenant $tenant, array $datos): void
    {
        $administrador = Usuario::create([
            'tenant_id' => $tenant->id,
            'authentik_sub' => (string) Str::uuid(),
            'correo' => 'admin@' . $datos['dominio'],
            'nombre' => 'Ana',
            'apellido' => 'Perez',
            'telefono' => '+56 9 1111 2222',
            'rol' => RolUsuario::Administrador,
            'activo' => true,
            'ultimo_acceso' => now(),
        ]);

        $cliente = Usuario::create([
            'tenant_id' => $tenant->id,
            'authentik_sub' => (string) Str::uuid(),
            'correo' => 'cliente@' . $datos['dominio'],
            'nombre' => 'Carlos',
            'apellido' => 'Munoz',
            'telefono' => '+56 9 3333 4444',
            'rol' => RolUsuario::Cliente,
            'activo' => true,
            'ultimo_acceso' => now(),
        ]);

        $mantencion = CategoriaServicio::create([
            'tenant_id' => $tenant->id,
            'nombre' => 'Mantencion preventiva',
            'descripcion' => 'Revision periodica del equipo, con limpieza y cambio de repuestos.',
            'campos' => [
                [
                    'clave' => 'numero_serie',
                    'etiqueta' => 'Numero de serie',
                    'tipo' => 'texto',
                    'requerido' => true,
                ],
                [
                    'clave' => 'franja_horaria',
                    'etiqueta' => 'Franja horaria preferida',
                    'tipo' => 'seleccion',
                    'requerido' => false,
                    'opciones' => ['manana', 'tarde', 'hora punta'],
                ],
            ],
            'tiempo_respuesta_sugerido' => 48,
            'activa' => true,
        ]);

        CategoriaServicio::create([
            'tenant_id' => $tenant->id,
            'nombre' => 'Reparacion de equipos',
            'descripcion' => 'Diagnostico y reparacion en el sitio o en taller.',
            'campos' => null,
            'tiempo_respuesta_sugerido' => 24,
            'activa' => true,
        ]);

        // El folio lo genera el mismo servicio que usa la API, para que los
        // datos de ejemplo tengan la misma forma que los reales.
        $folios = new GeneradorDeFolio();

        $recibida = Solicitud::create([
            'tenant_id' => $tenant->id,
            'folio' => $folios->siguiente($tenant->id),
            'usuario_id' => $cliente->id,
            'categoria_id' => $mantencion->id,
            'titulo' => 'El refrigerador suena fuerte',
            'descripcion' => 'Desde hace una semana el refrigerador del local hace un ruido fuerte cada vez que parte el compresor.',
            'estado' => EstadoSolicitud::Recibida,
            'prioridad' => PrioridadSolicitud::Alta,
            'datos' => ['numero_serie' => 'RF-88213'],
            'asignado_a' => $administrador->id,
        ]);

        $resuelta = Solicitud::create([
            'tenant_id' => $tenant->id,
            'folio' => $folios->siguiente($tenant->id),
            'usuario_id' => $cliente->id,
            'categoria_id' => $mantencion->id,
            'titulo' => 'Cambio de filtro de la campana',
            'descripcion' => 'Necesito cambiar el filtro de la campana extractora antes de fin de mes.',
            'estado' => EstadoSolicitud::Cerrada,
            'prioridad' => PrioridadSolicitud::Baja,
            'datos' => null,
            'asignado_a' => $administrador->id,
            'cerrada_en' => now()->subDays(3),
        ]);

        SolicitudEvento::create([
            'tenant_id' => $tenant->id,
            'solicitud_id' => $recibida->id,
            'usuario_id' => $cliente->id,
            'estado_anterior' => null,
            'estado_nuevo' => EstadoSolicitud::Recibida,
            'comentario' => 'Solicitud abierta por el cliente.',
            'registrado_en' => now()->subHours(5),
        ]);

        SolicitudEvento::create([
            'tenant_id' => $tenant->id,
            'solicitud_id' => $recibida->id,
            'usuario_id' => $administrador->id,
            'estado_anterior' => EstadoSolicitud::Recibida,
            'estado_nuevo' => EstadoSolicitud::EnRevision,
            'comentario' => 'Estamos confirmando si es el compresor o el ventilador.',
            'registrado_en' => now()->subHours(3),
        ]);

        SolicitudEvento::create([
            'tenant_id' => $tenant->id,
            'solicitud_id' => $resuelta->id,
            'usuario_id' => $cliente->id,
            'estado_anterior' => null,
            'estado_nuevo' => EstadoSolicitud::Recibida,
            'comentario' => 'Solicitud abierta por el cliente.',
            'registrado_en' => now()->subDays(6),
        ]);

        Documento::create([
            'tenant_id' => $tenant->id,
            'usuario_id' => null,
            'tipo' => TipoDocumento::Boleta,
            'numero' => 'B-1042',
            'detalle' => 'Servicio de mantenimiento',
            'emitido_en' => now()->subDays(4)->toDateString(),
            'monto_bruto' => 85000,
            'moneda' => 'CLP',
            'ruta_archivo' => 'ejemplo/boleta-B-1042.pdf',
            'nombre_archivo' => 'boleta-B-1042.pdf',
            'mime' => 'application/pdf',
            'tamano_bytes' => 48210,
            'hash_sha256' => hash('sha256', $datos['slug'] . '-boleta'),
        ]);

        Documento::create([
            'tenant_id' => $tenant->id,
            'usuario_id' => $cliente->id,
            'tipo' => TipoDocumento::Contrato,
            'numero' => 'CT-0007',
            'detalle' => 'Contrato de soporte mensual',
            'emitido_en' => now()->subMonths(1)->toDateString(),
            'monto_bruto' => 120000,
            'moneda' => 'CLP',
            'ruta_archivo' => 'ejemplo/contrato-CT-0007.pdf',
            'nombre_archivo' => 'contrato-CT-0007.pdf',
            'mime' => 'application/pdf',
            'tamano_bytes' => 120300,
            'hash_sha256' => hash('sha256', $datos['slug'] . '-contrato'),
        ]);

        $this->command?->info(sprintf(
            '    2 usuarios, 2 categorias, 2 solicitudes y 2 documentos para "%s".',
            $tenant->slug
        ));
    }

    /**
     * @return list<array<string, string>>
     */
    private function pymesDeEjemplo(): array
    {
        return [
            [
                'slug' => 'panaderia-las-tortas',
                'nombre' => 'Panaderia Las Tortas',
                'razon_social' => 'Panaderia Las Tortas SpA',
                'rut' => '76.123.456-7',
                'dominio' => 'lastortas.cl',
            ],
            [
                'slug' => 'taller-bravo',
                'nombre' => 'Taller Bravo',
                'razon_social' => 'Taller Bravo Ltda.',
                'rut' => '77.654.321-9',
                'dominio' => 'tallerbravo.cl',
            ],
        ];
    }
}