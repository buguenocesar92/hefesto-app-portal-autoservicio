<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\RolUsuario;
use App\Models\Invitacion;
use App\Models\Tenant;
use App\Models\Usuario;
use App\Services\Tenancy\AuditorDeAcceso;
use App\Services\Tenancy\ContextoTenant;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Resuelve a que PyME pertenece la identidad que entro por Authentik,
 * y devuelve (o crea) el usuario local de esa PyME.
 *
 * REGLA DE RESOLUCION (en este orden)
 * -----------------------------------
 * 1. Si la entrada del portal trae `slug` (por ejemplo
 *    `portal.hefestostudio.cl/panaderia-las-tortas`), manda el slug: se
 *    busca esa PyME y se exige que este activa.
 * 2. Si no hay slug, se resuelve por DOMINIO DE CORREO: cada PyME declara
 *    el dominio de sus empleados (`dominio_correo`, unico en toda la
 *    instalacion).
 * 3. Si hay slug Y dominio, se CRUZAN: si el dominio del correo no
 *    corresponde al dominio declarado por esa PyME, el acceso se rechaza
 *    y queda registrado. Este cruce es lo que impide que un empleado de
 *    una PyME entre por la puerta de otra.
 *
 * ALTA DE USUARIO
 * ---------------
 * Una identidad de Authentik NO habilita por si sola el acceso al portal.
 * Se necesita ademas una de estas dos condiciones:
 *
 *   a) el dominio del correo es el dominio declarado por la PyME, o
 *   b) el correo tiene una invitacion vigente en esa PyME.
 *
 * Motivo: desde Authentik 2025.10 el claim `email_verified` vale `false`
 * salvo que se configure un flujo que lo verifique. Confiar solo en ese
 * claim seria confiar en algo que el proveedor ya no garantiza.
 *
 * ROLES
 * -----
 * La identidad la demuestra Authentik; la PERMISION la decide la PyME
 * dentro del portal. El grupo de Authentik solo sirve para el PRIMER
 * alta: si la persona pertenece al grupo configurado, nace como
 * administrador. Despues manda el rol guardado en el portal, que es lo
 * que cambia el administrador de la PyME desde el mismo portal.
 */
class ResolvedorTenant
{
    public function __construct(
        private readonly ContextoTenant $contexto,
        private readonly AuditorDeAcceso $auditor
    ) {}

    /**
     * @param  array{sub: string, correo: ?string, correo_verificado: bool, nombre: ?string, usuario_preferido: ?string, grupos: list<string>}  $identidad
     * @return array{tenant: Tenant, usuario: Usuario}
     */
    public function resolver(?string $slugSolicitado, array $identidad): array
    {
        $correo = Str::lower(trim((string) ($identidad['correo'] ?? '')));
        $sub = (string) ($identidad['sub'] ?? '');

        if ($sub === '') {
            throw new RuntimeException('Authentik no entrego el identificador del usuario (sub).');
        }

        if ($correo === '') {
            throw new RuntimeException('El alcance "email" es obligatorio en el proveedor de Authentik.');
        }

        $dominio = $this->dominioDe($correo);

        $tenant = $slugSolicitado !== null
            ? $this->tenantPorSlug($slugSolicitado)
            : $this->tenantPorDominio($dominio);

        if ($tenant === null) {
            $this->auditor->registrarEventoDeAcceso('tenant_no_resuelto', [
                'slug' => $slugSolicitado,
                'dominio' => $dominio,
            ]);

            throw new RuntimeException('Este correo no pertenece a ninguna PyME con acceso al portal.');
        }

        // Cruce: si la PyME declara dominio, el correo debe calzar.
        if ($tenant->dominio_correo !== null && $tenant->dominio_correo !== $dominio) {
            $this->auditor->registrarEventoDeAcceso('cruce_de_dominio_rechazado', [
                'slug' => $tenant->slug,
                'dominio_recibido' => $dominio,
                'dominio_declarado' => $tenant->dominio_correo,
            ]);

            throw new RuntimeException('Esta cuenta no tiene acceso al portal de esa PyME.');
        }

        // A partir de aqui toda consulta va dentro del contexto del tenant.
        return $this->contexto->ejecutarAislado(
            $tenant->id,
            fn (): array => [
                'tenant' => $tenant,
                'usuario' => $this->obtenerOCrearUsuario($tenant, $identidad, $correo, $dominio),
            ]
        );
    }

