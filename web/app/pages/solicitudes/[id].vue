<template>
  <div>
    <NuxtLink to="/solicitudes" class="volver">Volver a mis solicitudes</NuxtLink>

    <div v-if="solicitud" class="detalle">
      <header class="detalle__cabecera">
        <div>
          <span class="folio">{{ solicitud.folio }}</span>
          <h1>{{ solicitud.titulo }}</h1>
        </div>
        <EstadoSolicitud :estado="solicitud.estado" :etiqueta="solicitud.estado_etiqueta" />
      </header>

      <dl class="datos">
        <div>
          <dt>Categoria</dt>
          <dd>{{ solicitud.categoria?.nombre ?? 'Sin categoria' }}</dd>
        </div>
        <div>
          <dt>Urgencia</dt>
          <dd>{{ solicitud.prioridad_etiqueta }}</dd>
        </div>
        <div>
          <dt>Abierta</dt>
          <dd>{{ formatearFecha(solicitud.creado_en) }}</dd>
        </div>
        <div v-if="solicitud.cerrada_en">
          <dt>Cerrada</dt>
          <dd>{{ formatearFecha(solicitud.cerrada_en) }}</dd>
        </div>
      </dl>

      <section>
        <h2>Descripcion</h2>
        <p class="descripcion">{{ solicitud.descripcion }}</p>
      </section>

      <section v-if="Object.keys(solicitud.datos ?? {}).length > 0">
        <h2>Datos que entregaste</h2>
        <dl class="datos">
          <div v-for="(valor, clave) in solicitud.datos ?? {}" :key="clave">
            <dt>{{ clave }}</dt>
            <dd>{{ valor }}</dd>
          </div>
        </dl>
      </section>

      <section>
        <h2>Seguimiento</h2>

        <ol class="linea">
          <li v-for="evento in eventos" :key="evento.id">
            <div class="linea__marca">
              <EstadoSolicitud
                :estado="evento.estado_nuevo"
                :etiqueta="evento.estado_nuevo_etiqueta"
              />
            </div>
            <div class="linea__detalle">
              <p v-if="evento.es_cambio_de_estado" class="linea__cambio">
                De {{ evento.estado_anterior }} a {{ evento.estado_nuevo_etiqueta }}
              </p>
              <p v-if="evento.comentario">{{ evento.comentario }}</p>
              <p class="linea__meta">
                {{ evento.autor?.nombre_completo ?? 'Sistema' }} ·
                {{ formatearFecha(evento.registrado_en) }}
              </p>
            </div>
          </li>
        </ol>

        <form class="comentario" @submit.prevent="comentar">
          <label for="comentario">Responder</label>
          <textarea
            id="comentario"
            v-model="nuevoComentario"
            rows="3"
            maxlength="1000"
            placeholder="Escribe una respuesta o agrega informacion."
          />
          <button type="submit" class="boton" :disabled="enviandoComentario">
            {{ enviandoComentario ? 'Enviando...' : 'Enviar' }}
          </button>
        </form>
      </section>

      <section v-if="esAdministrador && solicitudesSiguientes.length > 0" class="admin">
        <h2>Mover el estado</h2>
        <div class="admin__barra">
          <select v-model="estadoNuevo">
            <option v-for="valor in solicitudesSiguientes" :key="valor" :value="valor">
              {{ valor }}
            </option>
          </select>
          <input v-model="comentarioEstado" type="text" placeholder="Comentario (opcional)" />
          <button type="button" class="boton" :disabled="cambiandoEstado" @click="cambiarEstado">
            Aplicar
          </button>
        </div>
      </section>
    </div>
  </div>
</template>

<script setup lang="ts">
import type { EventoSolicitud, RecursoPaginado, Solicitud, EstadoSolicitud as Estado } from '~/tipos/api';

definePageMeta({ middleware: 'auth' });

const ruta = useRoute();
const { esAdministrador } = useSesion();

const id = String(ruta.params.id);

const { data: solicitud } = await useFetch<{ data: Solicitud }>(`/api/v1/solicitudes/${id}`);

const { data: historial, refresh: refrescarEventos } = await useFetch<RecursoPaginado<EventoSolicitud>>(
  `/api/v1/solicitudes/${id}/eventos`,
);

const eventos = computed(() => historial.value?.data ?? []);
const solicitudesSiguientes = computed<Estado[]>(() => solicitud.value?.data.estados_siguientes ?? []);

const nuevoComentario = ref('');
const enviandoComentario = ref(false);

const estadoNuevo = ref<Estado | ''>('');
const comentarioEstado = ref('');
const cambiandoEstado = ref(false);

// El menu de estados se arma con lo que la API permite desde el estado
// actual: la lista no se inventa en el navegador.
watch(
  solicitudesSiguientes,
  (opciones) => {
    estadoNuevo.value = opciones[0] ?? '';
  },
  { immediate: true },
);

async function comentar() {
  enviandoComentario.value = true;

  try {
    await $fetch(`/api/v1/solicitudes/${id}/eventos`, {
      method: 'POST',
      body: { comentario: nuevoComentario.value },
    });

    nuevoComentario.value = '';
    await refrescarEventos();
  } finally {
    enviandoComentario.value = false;
  }
}

async function cambiarEstado() {
  if (!estadoNuevo.value) {
    return;
  }

  cambiandoEstado.value = true;

  try {
    await $fetch(`/api/v1/solicitudes/${id}/estado`, {
      method: 'PATCH',
      body: {
        estado: estadoNuevo.value,
        comentario: comentarioEstado.value || null,
      },
    });

    comentarioEstado.value = '';
    await refrescarEventos();
    await refreshNuxtData();
  } finally {
    cambiandoEstado.value = false;
  }
}

function formatearFecha(valor: string | null): string {
  if (!valor) {
    return '';
  }

  return new Date(valor).toLocaleString('es-CL');
}
</script>

<style scoped>
.volver {
  display: inline-block;
  margin-bottom: 1rem;
  color: #2b6cb0;
}

.detalle__cabecera {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1rem;
  flex-wrap: wrap;
}

.folio {
  font-family: ui-monospace, monospace;
  font-size: 0.85rem;
  color: #627d98;
}

.datos {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
  gap: 0.75rem;
}

.datos dt {
  font-size: 0.78rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: #829ab1;
}

.datos dd {
  margin: 0.15rem 0 0;
}

.descripcion {
  white-space: pre-line;
  background: #f8fafc;
  border: 1px solid #e4eaf1;
  border-radius: 8px;
  padding: 1rem;
}

.linea {
  list-style: none;
  padding: 0;
  display: grid;
  gap: 0.75rem;
}

.linea li {
  display: flex;
  gap: 0.75rem;
  align-items: flex-start;
}

.linea__meta {
  font-size: 0.8rem;
  color: #829ab1;
  margin: 0.2rem 0 0;
}

.comentario {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  margin-top: 1.5rem;
  max-width: 42rem;
}

textarea,
input,
select {
  padding: 0.55rem 0.7rem;
  border: 1px solid #bcccdc;
  border-radius: 6px;
  font-family: inherit;
  font-size: 1rem;
}

.boton {
  padding: 0.5rem 1rem;
  border: none;
  border-radius: 6px;
  background: #2b6cb0;
  color: #ffffff;
  cursor: pointer;
  align-self: flex-start;
}

.admin {
  margin-top: 2rem;
  border-top: 1px solid #d9e2ec;
  padding-top: 1rem;
}

.admin__barra {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
}
</style>