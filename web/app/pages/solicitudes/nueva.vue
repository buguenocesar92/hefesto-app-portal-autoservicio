<template>
  <div>
    <h1>Nueva solicitud</h1>

    <p class="ayuda">
      Cuantos mas detalles des, mas rapido te va a poder responder tu PyME.
    </p>

    <form class="formulario" @submit.prevent="enviar">
      <label for="categoria">Que necesitas</label>
      <select id="categoria" v-model="formulario.categoria_id" :disabled="enviando">
        <option value="">Sin categoria</option>
        <option v-for="categoria in categorias" :key="categoria.id" :value="categoria.id">
          {{ categoria.nombre }}
        </option>
      </select>

      <p v-if="categoriaElegida?.descripcion" class="ayuda">
        {{ categoriaElegida.descripcion }}
        <template v-if="categoriaElegida.tiempo_respuesta_sugerido">
          Tiempo de respuesta sugerido:
          {{ categoriaElegida.tiempo_respuesta_sugerido }} horas.
        </template>
      </p>

      <label for="titulo">Titulo</label>
      <input
        id="titulo"
        v-model="formulario.titulo"
        type="text"
        maxlength="160"
        placeholder="Por ejemplo: el refrigerador suena fuerte"
        :disabled="enviando"
      />

      <label for="descripcion">Describe el problema</label>
      <textarea
        id="descripcion"
        v-model="formulario.descripcion"
        rows="5"
        maxlength="5000"
        placeholder="Cuando pasa, desde cuando, y que has probado."
        :disabled="enviendo"
      />

      <label for="prioridad">Urgencia</label>
      <select id="prioridad" v-model="formulario.prioridad" :disabled="enviando">
        <option value="baja">Baja</option>
        <option value="normal">Normal</option>
        <option value="alta">Alta</option>
        <option value="urgente">Urgente</option>
      </select>

      <fieldset v-if="camposDinamicos.length > 0">
        <legend>Datos que necesitas</legend>
        <p class="ayuda">Tu PyME necesita estos datos para atenderte.</p>

        <div v-for="campo in camposDinamicos" :key="campo.clave" class="campo">
          <label :for="campo.clave">
            {{ campo.etiqueta }}
            <span v-if="campo.requerido" aria-hidden="true">(obligatorio)</span>
          </label>

          <select
            v-if="campo.tipo === 'seleccion'"
            :id="campo.clave"
            v-model="datos[campo.clave]"
            :disabled="enviando"
          >
            <option value="">Sin especificar</option>
            <option v-for="opcion in campo.opciones ?? []" :key="opcion" :value="opcion">
              {{ opcion }}
            </option>
          </select>

          <input
            v-else-if="campo.tipo === 'numero'"
            :id="campo.clave"
            v-model.number="datos[campo.clave]"
            type="number"
            step="any"
            :disabled="enviando"
          />

          <input
            v-else-if="campo.tipo === 'fecha'"
            :id="campo.clave"
            v-model="datos[campo.clave]"
            type="date"
            :disabled="enviando"
          />

          <label v-else-if="campo.tipo === 'booleano'" class="casilla">
            <input v-model="datos[campo.clave]" type="checkbox" :disabled="enviando" />
            Si
          </label>

          <input
            v-else
            :id="campo.clave"
            v-model="datos[campo.clave]"
            type="text"
            :disabled="enviando"
          />

          <p v-if="campo.ayuda" class="ayuda">{{ campo.ayuda }}</p>
        </div>
      </fieldset>

      <ul v-if="errores.length > 0" class="errores">
        <li v-for="mensaje in errores" :key="mensaje">{{ mensaje }}</li>
      </ul>

      <button type="submit" class="boton" :disabled="enviando">
        {{ enviando ? 'Enviando...' : 'Enviar solicitud' }}
      </button>
    </form>
  </div>
</template>

<script setup lang="ts">
import type { Categoria } from '~/tipos/api';

definePageMeta({ middleware: 'auth' });

const formulario = reactive({
  categoria_id: '',
  titulo: '',
  descripcion: '',
  prioridad: 'normal',
});

// Los datos que pide cada categoria. Se inicializan vacios para que el
// formulario no los muestre "undefined".
const datos = reactive<Record<string, string | number | boolean | null>>({});

const enviando = ref(false);
const errores = ref<string[]>([]);

// `campos=1` le pide a la API que mande la definicion de campos, que en los
// listados viene oculta para no pesar.
const { data } = await useFetch<Categoria[]>('/api/v1/categorias', {
  query: { campos: 1 },
});

const categorias = computed<Categoria[]>(() => data.value ?? []);
const categoriaElegida = computed(() => categorias.value.find((c) => c.id === formulario.categoria_id));
const camposDinamicos = computed(() => categoriaElegida.value?.campos ?? []);

watch(categoriaElegida, (categoria) => {
  for (const clave of Object.keys(datos)) {
    delete datos[clave];
  }

  for (const campo of categoria?.campos ?? []) {
    datos[campo.clave] = campo.tipo === 'booleano' ? false : '';
  }
});

async function enviar() {
  enviando.value = true;
  errores.value = [];

  try {
    const respuesta = await $fetch<{ data: { id: string } }>('/api/v1/solicitudes', {
      method: 'POST',
      body: {
        categoria_id: formulario.categoria_id || null,
        titulo: formulario.titulo,
        descripcion: formulario.descripcion,
        prioridad: formulario.prioridad,
        datos: Object.keys(datos).length > 0 ? { ...datos } : null,
      },
    });

    await navigateTo(`/solicitudes/${respuesta.data.id}`);
  } catch (fallo) {
    errores.value = mensajesDeValidacion(fallo);
  } finally {
    enviando.value = false;
  }
}

/**
 * Traduce la respuesta de validacion de Laravel a una lista de mensajes.
 *
 * Laravel devuelve `errors: { "campo": ["mensaje"] }`. Se aplana para que la
 * persona lea que falta sin tener que descifrar nombres de campo.
 */
function mensajesDeValidacion(fallo: unknown): string[] {
  const respuesta = fallo as {
    data?: { data?: { mensaje?: string; errors?: Record<string, string[]> } };
    statusMessage?: string;
  };

  const cuerpo = respuesta.data?.data ?? respuesta.data;

  if (cuerpo?.errors) {
    return Object.values(cuerpo.errors).flat();
  }

  return [cuerpo?.mensaje ?? respuesta.statusMessage ?? 'No se pudo enviar la solicitud.'];
}
</script>

<style scoped>
.formulario {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  max-width: 42rem;
}

input,
select,
textarea {
  padding: 0.55rem 0.7rem;
  border: 1px solid #bcccdc;
  border-radius: 6px;
  font-size: 1rem;
  font-family: inherit;
}

fieldset {
  border: 1px solid #d9e2ec;
  border-radius: 8px;
  margin: 1rem 0 0;
}

.campo {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  margin-top: 0.75rem;
}

.casilla {
  display: flex;
  flex-direction: row;
  align-items: center;
  gap: 0.4rem;
}

.boton {
  margin-top: 1.25rem;
  padding: 0.6rem 1rem;
  border: none;
  border-radius: 6px;
  background: #2b6cb0;
  color: #ffffff;
  font-size: 1rem;
  cursor: pointer;
  align-self: flex-start;
}

.boton:disabled {
  background: #9fb3c8;
}

.ayuda {
  font-size: 0.85rem;
  color: #627d98;
  margin: 0.15rem 0 0;
}

.errores {
  margin: 1rem 0 0;
  padding-left: 1.1rem;
  color: #991b1b;
}
</style>