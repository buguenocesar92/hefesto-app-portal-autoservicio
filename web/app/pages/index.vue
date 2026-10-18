<template>
  <div>
    <h1>Portal de autoservicio</h1>
    <p class="ayuda">
      Hola {{ usuario?.nombre_completo }}. Desde aqui puedes pedir una atencion,
      descargar documentos y revisar como va cada solicitud.
    </p>

    <section class="resumen">
      <div class="cifra">
        <span class="cifra__valor">{{ resumen?.solicitudes_activas ?? 0 }}</span>
        <span class="cifra__etiqueta">solicitudes activas</span>
      </div>
      <div class="cifra">
        <span class="cifra__valor">{{ resumen?.solicitudes_totales ?? 0 }}</span>
        <span class="cifra__etiqueta">solicitudes en total</span>
      </div>
      <div class="cifra">
        <span class="cifra__valor">{{ resumen?.documentos_totales ?? 0 }}</span>
        <span class="cifra__etiqueta">documentos disponibles</span>
      </div>
    </section>

    <section class="acciones">
      <NuxtLink to="/solicitudes/nueva" class="accion">
        <strong>Abrir una solicitud</strong>
        <span>Contanos que necesitas y tu PyME la toma desde aca.</span>
      </NuxtLink>
      <NuxtLink to="/documentos" class="accion">
        <strong>Descargar documentos</strong>
        <span>Facturas, boletas, ordenes de trabajo y contratos.</span>
      </NuxtLink>
      <NuxtLink to="/historial" class="accion">
        <strong>Ver mi historial</strong>
        <span>Todo lo que pediste, con fecha y estado.</span>
      </NuxtLink>
    </section>
  </div>
</template>

<script setup lang="ts">
import type { ResumenHistorial } from '~/tipos/api';

definePageMeta({ middleware: 'auth' });

const { usuario } = useSesion();

const { data } = await useFetch<{ resumen: ResumenHistorial }>('/api/v1/historial');

const resumen = computed(() => data.value?.resumen);
</script>

<style scoped>
.ayuda {
  color: #627d98;
}

.resumen {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
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
  font-size: 1.8rem;
  font-weight: 700;
  color: #243b53;
}

.cifra__etiqueta {
  font-size: 0.85rem;
  color: #627d98;
}

.acciones {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr));
  gap: 1rem;
}

.accion {
  border: 1px solid #d9e2ec;
  border-radius: 8px;
  padding: 1rem;
  text-decoration: none;
  color: inherit;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.accion span {
  font-size: 0.85rem;
  color: #627d98;
}
</style>