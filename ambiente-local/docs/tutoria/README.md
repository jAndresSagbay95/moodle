# Manual de TutorIA en Moodle

[Abrir el manual completo en PDF](manual-moodle-tutoria.pdf).

Documento autónomo de **20 páginas**, con **11 capturas originales de Playwright**
incrustadas e indicaciones numeradas. Contiene seis diagramas: casos de uso,
componentes, despliegue, secuencia, estados y relaciones de datos. Se comparte como
un solo archivo, sin imágenes externas.

## Qué aporta la implementación

El docente solicita texto desde **Curso → Más → TutorIA · Contenido**. Moodle
valida permisos y datos, guarda la entrada y llama a ADAPTIA y TutorIA. El resultado
pertenece al docente y al curso. Repetir el mismo envío conserva una sola solicitud;
los fallos quedan en el historial y no disparan reintentos automáticos.

El formulario utiliza las APIs de Moodle: Forms, capacidades, Lock, Persistent,
XMLDB, Output, Language y Privacy. Este piloto usa un perfil ficticio: no conecta
historiales institucionales, no decide la dificultad automáticamente y no publica
contenido en el curso.

## Índice de revisión

| Contenido | Páginas |
| --- | --- |
| Casos de uso, actores y responsabilidades | [2](manual-moodle-tutoria.pdf#page=2) |
| Componentes nativos y patrones de Moodle | [3](manual-moodle-tutoria.pdf#page=3) |
| Servicios, redes y bases de datos | [4](manual-moodle-tutoria.pdf#page=4) |
| Secuencia real y correlación | [5](manual-moodle-tutoria.pdf#page=5) |
| Curso, formulario y ejemplo numerado | [6-8](manual-moodle-tutoria.pdf#page=6) |
| Validación, fallo real, historial y estados | [9-11](manual-moodle-tutoria.pdf#page=9) |
| Permisos, privacidad y salida controlada | [12-13](manual-moodle-tutoria.pdf#page=12) |
| Uso en móvil | [14](manual-moodle-tutoria.pdf#page=14) |
| Swagger, JSON y modelo de datos | [15-16](manual-moodle-tutoria.pdf#page=15) |
| Clave, modelo, configuración, fork y despliegue | [17-18](manual-moodle-tutoria.pdf#page=17) |
| Pruebas, reproducción y diagnóstico | [19-20](manual-moodle-tutoria.pdf#page=19) |

## Evidencia y pendientes

Corte: **19/11/2026, 22:13, America/Guayaquil**. Plugin `2026111901 / 0.6.0`, en el
checkout Moodle 6.0dev. Regresión ADAPTIA: **39 casos aprobados** en PostgreSQL
aislado (solicitudes 16, Down 13, cliente TutorIA 10). E2E base: **11 comprobaciones**.
Recorrido documental: **12 comprobaciones y 11 capturas**; el formulario se muestra
en dos recortes. Son grupos parcialmente solapados, no un porcentaje de cobertura.

El **503 procede de TutorIA real con la IA apagada**. El smoke PHP también verificó
422 para dificultad inválida y correlación. La **salida exitosa fotografiada es un
fixture controlado**, rotulado QA: comprueba presentación, escape y consumo
desconocido; no representa una generación de Gemini. Las cuentas ficticias se
eliminaron al terminar.

Modelo preparado: `gemini-3.1-flash-lite`, cinco intentos diarios compartidos en
TutorIA. La clave sigue vacía y habilitación/confirmaciones siguen en `false`.
Falta verificar cuenta y cuota, cargar la clave privada y comprobar una primera
generación externa. La clave del proveedor se guarda solo en TutorIA; Moodle y
Swagger usan tokens internos de servicio.

Quedan pendientes publicación de contenido real, RAG en el POST externo, evaluación
pedagógica y carga. No se ejecutaron PHPUnit, PHPCS ni una auditoría WCAG completa.
El borrado local de privacidad no elimina las trazas remotas de TutorIA.

## Continuar

La [guía del ambiente](../../README.md) explica arranque y comprobaciones. Los
scripts versionables son `tests/tutoria_ui.cjs`, `tests/tutoria_ui_fixture.php`,
`tests/tutoria.php` y `backend/test_tutoria.py`, bajo `ambiente-local`. Los comandos
y condiciones de reproducción están en el PDF.

La copia de TutorIA en `docs/api/manual-moodle-tutoria.pdf` contiene el mismo
documento. Los PNG originales, el manifiesto SHA256 y el generador quedan en el área
local ignorada `documentacion-local/adaptia-api/manual/` de TutorIA para iterar; no
se añaden imágenes sueltas al conjunto versionable.

SHA256: `410aa0af12e6b595cae277834965b3a2185339cf4a25e9422f8040d627ea3a02`.
