# ADAPTIA: integración local con Moodle

El código de la pasarela está en `ambiente-local/backend` y el plugin en
`public/local/adaptia_core`. Utiliza formularios, capacidades, persistencia y
recursos nativos de Moodle. No modifica el tema, el login ni otros plugins.

## Guías técnicas

- [Misión Down: contrato, configuración y validación E2E](docs/mision-down/README.md).
- [TutorIA: manual y alcance del servicio](docs/tutoria/README.md).

## Repositorio

Fork: [jAndresSagbay95/moodle](https://github.com/jAndresSagbay95/moodle), derivado
del Moodle oficial. `origin` apunta al fork y `upstream` al proyecto oficial,
con push deshabilitado. La integración Down se mantiene en la rama
`integracion/mision-down-qa-2026-11-23`. Los cambios pertenecen al plugin y al
entorno de integración; el núcleo Moodle se conserva.

## TutorIA dentro de un curso

El [manual visual de TutorIA](docs/tutoria/README.md) enlaza el PDF autónomo de
20 páginas, con capturas de Playwright señaladas, ejemplos paso a paso y diagramas
de casos de uso, componentes, despliegue, secuencia, estados y datos.

El docente entra al curso y abre **Más → TutorIA · Contenido**. La página
`/local/adaptia_core/tutoria.php?courseid=<id>` permite solicitar texto con tema,
dificultad1–10, presentación, objetivo y conceptos de un perfil ficticio. Utiliza el
cliente real de servicio descrito debajo. No publica actividades automáticamente.

La implementación sigue las APIs del plugin existente y los patrones de Moodle:

| Responsabilidad | API y ubicación |
| --- | --- |
| Acceso | Contexto de curso y capacidad `local/adaptia_core:generatetutoria` en `db/access.php`. |
| Formulario | Forms API, validación servidor y sesskey en `classes/form/tutoria_form.php`. |
| Coordinación | `classes/local/tutoria_service.php`: autorización, snapshot inmutable y Lock API antes de enviar. |
| Persistencia | `classes/local/tutoria_request.php`, mediante `core\persistent`, tabla XMLDB `local_adaptia_tutoria` y actualización aditiva del plugin. |
| Salida | Renderable, renderer y plantilla Mustache en `classes/output/` y `templates/`, con textos escapados y cadenas en inglés/español. |
| Privacidad | El proveedor existente declara los datos y permite descubrir, exportar y borrar registros propios por curso/usuario. Los observers limpian asociaciones al eliminar usuarios o cursos. |

La reserva se guarda antes de HTTP y la sesión Moodle se libera durante la llamada.
Después del POST se redirige a un GET: recargar o repetir el mismo formulario conserva
la solicitud, sin otro envío automático. Un fallo se muestra como fallo, conserva sus
datos y aparece en las últimas20 solicitudes del docente en ese curso. No se comparte
el historial entre docentes. La cuota de generación sigue reservándose solo en TutorIA.

Plugin `local_adaptia_core` versión `2026111901` / `0.6.0`. Aplicar sus cambios con
el CLI nativo de Moodle:

```sh
docker compose exec -T -u www-data moodle php /var/www/html/admin/cli/upgrade.php --non-interactive --allow-unstable
```

El E2E `tests/tutoria_ui.cjs` verifica el recorrido con cuentas ficticias dedicadas,
que elimina al terminar. El503 se recibe del TutorIA real con el LLM apagado. El
renderizado de éxito y el escape de contenido se verifican con un fixture señalado;
no se presentan como una generación real. Las capturas y el reporte se guardan fuera
del repositorio en `/private/tmp/adaptia-tutoria-ui`.

Referencias de diseño: [convenciones de Moodle](https://moodledev.io/general/development/policies/codingstyle),
[APIs del núcleo](https://docs.moodle.org/dev/Core_APIs) y
[Privacy API](https://moodledev.io/docs/6.0/apis/subsystems/privacy), además del código
de la versión6.0dev de este checkout. Se conserva la organización del plugin local;
no se añade otro frontend ni se modifica el núcleo de Moodle.

## Conexión de servicio con TutorIA

`POST /api/v1/tutoria/generate-content` ya conecta esta pasarela con la API real de
Patricio. Reemplaza el antiguo501. Es independiente del recorrido **Ejecutar MOCK**,
que conserva sus fixtures y su identificación de simulación.

La clase PHP del plugin dispone de
`\local_adaptia_core\client::tutoria($payload, $requestid, $sessionid)`.
Recibe el cuerpo de ocho campos de TutorIA y devuelve `http_status` y `body`, con el
envelope completo, también en errores controlados. Reutilizar el mismo identificador
y el mismo cuerpo al repetir una solicitud. La función requiere una invocación
explícita del código servidor. El formulario de curso la utiliza tras validar sus
permisos. La publicación de contenido generado en cursos queda para otra iteración.

El token Moodle→ADAPTIA sigue siendo `ADAPTIA_SERVICE_TOKEN`. En `.env`, configurar
por separado `ADAPTIA_TUTORIA_URL` (origen de TutorIA, sin ruta),
`ADAPTIA_TUTORIA_TOKEN` (token de servicio de TutorIA) y
`ADAPTIA_TUTORIA_TIMEOUT=70`. La clave de Gemini/Claude permanece únicamente en el
backend TutorIA; no se copia al plugin, a esta pasarela ni al navegador.

Para la activación inicial, el README de `patricio-lucero/TutorIA-Tesis` incluye la
sección **Configurar Gemini para ADAPTIA y Moodle**. El modelo preparado es
`gemini-3.1-flash-lite`, con máximo5 solicitudes diarias. La generación sigue apagada
hasta guardar la API key y confirmar el acceso y la cuota. Este formulario no necesita
otra instalación ni una API key propia de Moodle.

Para dos proyectos Docker locales, crear la red una sola vez:

```sh
docker network create adaptia-tutoria
```

En TutorIA, iniciar su backend con `docker-compose.yml` y
`infraestructura/docker/compose.adaptia.yml`. En este directorio:

```sh
docker compose -f compose.yaml -f compose.tutoria.yaml up -d --no-deps --wait backend
```

La URL interna será `http://tutoria-api:8000`. Ambos backends mantienen además sus
redes originales. Las bases de datos no se conectan a la red compartida. Si se guarda
`COMPOSE_FILE=compose.yaml:compose.tutoria.yaml` en el `.env` local, los comandos
habituales de este directorio y `bootstrap.py` conservan la conexión. La plantilla
base permite seguir usando Moodle sin instalar TutorIA.

La pasarela exige `X-Request-ID`, admite `X-Session-ID` y devuelve la correlación del
llamador en `tracking.request_id`. `tracking.upstream_request_id` identifica la traza
guardada por TutorIA. Los IDs de solicitud y sesión se separan por docente para evitar
colisiones y recuperación de contenido de otra persona. No hay reintentos automáticos,
fallback a fixtures, redirecciones con credenciales ni afirmaciones de costo cero.
El consumo desconocido sigue siendo `null`. La cuota y la reserva durable se gestionan
en TutorIA, sin crear otra base o una reserva duplicada en Moodle.

Con el LLM apagado, el recorrido PHP→pasarela→TutorIA devuelve503 y
`AI_SERVICE_UNAVAILABLE`. Un cuerpo inválido devuelve422. Esto comprueba la conexión,
no una generación con proveedor real. Para una prueba explícita sin proveedor, tras
confirmar que TutorIA tiene `LLM_ENABLED=false`:

```sh
docker compose exec -T -e ADAPTIA_SMOKE_LLM_DISABLED=confirmed moodle php /var/www/html/ambiente-local/tests/tutoria.php
```

Las diez pruebas `backend/test_tutoria.py` usan HTTP controlado, sin IA de pago.
Incluyen errores, timeout, aislamiento, replay, respuesta acotada, rechazo de mock y
ausencia de reintentos, además de transporte UTF-8 sin inflar el tamaño con escapes
ASCII. `bootstrap.py --test` las incorpora a la regresión aislada.

## Integración local con el motor de Andrés

[Guía técnica de Misión Down](docs/mision-down/README.md): montaje del checkout QA,
contrato 1.1.0, roles, rutas internas y comprobación de sesiones completas.

La navegación docente del curso incluye **Misión Down · Integración**. Este piloto llama al motor real de Andrés, registra sesiones y decisiones en PostgreSQL y permite probar los tres juegos mediante ensayos ficticios ingresados por el docente. Las demostraciones MOCK de las secciones siguientes conservan su funcionamiento.

Consulta la [guía técnica del consumidor Down](docs/mision-down/README.md) para el alcance, contrato, configuración, pruebas y límites de la conexión con Flutter. El servicio local del motor utiliza el puerto 5200; no necesita el emulador de Firestore.

## Probar el flujo

1. Entra en `http://localhost:8086` con tu usuario administrador.
2. Abre el curso de prueba **[MOCK] ADAPTIA · Integración E2E**.
3. En **Más → Solicitudes ADAPTIA / ADAPTIA requests**, crea contenido mock.
4. Selecciona un caso, escribe un objetivo con datos ficticios y guarda.
5. Ejecuta los servicios mock. Revisa la decisión, el contenido y el desplegable con los JSON enviados y recibidos.
6. Pulsa **Publicar recurso MOCK en el curso**. Moodle crea una **Página** visible para los estudiantes matriculados.

También puedes entrar desde **Administración del sitio → Extensiones / Plugins → ADAPTIA** y seleccionar el curso en el formulario. Las solicitudes pertenecen al docente que las creó. El estudiante accede a la Página publicada, no al formulario docente.

## Qué se emula

| Caso | Llamadas HTTP | Resultado de prueba |
|---|---|---|
| Avance | Motor → TutorIA | Factorización de ecuaciones cuadráticas, dificultad 7 |
| Refuerzo | Motor → TutorIA | Explicación guiada, dificultad 4 |
| Misión Down | Motor → reglas Down → evaluación Down → TutorIA | Repetición de instrucciones y contenido breve |

Los contratos Motor/TutorIA siguen los ejemplos de `FASE-1-BASE/HITO-A-COMPLETO.md` y `patricio-lucero/REUNION-PATRICIO-FASE1-KICKOFF.md` del material de ADAPTIA. El caso de avance conserva la decisión ilustrativa documentada. Las secciones educativas y el caso de refuerzo son fixtures creados para probar la integración, no respuestas de proveedores reales ni reglas científicas aprobadas.

Los archivos `backend/fixtures/down-*.json` conservan el request, la respuesta completa con `model_state` y el catálogo de reglas obtenidos del test `test_ejemplo_de_la_especificacion` de Misión Down. Ese test acumula cinco interacciones y activa `offer_instruction_repeat`. No requiere ejecutar el repositorio de la tesis para usar esta demo.

El contenido es fijo por caso; cambiar el objetivo permite comprobar su transporte hacia TutorIA, pero no genera una respuesta nueva. No se ejecutan microjuegos, H5P ni el motor científico real. La lectura del estudiante registra el evento nativo de Moodle; todavía no devuelve sus interacciones al motor. Las APIs de pago y las cuotas de investigación quedan para la integración real.

## Servicios y datos

- Moodle: `http://localhost:8086`.
- Backend: `http://127.0.0.1:5000/health`.
- Servicios mock: `http://127.0.0.1:5100/health`.
- PostgreSQL 17: bases `moodle` y `adaptia`; pruebas aisladas en `adaptia_test` con otro rol. No publica un puerto de PostgreSQL en el host.
- Credenciales locales: `.env`, ignorado por Git. Las claves internas permanecen en los servidores y no se incluyen en la traza visible.

La generación y la publicación admiten reintentos sin duplicar el resultado o la Página. Un fallo HTTP o un JSON inválido conserva la solicitud pendiente y su traza de error para reintentar. La publicación exige confirmación mediante el botón y un `sesskey` válido.

## Arranque y verificaciones

Desde `ambiente-local`, con Docker disponible:

```sh
python3 bootstrap.py
python3 bootstrap.py --test
```

`bootstrap.py` conserva las bases y aplica las actualizaciones. El arranque normal no crea cursos ni usuarios de prueba.

La verificación de navegador necesita Node, Playwright y Chrome:

```sh
PLAYWRIGHT_PATH=/ruta/al/paquete/playwright node tests/workflow.cjs
```

Usa un curso identificado como `adaptia-mock-e2e` y cuentas ficticias temporales. Comprueba los tres casos, la traza HTTP, reintentos, publicación, protección CSRF, aislamiento entre docentes, acceso del estudiante y eventos nativos de lectura. Al finalizar elimina las cuentas temporales y su recurso docente; conserva los tres ejemplos publicados por el administrador para revisarlos. Las capturas y el reporte quedan en `/private/tmp/adaptia-workflow-qa`.

Si la prueba se interrumpe, se pueden eliminar sus cuentas temporales mediante:

```sh
docker compose exec -T -u www-data moodle php /var/www/html/ambiente-local/tests/fixture.php cleanup
```
