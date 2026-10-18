<template>
  <div>
    <h1>Documentos</h1>
    <p class="ayuda">
      Documentos que tu PyME ya emitio. Descargarlos aqui no los cambia en
      ningun sistema del SII: es solo una copia.
    </p>

    <div class="barra">
      <select v-model="tipo" aria-label="Filtrar por tipo">
        <option value="">Todos los tipos</option>
        <option v-for="valor in tiposDisponibles" :key="valor" :value="valor">
          {{ valor }}
        </option>
      </select>
      <input v-model="desde" type="date" aria-label="Desde" />
      <input v-model="hasta" type="date" aria-label="Hasta" />
    </div>

    <p v-if="cargando">Cargando...</p>
    <p v-else-if="!documentos.length" class="vacio">Tu PyME todavia no publico documentos.</p>

    <table v-else class="tabla">
      <thead>
        <tr>
          <th>Tipo</th>
          <th>Numero</th>
          <th>Detalle</th>
          <th>Emitido</th>
          <th>Monto</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="documento in documentos" :key="documento.id">
          <td>{{ documento.tipo_etiqueta }}</td>
          <td class="mono">{{ documento.numero }}</td>
          <td>{{ documento.detalle ?? '-' }}</td>
          <td>{{ documento.emitido_en ?? '-' }}</td>
          <td>
            <template v-if="documento.monto_bruto">
              {{ formatearMonto(documento.monto_bruto, documento.moneda) }}
            </template>
            <template v-else>-</template>
          </td>
          <td>
            <a :href="documento.url_descarga" class="boton" download>Descargar</a>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<script setup lang="ts">
import type { Documento, RecursoPaginado } from '~/tipos/api';

definePageMeta({ middleware: 'auth' });

const tiposDisponibles = [
  'factura',
  'boleta',
  'orden_trabajo',
  'guia',
  'contrato',
  'otro',
];

const tipo = ref('');
const desde = ref('');
const hasta = ref('');

const { data, pending: cargando } = await useFetch<RecursoPaginado<Documento>>('/api/v1/documentos', {
  query: computed(() => ({
    tipo: tipo.value || undefined,
    desde: desde.value || undefined,
    hasta: hasta.value || undefined,
  })),
  watch: [tipo, desde, hasta],
});

const documentos = computed(() => data.value?.data ?? []);

function formatearMonto(valor: string, moneda: string): string {
  const numero = Number(valor);

  if (!Number.isFinite(numero)) {
    return `${valor} ${moneda}`;
  }

  return `${new Intl.NumberFormat('es-CL').format(numero)} ${moneda}`;
}
</script>

<style scoped>
.barra {
  display: flex;
  gap: 0.6rem;
  flex-wrap: wrap;
  margin-bottom: 1rem;
}

.tabla {
  width: 100%;
  border-collapse: collapse;
}

.tabla th,
.tabla td {
  text-align: left;
  padding: 0.55rem 0.5rem;
  border-bottom: 1px solid #e4eaf1;
  font-size: 0.92rem;
}

.mono {
  font-family: ui-monospace, monospace;
}

.boton {
  display: inline-block;
  padding: 0.3rem 0.7rem;
  border: 1px solid #2b6cb0;
  border-radius: 6px;
  color: #2b6cb0;
  text-decoration: none;
  font-size: 0.85rem;
}

.ayuda,
.vacio {
  color: #627d98;
  font-size: 0.9rem;
}
</style>