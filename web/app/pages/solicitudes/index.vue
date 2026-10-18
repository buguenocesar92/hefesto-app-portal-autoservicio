<template>
  <div>
    <h1>Mis solicitudes</h1>

    <div class="barra">
      <input
        v-model="busqueda"
        type="search"
        placeholder="Buscar por folio o titulo"
        aria-label="Buscar solicitudes"
      />

      <select v-model="estado" aria-label="Filtrar por estado">
        <option value="">Todos los estados</option>
        <option v-for="valor in estadosDisponibles" :key="valor" :value="valor">
          {{ valor }}
        </option>
      </select>

      <NuxtLink to="/solicitudes/nueva" class="boton">Nueva solicitud</NuxtLink>
    </div>

    <p v-if="cargando">Cargando...</p>

    <p v-else-if="!solicitudes.length" class="vacio">
      Todavia no tienes solicitudes. Cuando abras una, la vas a ver aqui.
    </p>

    <ul v-else class="lista">
      <li v-for="solicitud in solicitudes" :key="solicitud.id" class="tarjeta">
        <NuxtLink :to="`/solicitudes/${solicitud.id}`" class="tarjeta__enlace">
          <div class="tarjeta__cabecera">
            <span class="folio">{{ solicitud.folio }}</span>
            <EstadoSolicitud
              :estado="solicitud.estado"
              :etiqueta="solicitud.estado_etiqueta"
            />
          </div>
          <h2>{{ solicitud.titulo }}</h2>
          <p class="meta">
            {{ solicitud.categoria?.nombre ?? 'Sin categoria' }} ·
            {{ formatearFecha(solicitud.creado_en) }}
          </p>
        </NuxtLink>
      </li>
    </ul>

    <p v-if="meta && meta.total > 0" class="paginacion">
      Pagina {{ meta.current_page }} de {{ meta.last_page }} ({{ meta.total }} en total)
    </p>
  </div>
</template>

<script setup lang="ts">
import type { RecursoPaginado } from '~/tipos/api';

definePageMeta({ middleware: 'auth' });

const busqueda = ref('');
const estado = ref('');

const estadosDisponibles = [
  'recibida',
  'en_revision',
  'agendada',
  'en_ejecucion',
  'espera_cliente',
  'resuelta',
  'cerrada',
  'anulada',
];

// `useFetch` reenvia las cookies durante el render en el servidor: la API
// recibe la sesion y el listado sale ya filtrado por PyME.
const { data, pending: cargando } = await useFetch<RecursoPaginado>('/api/v1/solicitudes', {
  query: computed(() => ({
    q: busqueda.value || undefined,
    estado: estado.value || undefined,
  })),
  watch: [busqueda, estado],
});

const solicitudes = computed(() => data.value?.data ?? []);
const meta = computed(() => data.value?.meta ?? null);

function formatearFecha(valor: string | null): string {
  if (!valor) {
    return '';
  }

  return new Date(valor).toLocaleDateString('es-CL');
}
</script>

<style scoped>
.barra {
  display: flex;
  gap: 0.75rem;
  flex-wrap: wrap;
  align-items: center;
  margin-bottom: 1.25rem;
}

input,
select {
  padding: 0.45rem 0.6rem;
  border: 1px solid #bcccdc;
  border-radius: 6px;
}

.boton {
  margin-left: auto;
  padding: 0.45rem 0.9rem;
  border-radius: 6px;
  background: #2b6cb0;
  color: #ffffff;
  text-decoration: none;
}

.lista {
  list-style: none;
  padding: 0;
  display: grid;
  gap: 0.75rem;
}

.tarjeta {
  border: 1px solid #d9e2ec;
  border-radius: 8px;
  background: #ffffff;
}

.tarjeta__enlace {
  display: block;
  padding: 1rem;
  color: inherit;
  text-decoration: none;
}

.tarjeta__cabecera {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 1rem;
}

.folio {
  font-family: ui-monospace, monospace;
  font-size: 0.85rem;
  color: #627d98;
}

.meta,
.vacio {
  color: #627d98;
  font-size: 0.9rem;
}

.paginacion {
  margin-top: 1rem;
  font-size: 0.85rem;
  color: #627d98;
}
</style>