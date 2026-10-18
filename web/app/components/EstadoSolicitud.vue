<template>
  <span class="estado" :class="`estado--${tono}`">{{ etiqueta }}</span>
</template>

<script setup lang="ts">
/**
 * Distintivo visual del estado de una solicitud.
 *
 * El tono se decide por el VALOR del estado que manda la API, no por el
 * texto de la etiqueta: asi, si la API agrega un estado nuevo, esta pieza
 * cae en el tono neutro en vez de romperse.
 */
const props = defineProps<{ estado: string; etiqueta: string }>();

const tono = computed(() => {
  switch (props.estado) {
    case 'recibida':
    case 'en_revision':
      return 'neutro';
    case 'agendada':
    case 'en_ejecucion':
      return 'aviso';
    case 'espera_cliente':
      return 'pendiente';
    case 'resuelta':
      return 'exito';
    case 'cerrada':
      return 'cerrado';
    case 'anulada':
      return 'error';
    default:
      return 'neutro';
  }
});
</script>

<style scoped>
.estado {
  display: inline-block;
  padding: 0.15rem 0.55rem;
  border-radius: 999px;
  font-size: 0.8rem;
  font-weight: 600;
  border: 1px solid transparent;
}

.estado--neutro {
  background: #e4ecf7;
  color: #243b53;
}

.estado--aviso {
  background: #dbeafe;
  color: #1e40af;
}

.estado--pendiente {
  background: #fef3c7;
  color: #92400e;
}

.estado--exito {
  background: #dcfce7;
  color: #166534;
}

.estado--cerrado {
  background: #e5e7eb;
  color: #374151;
}

.estado--error {
  background: #fee2e2;
  color: #991b1b;
}
</style>