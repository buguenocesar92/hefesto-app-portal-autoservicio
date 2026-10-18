/**
 * Middleware de rutas que exigen sesion.
 *
 * Se aplica pagina por pagina con `definePageMeta({ middleware: 'auth' })`.
 * Si la API responde que no hay sesion, se va a la pantalla de entrada
 * guardando a donde queria ir la persona, para volver ahi despues.
 */
export default defineNuxtRouteMiddleware(async (to) => {
  // La pantalla de entrada no puede exigir lo que esta por starters.
  if (to.path === '/entrar') {
    return;
  }

  const { cargar, usuario } = useSesion();

  await cargar();

  if (!usuario.value) {
    return navigateTo({
      path: '/entrar',
      query: { redirigir_a: to.fullPath },
    });
  }
});