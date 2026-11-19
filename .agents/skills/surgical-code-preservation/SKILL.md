---
name: surgical-code-preservation
description: Guía de preservación quirúrgica de código y prevención de regresiones en GPI. Usar para aplicar modificaciones precisas sin alterar código estable, prevenir etiquetas huérfanas, proteger contenedores y layouts, y comprender con exactitud la solicitud actual del usuario.
---

# Preservación Quirúrgica de Código y Prevención de Regresiones (GPI)

Este skill define el protocolo obligatorio para realizar modificaciones precisas en el proyecto GPI, evitando eliminar código funcional, alterar módulos no solicitados o introducir regresiones visuales y estructurales.

---

## 1. Principio Fundamental: Cirugía de Precisión (No Regresión)

1. **Nunca mutilar código preexistente:**
   - Si un bloque, contenedor o funcionalidad ya funciona correctamente y fue aprobado, **no debe reescribirse ni eliminarse** salvo que el usuario lo pida explícitamente.
   - Los cambios deben ser aditivos o quirúrgicos (modificar únicamente la línea o atributo objetivo).

2. **Cuidado riguroso del balance de etiquetas HTML / Twig:**
   - Al editar plantillas `.html.twig`, verificar minuciosamente que cada etiqueta abierta (`<div>`, `<section>`, `<form>`) coincida con su cierre exacto.
   - **Regla de oro:** Jamás dejar etiquetas de cierre huérfanas (`</div>`, `</section>`) ni cerrar contenedores antes de tiempo (por ejemplo, cerrar accidentalmente `.user-module-wrapper` antes de la tarjeta principal o tabla de datos).

3. **Respeto a los contenedores estructurales:**
   - En el diseño de GPI, vistas como Tickets, Usuarios e Inventario de Activos utilizan **`.user-module-wrapper`** (`max-width: 1200px; margin: 0 auto;`).
   - Todo el contenido de la página (breadcrumbs, header, KPIs y tarjeta de tabla) **debe residir dentro de ese contenedor único**.

---

## 2. Protocolo de Interpretación de Solicitudes del Usuario

1. **Comprender antes de tocar código:**
   - Identificar con precisión: ¿A qué pantalla, componente o elemento específico se refiere el usuario?
   - Si el usuario comparte capturas de pantalla, analizar:
     * Si es una vista móvil (ancho reducido, colapso de columnas, colisión de textos/iconos).
     * Si es una vista desktop (pantalla ancha, márgenes, proporciones).
     * Si la imagen representa un **objetivo a replicar** (ejemplo a seguir) o un **defecto visual a corregir**.

2. **Aislar el alcance del cambio:**
   - Si el usuario pide ajustar el módulo A (ej. Inventario de Activos), **no modificar archivos ni estilos globales del módulo B** (ej. Gestión de Tickets) a menos que se trate de un token CSS compartido y deliberadamente acordado.

---

## 3. Protocolo de Verificación Obligatorio Antes de Entregar

Antes de dar por concluida cualquier tarea o modificación:

1. **Revisión de Diferencias (`git diff`):**
   - Ejecutar `git diff` para auditar cada línea modificada.
   - Preguntarse:
     * ¿Se eliminó inadvertidamente alguna clase, tag o texto original?
     * ¿Se cerró un bloque prematuramente?
     * ¿El diff contiene exclusivamente lo que el usuario pidió?

2. **Validación de Sintaxis y Contenedores:**
   - Ejecutar `php bin/console lint:twig templates/` para asegurar que ningún archivo Twig tenga sintaxis rota.
   - Ejecutar `php bin/console lint:container` para validar inyección de dependencias.

3. **Pruebas Automatizadas:**
   - Ejecutar `php bin/phpunit` para certificar que la suite completa de pruebas sigue pasando al 100%.

4. **Compilación de Assets:**
   - Ejecutar `npm run build` si se modificaron archivos CSS o JavaScript.

---

## 4. Control de Versiones: Los Commits los Realiza Exclusivamente el Usuario

1. **Prohibición de `git commit` y `git push` automático:**
   - **NUNCA** ejecutar `git commit` ni `git push` de manera autónoma.
   - La acción de crear los commits y enviarlos al repositorio remoto es responsabilidad **exclusiva del usuario**.

2. **Rol del Asistente en Git:**
   - Aplicar los cambios en el código con precisión quirúrgica.
   - Ejecutar verificaciones (linters, tests, builds).
   - Sugerir al usuario el nombre del commit siguiendo la convención estricta:
     `[ADD ] <mensaje>` (sin la palabra "implementación").
   - Dejar el espacio de trabajo listo para que el usuario sea quien realice el commit.
