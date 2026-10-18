/**
 * Estado de la sesion del portal.
 *
 * La API de Laravel es la unica que sabe si la persona esta conectada. Esta
 * composable no guarda ningun dato de autorizacion propio: solo reflecte lo
 * que la API responde. Si alguien manipulara este estado en el navegador,
 * no ganaria nada: cada peticion vuelve a preguntar a la API.
 */
export interface UsuarioPortal {
  id: string;
  correo: string;
  nombre: string | null;
  apellido: string | null;
  nombre_completo: string;
  rol: 'cliente' | 'administrador';
  rol_etiqueta: string;
  es_administrador: boolean;
  activo: boolean;
}

export interface DatosSesion {
  usuario: UsuarioPortal;
  pymes: { slug: string; nombre: string };
}

export function useSesion() {
  const sesion = useState<DatosSesion | null>('sesion', () => null);
  const cargando = useState<boolean>('sesion-cargando', () => false);
  const error = useState<string | null>('sesion-error', () => null);

  /**
   * Pregunta a la API quien esta conectado.
   *
   * En el servidor usa `useRequestFetch()`, que reenvia las cookies del
   * navegador al BFF. Sin eso, la primera carga en modo SSR siempre
   * responderia "no conectado" y la pagina de entrada apareceria siempre.
   */
  async function cargar(): Promise<DatosSesion | null> {
    cargando.value = true;
    error.value = null;

    try {
      const respuesta = await useRequestFetch()<DatosSesion>('/api/v1/auth/usuario');
      sesion.value = respuesta;
    } catch {
      sesion.value = null;
    } finally {
      cargando.value = false;
    }

    return sesion.value;
  }

  async function salir(): Promise<void> {
    await $fetch('/api/v1/auth/logout', { method: 'POST' });
    sesion.value = null;

    await navigateTo('/entrar');
  }

  const usuario = computed<UsuarioPortal | null>(() => sesion.value?.usuario ?? null);
  const esAdministrador = computed<boolean>(() => usuario.value?.es_administrador === true);
  const nombrePyme = computed<string>(() => sesion.value?.pymes.nombre ?? '');

  return { sesion, usuario, esAdministrador, nombrePyme, cargando, error, cargar, salir };
}