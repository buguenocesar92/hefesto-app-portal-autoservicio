<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
|-------------------------------------------------------------------------------
| Comandos de consola programados
|-------------------------------------------------------------------------------
|
| Este archivo se carga desde bootstrap/app.php. Los comandos de artisan que
| escribimos nosotros (portal:verificar-aislamiento, portal:olvidar-cache-oidc)
| se descubren solos en app/Console/Commands; no hay que registrarlos aca.
|
| En este primer corte NO hay tareas programadas a proposito: el portal no
| tiene trabajos diferidos ni cobros. Lo que si conviene correr de forma
| periodica, en el despliegue, es:
|
|   php artisan session:table prune      -- limpia sesiones vencidas
|   php artisan queue:prune-batches      -- recorta lotes viejos de la cola
|
| Ver docs/07, seccion "Mantenimiento periodico". Si mas adelante se agregan
| trabajos encolados, se programan aca con Schedule::job() o Schedule::command().
|
*/
Schedule::command('auth:clear-resets')->everyFifteenMinutes();

Schedule::command('queue:prune-batches --hours=168')->daily();