<template>
  <div class="portal">
    <header class="portal__cabecera">
      <div class="portal__marca">
        <strong>Portal de Autoservicio</strong>
        <span v-if="nombrePyme" class="portal__pyme">{{ nombrePyme }}</span>
      </div>

      <nav v-if="usuario" class="portal__menu">
        <NuxtLink to="/solicitudes">Mis solicitudes</NuxtLink>
        <NuxtLink to="/documentos">Documentos</NuxtLink>
        <NuxtLink to="/historial">Historial</NuxtLink>
        <template v-if="esAdministrador">
          <NuxtLink to="/admin/usuarios">Personas</NuxtLink>
          <NuxtLink to="/admin/categorias">Categorias</NuxtLink>
        </template>
      </nav>

      <div v-if="usuario" class="portal__usuario">
        <span>{{ usuario.nombre_completo }}</span>
        <button type="button" class="boton boton--discreto" @click="salir">Salir</button>
      </div>
    </header>

    <main class="portal__contenido">
      <slot />
    </main>

    <footer class="portal__pie">
      <p>
        Aplicacion de Hefesto Studio. Si necesitas una atencion urgente, llama
        directamente a tu PyME.
      </p>
    </footer>
  </div>
</template>

<script setup lang="ts">
const { usuario, esAdministrador, nombrePyme, cargar, salir } = useSesion();

// El menu depende de quien esta conectado. Se carga una sola vez por visita:
// el resto de las paginas ya la piden por su cuenta con el middleware.
await callOnce('cargar-sesion', cargar);
</script>

<style scoped>
.portal {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
  color: #1f2933;
}

.portal__cabecera {
  display: flex;
  align-items: center;
  gap: 1.5rem;
  flex-wrap: wrap;
  padding: 1rem 1.5rem;
  border-bottom: 1px solid #d9e2ec;
  background: #ffffff;
}

.portal__marca {
  display: flex;
  flex-direction: column;
}

.portal__pyme {
  font-size: 0.85rem;
  color: #627d98;
}

.portal__menu {
  display: flex;
  gap: 1rem;
  flex-wrap: wrap;
}

.portal__menu a {
  color: #334e68;
  text-decoration: none;
  padding: 0.25rem 0;
  border-bottom: 2px solid transparent;
}

.portal__menu a.router-link-active {
  border-bottom-color: #2b6cb0;
  font-weight: 600;
}

.portal__usuario {
  margin-left: auto;
  display: flex;
  align-items: center;
  gap: 0.75rem;
  font-size: 0.9rem;
}

.portal__contenido {
  flex: 1;
  width: 100%;
  max-width: 68rem;
  margin: 0 auto;
  padding: 1.5rem;
}

.portal__pie {
  border-top: 1px solid #d9e2ec;
  padding: 1rem 1.5rem;
  font-size: 0.8rem;
  color: #627d98;
}
</style>