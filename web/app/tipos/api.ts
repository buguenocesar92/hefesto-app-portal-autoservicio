/**
 * Tipos que mirroran lo que devuelve la API de Laravel.
 *
 * Son deliberadamente una copia manual de lo que exponen los Resources de
 * la API (`app/Http/Resources`). Se mantienen a mano, y no con generacion
 * automatica, para que un cambio en la API se note como un error de
 * compilacion y no como un dato indefinido en pantalla.
 *
 * Si la API agrega un campo, se agrega aca.
 */

export interface MetaPaginada {
  current_page: number;
  from: number | null;
  last_page: number;
  per_page: number;
  to: number | null;
  total: number;
}

export interface RecursoPaginado<T> {
  data: T[];
  links?: Record<string, string>;
  meta: MetaPaginada;
}

export type EstadoSolicitud =
  | 'recibida'
  | 'en_revision'
  | 'agendada'
  | 'en_ejecucion'
  | 'espera_cliente'
  | 'resuelta'
  | 'cerrada'
  | 'anulada';

export type PrioridadSolicitud = 'baja' | 'normal' | 'alta' | 'urgente';

export interface UsuarioPortal {
  id: string;
  correo: string;
  nombre: string | null;
  apellido: string | null;
  nombre_completo: string;
  telefono: string | null;
  rol: 'cliente' | 'administrador';
  rol_etiqueta: string;
  es_administrador: boolean;
  activo: boolean;
  ultimo_acceso: string | null;
  creado_en: string | null;
}

export interface Solicitud {
  id: string;
  folio: string;
  titulo: string;
  descripcion: string;
  estado: EstadoSolicitud;
  estado_etiqueta: string;
  es_terminal: boolean;
  prioridad: PrioridadSolicitud;
  prioridad_etiqueta: string;
  datos: Record<string, unknown> | null;
  categoria: { id: string; nombre: string } | null;
  solicitante: { id: string; nombre_completo: string; correo: string } | null;
  asignado_a: string | null;
  cerrada_en: string | null;
  creado_en: string | null;
  actualizado_en: string | null;
  estados_siguientes: EstadoSolicitud[];
}

export interface EventoSolicitud {
  id: string;
  solicitud_id: string;
  estado_anterior: EstadoSolicitud | null;
  estado_nuevo: EstadoSolicitud;
  estado_nuevo_etiqueta: string;
  es_cambio_de_estado: boolean;
  comentario: string | null;
  autor: { id: string; nombre_completo: string; rol: 'cliente' | 'administrador' } | null;
  registrado_en: string | null;
}

export type TipoDocumento =
  | 'factura'
  | 'boleta'
  | 'orden_trabajo'
  | 'guia'
  | 'contrato'
  | 'otro';

export interface Documento {
  id: string;
  tipo: TipoDocumento;
  tipo_etiqueta: string;
  es_tributario: boolean;
  numero: string;
  detalle: string | null;
  emitido_en: string | null;
  monto_bruto: string | null;
  moneda: string;
  nombre_archivo: string;
  mime: string | null;
  tamano_bytes: number | null;
  es_dirigido: boolean;
  url_descarga: string;
  creado_en: string | null;
}

export type TipoCampoDinamico = 'texto' | 'numero' | 'fecha' | 'seleccion' | 'booleano';

export interface CampoDinamico {
  clave: string;
  etiqueta: string;
  tipo: TipoCampoDinamico;
  requerido?: boolean;
  ayuda?: string | null;
  opciones?: string[];
}

export interface Categoria {
  id: string;
  nombre: string;
  descripcion: string | null;
  tiempo_respuesta_sugerido: number | null;
  activa: boolean;
  tiene_campos: boolean;
  campos: CampoDinamico[];
}

export interface ResumenHistorial {
  solicitudes_totales: number;
  solicitudes_activas: number;
  documentos_totales: number;
}