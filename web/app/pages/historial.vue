<template>
  <div>
    <h1>Historial</h1>

    <section class="resumen">
      <div class="cifra">
        <span class="cifra__valor">{{ datos?.resumen.solicitudes_totales ?? 0 }}</span>
        <span class="cifra__etiqueta">solicitudes</span>
      </div>
      <div class="cifra">
        <span class="cifra__valor">{{ datos?.resumen.solicitudes_activas ?? 0 }}</span>
        <span class="cifra__etiqueta">activas</span>
      </div>
      <div class="cifra">
        <span class="cifra__valor">{{ datos?.resumen.documentos_totales ?? 0 }}</span>
        <span class="cifra__etiqueta">documentos</span>
      </div>
    </section>

    <h2>Solicitudes</h2>
    <ul class="lista">
      <li v-for="solicitud in datos?.solicitudes.data ?? []" :key="solicitud.id">
        <NuxtLink :to="`/solicitudes/${solicitud.id}`">
          <span class="mono">{{ solicitud.folio }}</span>
          {{ solicitud.titulo }}
        </NuxtLink>
        <EstadoSolicitud :estado="solicitud.estado" :etiqueta="solicitud.estado_etiqueta" />
      </li>
    </ul>
    <p v-if="!datos?.solicitudes.data.length" class="vacio">Todavia no hay solicitudes.</p>

    <h2>Documentos</h2>
    <ul class="lista">
      <li v-for="documento in datos?.documentos.data ?? []" :key="documento.id">
        <a :href="documento.url_descarga" download>
          <span class="mono">{{ documento.numero }}</span>
          {{ documento.tipo_etiqueta }} {{ documento.detalle ?? '' }}
        </a>
      </li>
    </ul>
    <p v-if="!datos?.documentos.data.length" class="vacio">Todavia no hay documentos.</p>
  </div>
</template>

<script setup lang="ts">
import type { Documento, RecursoPaginado, ResumenHistorial, Solicitud } from '~/tipos/api';

definePageMeta({ middleware: 'auth' });

interface Historial {
  resumen: ResumenHistorial;
  solicitudes: RecursoPaginado<Solicitud>;
  documentos: RecursoPaginado<Documento>;
}

const { data } = await useFetch<Historial>('/api/v1/historial');
</script>

<style scoped>
.resumen {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr));
  gap: 1rem;
  margin: 1.5rem 0;
}

.cifra {
  border: 1px solid #d9e2ec;
  border-radius: 8px;
  padding: 1rem;
  display: flex;
  flex-direction: column;
}

.cifra__valor {
  font-size: 1.6rem;
  font-weight: 700;
}

.cifra__etiqueta {
  font-size: 0.85rem;
  color: #627d98;
}

.lista {
  list-style: none;
  padding: 0;
  display: grid;
  gap: 0.5rem;
}

.lista li {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 1rem;
  border-bottom: 1px solid #e4eaf1;
  padding: 0.4rem 0;
}

.mono {
  font-family: ui-monospace, monospace;
  font-size: 0.85rem;
  color: #627d98;
  margin-right: 0.5rem;
}

.vacio {
  color: #627d98;
  font-size: 0.9rem;
}
</style>