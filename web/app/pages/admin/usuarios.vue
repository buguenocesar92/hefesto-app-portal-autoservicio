<template>
  <div>
    <h1>Personas de la PyME</h1>
    <p class="ayuda">
      Quien puede entrar al portal de tu empresa y con que permiso.
      La identidad la maneja Authentik; acui se decide el permiso.
    </p>

    <h2>Invitar a alguien</h2>
    <form class="invitacion" @submit.prevent="invitar">
      <input
        v-model="invitacion.correo"
        type="email"
        placeholder="persona@empresa.cl"
        aria-label="Correo"
      />
      <select v-model="invitacion.rol" aria-label="Rol">
        <option value="cliente">Cliente</option>
        <option value="administrador">Administrador de la PyME</option>
      </select>
      <button type="submit" class="boton" :disabled="enviandoInvitacion">Invitar</button>
    </form>

    <p v-if="invitacionCreada" class="aviso">
      Invitacion creada para <strong>{{ invitacionCreada.invitacion.correo }}</strong>.
      Entrega este enlace a la persona, y no lo guardes en ningun lado:
      <code>{{ invitacionCreada.token }}</code>
    </p>

    <ul v-if="errores.length > 0" class="errores">
      <li v-for="mensaje in errores" :key="mensaje">{{ mensaje }}</li>
    </ul>

    <h2>Personas actuales</h2>
    <table class="tabla">
      <thead>
        <tr>
          <th>Nombre</th>
          <th>Correo</th>
          <th>Rol</th>
          <th>Estado</th>
          <th>Ultimo acceso</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="persona in personas" :key="persona.id">
          <td>{{ persona.nombre_completo }}</td>
          <td>{{ persona.correo }}</td>
          <td>
            <select
              :value="persona.rol"
              :disabled="persona.id === usuario?.id"
              @change="cambiarRol(persona, $event)"
            >
              <option value="cliente">Cliente</option>
              <option value="administrador">Administrador</option>
            </select>
          </td>
          <td>{{ persona.activo ? 'Activa' : 'Desactivada' }}</td>
          <td>{{ persona.ultimo_acceso ? formatearFecha(persona.ultimo_acceso) : 'Nunca' }}</td>
          <td>
            <button
              v-if="persona.activo && persona.id !== usuario?.id"
              type="button"
              class="boton boton--peligro"
              @click="desactivar(persona)"
            >
              Desactivar
            </button>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<script setup lang="ts">
import type { RecursoPaginado, UsuarioPortal } from '~/tipos/api';

definePageMeta({ middleware: 'auth' });

const { usuario } = useSesion();

const invitacion = reactive({ correo: '', rol: 'cliente' });
const enviandoInvitacion = ref(false);
const invitacionCreada = ref<{ invitacion: { correo: string }; token: string } | null>(null);
const errores = ref<string[]>([]);

const { data, refresh } = await useFetch<RecursoPaginado<UsuarioPortal>>('/api/v1/admin/usuarios');

const personas = computed(() => data.value?.data ?? []);

async function invitar() {
  enviandoInvitacion.value = true;
  errores.value = [];
  invitacionCreada.value = null;

  try {
    invitacionCreada.value = await $fetch('/api/v1/admin/usuarios', {
      method: 'POST',
      body: { correo: invitacion.correo, rol: invitacion.rol },
    });

    invitacion.correo = '';
    await refresh();
  } catch (fallo) {
    errores.value = mensajesDeError(fallo);
  } finally {
    enviandoInvitacion.value = false;
  }
}

async function cambiarRol(persona: UsuarioPortal, evento: Event) {
  const nuevo = (evento.target as HTMLSelectElement).value;

  errores.value = [];

  try {
    await $fetch(`/api/v1/admin/usuarios/${persona.id}`, {
      method: 'PATCH',
      body: { rol: nuevo },
    });

    await refresh();
  } catch (fallo) {
    errores.value = mensajesDeError(fallo);
    await refresh();
  }
}

async function desactivar(persona: UsuarioPortal) {
  errores.value = [];

  try {
    await $fetch(`/api/v1/admin/usuarios/${persona.id}/desactivar`, { method: 'POST' });
    await refresh();
  } catch (fallo) {
    errores.value = mensajesDeError(fallo);
  }
}

function mensajesDeError(fallo: unknown): string[] {
  const respuesta = fallo as { data?: { data?: { errors?: Record<string, string[]>; mensaje?: string } } };
  const cuerpo = respuesta.data?.data;

  if (cuerpo?.errors) {
    return Object.values(cuerpo.errors).flat();
  }

  return [cuerpo?.mensaje ?? 'No se pudo completar la operacion.'];
}

function formatearFecha(valor: string): string {
  return new Date(valor).toLocaleString('es-CL');
}
</script>

<style scoped>
.invitacion {
  display: flex;
  gap: 0.6rem;
  flex-wrap: wrap;
  margin-bottom: 0.75rem;
}

.tabla {
  width: 100%;
  border-collapse: collapse;
}

.tabla th,
.tabla td {
  text-align: left;
  padding: 0.5rem;
  border-bottom: 1px solid #e4eaf1;
  font-size: 0.92rem;
}

.boton {
  padding: 0.35rem 0.8rem;
  border: none;
  border-radius: 6px;
  background: #2b6cb0;
  color: #ffffff;
  cursor: pointer;
}

.boton--peligro {
  background: #c53030;
}

.aviso {
  background: #fef3c7;
  color: #92400e;
  padding: 0.7rem 0.9rem;
  border-radius: 6px;
  font-size: 0.9rem;
}

.errores {
  color: #991b1b;
  padding-left: 1.1rem;
}

.ayuda {
  color: #627d98;
  font-size: 0.9rem;
}

input,
select {
  padding: 0.45rem 0.6rem;
  border: 1px solid #bcccdc;
  border-radius: 6px;
}
</style>