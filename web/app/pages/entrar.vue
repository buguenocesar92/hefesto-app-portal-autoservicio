<template>
  <div class="entrada">
    <h1>Entrar al portal</h1>

    <p v-if="mensajeError" class="alerta alerta--error">{{ mensajeError }}</p>

    <p v-else class="ayuda">
      Ingresa con el correo con el que tu PyME te registro. Si no sabes como
      entrar, pidele a tu PyME que te invite.
    </p>

    <form class="formulario" @submit.prevent="entrar">
      <label for="slug">Tu empresa</label>
      <input
        id="slug"
        v-model="slug"
        name="slug"
        type="text"
        autocomplete="organization"
        placeholder="panaderia-las-tortas"
        :disabled="enviando"
      />
      <p class="ayuda">
        Es el nombre corto que aparece en el enlace que te dio tu PyME. Si
        prefieres, lo dejamos vacio y lo buscaremos por tu correo.
      </p>

      <button type="submit" class="boton" :disabled="enviando">
        {{ enviando ? 'Redirigiendo...' : 'Continuar' }}
      </button>
    </form>

    <p v-if="error" class="alerta alerta--error">{{ error }}</p>
  </div>
</template>

<script setup lang="ts">
/**
 * Pantalla de entrada.
 *
 * El flujo real es de dos pasos y esta pagina solo inicia el primero:
 *
 *  1. Se pide a la API la URL de Authentik (`GET /api/v1/auth/login`).
 *  2. El navegador navega ahi y la persona se autentica.
 *  3. Authentik devuelve al BFF, que redirige a la pagina pedida.
 *
 * Esta pagina nunca ve un token: la API abre la sesion con una cookie
 * HttpOnly que el navegador no puede leer.
 */
const ruta = useRoute();

const slug = ref(typeof ruta.query.slug === 'string' ? ruta.query.slug : '');
const redirigirA = ref(typeof ruta.query.redirigir_a === 'string' ? ruta.query.redirigir_a : '');
const enviando = ref(false);
const error = ref<string | null>(null);

const mensajeError = computed(() => {
  if (typeof ruta.query.error === 'string') {
    return ruta.query.error;
  }

  return error.value;
});

async function entrar() {
  enviando.value = true;
  error.value = null;

  try {
    const respuesta = await $fetch<{ url: string }>('/api/v1/auth/login', {
      method: 'GET',
      query: {
        slug: slug.value.trim() || undefined,
        redirigir_a: redirigirA.value || undefined,
      },
    });

    await navigateTo(respuesta.url, { external: true });
  } catch (fallo) {
    error.value = 'No pudimos conectarte con el servicio de acceso. Intenta de nuevo en unos segundos.';
    console.error(fallo);
  } finally {
    enviando.value = false;
  }
}
</script>

<style scoped>
.entrada {
  max-width: 32rem;
  margin: 2rem auto;
}

.formulario {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  margin-top: 1.5rem;
}

input {
  padding: 0.6rem 0.7rem;
  border: 1px solid #bcccdc;
  border-radius: 6px;
  font-size: 1rem;
}

.boton {
  margin-top: 1rem;
  padding: 0.6rem 1rem;
  border: none;
  border-radius: 6px;
  background: #2b6cb0;
  color: #ffffff;
  font-size: 1rem;
  cursor: pointer;
}

.boton:disabled {
  background: #9fb3c8;
  cursor: not-allowed;
}

.ayuda {
  font-size: 0.85rem;
  color: #627d98;
  margin: 0.25rem 0 0;
}

.alerta {
  padding: 0.7rem 0.9rem;
  border-radius: 6px;
  font-size: 0.9rem;
}

.alerta--error {
  background: #fee2e2;
  color: #991b1b;
}
</style>