    /**
     * Busca (o crea) el usuario local dentro del tenant ya resuelto.
     */
    private function obtenerOCrearUsuario(Tenant $tenant, array $identidad, string $correo, string $dominio): Usuario
    {
        $usuario = Usuario::where('authentik_sub', $identidad['sub'])->first();

        if ($usuario !== null) {
            $this->sincronizarDatos($usuario, $identidad, $correo);

            return $usuario;
        }

        $invitacionVigente = $this->buscarInvitacion($tenant->id, $correo);
        $dominioCoincide = $tenant->dominio_correo !== null && $tenant->dominio_correo === $dominio;

        if ($invitacionVigente === null && ! $dominioCoincide) {
            $this->auditor->registrarEventoDeAcceso('alta_rechazada_sin_invitacion', [
                'slug' => $tenant->slug,
                'correo' => $correo,
            ]);

            throw new RuntimeException(
                'Esta cuenta no esta habilitada para el portal. Pida a la PyME que lo invite.'
            );
        }

        $nombre = $this->partirNombre((string) ($identidad['nombre'] ?? ''));

        $usuario = Usuario::create([
            'tenant_id' => $tenant->id,
            'authentik_sub' => $identidad['sub'],
            'correo' => $correo,
            'nombre' => $nombre['nombre'],
            'apellido' => $nombre['apellido'],
            'rol' => $this->rolInicial($identidad['grupos'] ?? []),
            'activo' => true,
            'ultimo_acceso' => now(),
        ]);

        $invitacionVigente?->forceFill(['aceptada_en' => now()])->save();

        $this->auditor->registrarEventoDeAcceso('alta_de_usuario', [
            'slug' => $tenant->slug,
            'usuario_id' => $usuario->id,
            'rol' => $usuario->rol->value,
        ]);

        return $usuario;
    }

    /**
     * Refresca los datos que Authentik es la fuente de verdad.
     *
     * El ROL NO se toca: lo administra la PyME desde el portal.
     */
    private function sincronizarDatos(Usuario $usuario, array $identidad, string $correo): void
    {
        $cambios = [
            'correo' => $correo,
            'ultimo_acceso' => now(),
        ];

        if (! $usuario->activo) {
            // Un usuario desactivado por la PyME no vuelve a entrar solo
            // porque su cuenta siga activa en Authentik.
            $this->auditor->registrarEventoDeAcceso('acceso_de_usuario_desactivado', [
                'usuario_id' => $usuario->id,
            ]);

            throw new RuntimeException('Esta cuenta fue desactivada. Contacte a la PyME.');
        }

        if (($identidad['nombre'] ?? null) !== null) {
            $nombre = $this->partirNombre((string) $identidad['nombre']);

            $cambios['nombre'] = $nombre['nombre'];
            $cambios['apellido'] = $nombre['apellido'];
        }

        $usuario->forceFill($cambios)->save();
    }

    private function rolInicial(array $grupos): RolUsuario
    {
        $grupoAdmin = (string) config('authentik.grupo_administradores');

        return in_array($grupoAdmin, $grupos, true)
            ? RolUsuario::Administrador
            : RolUsuario::Cliente;
    }

    private function tenantPorSlug(string $slug): ?Tenant
    {
        $slug = Tenant::normalizarSlug($slug);

        return Tenant::where('slug', $slug)->where('activo', true)->first();
    }

    private function tenantPorDominio(string $dominio): ?Tenant
    {
        if ($dominio === '') {
            return null;
        }

        return Tenant::where('dominio_correo', $dominio)->where('activo', true)->first();
    }

    private function buscarInvitacion(string $tenantId, string $correo): ?Invitacion
    {
        return Invitacion::where('correo', $correo)
            ->where('aceptada_en', null)
            ->where(function ($consulta): void {
                $consulta->whereNull('expira_en')->orWhere('expira_en', '>', now());
            })
            ->orderByDesc('creado_en')
            ->first();
    }

    private function dominioDe(string $correo): string
    {
        $posicion = strrpos($correo, '@');

        return $posicion === false ? '' : Str::lower(substr($correo, $posicion + 1));
    }

    /**
     * Authentik entrega `name` como un solo texto. Se parte por el primer
     * espacio para guardar nombre y apellido por separado, sin inventar
     * datos: lo que no viene, queda en NULL.
     *
     * @return array{nombre: ?string, apellido: ?string}
     */
    private function partirNombre(string $nombreCompleto): array
    {
        $partes = preg_split('/\s+/u', trim($nombreCompleto), 2) ?: [];

        return [
            'nombre' => $partes[0] !== '' ? $partes[0] : null,
            'apellido' => $partes[1] ?? null,
        ];
    }
}