<template>
  <div>
    <h1>Categorias de servicio</h1>
    <p class="ayuda">
      Las categorias son las opciones que tu client elige al abrir una
      solicitud. Cada una puede pedir datos extra.
    </p>

    <h2>Nueva categoria</h2>
    <form class="formulario" @submit.prevent="crear">
      <input v-model="nueva.nombre" type="text" placeholder="Nombre" aria-label="Nombre" />
      <input
        v-model="nueva.descripcion"
        type="text"
        placeholder="Descripcion (opcional)"
        aria-label="Descripcion"
      />
      <input
        v-model.number="nueva.tiempo_respuesta_sugerido"
        type="number"
        min="1"
        placeholder="Horas de respuesta sugeridas"
        aria-label="Tiempo de respuesta"
      />
      <button type="submit" class="boton" :disabled="creando">Crear</button>
    </form>

    <ul v-if="errores.length > 0" class="errores">
      <li v-for="mensaje in errores" :key="mensaje">{{ mensaje }}</li>
    </ul>

    <h2>Categorias actuales</h2>
    <table class="tabla">
      <thead>
        <tr>
          <th>Nombre</th>
          <th>Descripcion</th>
          <th>Datos que pide</th>
          <th>Estado</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="categoria in categorias" :key="categoria.id">
          <td>{{ categoria.nombre }}</td>
          <td>{{ categoria.descripcion ?? '-' }}</td>
          <td>
            <span v-if="!categoria.tiene_campos">-</span>
            <ul v-else class="campos">
              <li v-for="campo in categoria.campos" :key="campo.clave">
                {{ campo.etiqueta }} ({{ campo.tipo }})<span v-if="campo.requerido"> *</span>
              </li>
            </ul>
          </td>
          <td>{{ categoria.activa ? 'Activa' : 'Inactiva' }}</td>
          <td>
            <button type="button" class="boton" @click="alternar(categoria)">
              {{ categoria.activa ? 'Desactivar' : 'Activar' }}
            </button>
          </td>
        </tr>
      </tbody>
    </table>

    <p class="ayuda">
      El asterisco marca los campos obligatorios. La edicion detallada de los
      campos por categoria queda pendiente para el siguiente corte.
    </p>
  </div>
</template>

<script setup lang="ts">
import type { Categoria } from '~/tipos/api';

definePageMeta({ middleware: 'auth' });

const nueva = reactive({ nombre: '', descripcion: '', tiempo_respuesta_sugerido: null as number | null });
const creando = ref(false);
const errores = ref<string[]>([]);

// `campos=1` pide la definicion de campos: sin esto, la API no la envia.
const { data, refresh } = await useFetch<Categoria[]>('/api/v1/admin/categorias', {
  query: { campos: 1 },
});

const categorias = computed(() => data.value ?? []);

async function crear() {
  creando.value = true;
  errores.value = [];

  try {
    await $fetch('/api/v1/admin/categorias', {
      method: 'POST',
      body: {
        nombre: nueva.nombre,
        descripcion: nueva.descripcion || null,
        tiempo_respuesta_sugerido: nueva.tiempo_respuesta_sugerido,
      },
    });

    nueva.nombre = '';
    nueva.descripcion = '';
    nueva.tiempo_respuesta_sugerido = null;

    await refresh();
  } catch (fallo) {
    errores.value = mensajesDeError(fallo);
  } finally {
    creando.value = false;
  }
}

async function alternar(categoria: Categoria) {
  errores.value = [];

  try {
    await $fetch(`/api/v1/admin/categorias/${categoria.id}`, {
      method: 'PATCH',
      body: { activa: !categoria.activa },
    });

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
</script>

<style scoped>
.formulario {
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
  vertical-align: top;
}

.campos {
  margin: 0;
  padding-left: 1rem;
  font-size: 0.85rem;
}

.boton {
  padding: 0.35rem 0.8rem;
  border: none;
  border-radius: 6px;
  background: #2b6cb0;
  color: #ffffff;
  cursor: pointer;
}

.errores {
  color: #991b1b;
  padding-left: 1.1rem;
}

.ayuda {
  color: #627d98;
  font-size: 0.9rem;
}

input {
  padding: 0.45rem 0.6rem;
  border: 1px solid #bcccdc;
  border-radius: 6px;
}
</style>