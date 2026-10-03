# Segundo cerebro

**Lee `CLAUDE.md` entero antes de tocar nada:** es la documentación viva de este
proyecto (decisiones, reglas, cómo se prueba y se despliega) y vale igual para
Codex que para Claude. No se duplica aquí a propósito: en finanzas-personales
había dos copias y acabaron diciendo cosas distintas.

Lo imprescindible, por si solo lees esto:

1. `php pruebas/todas.php` tiene que dar 5/5 antes de dar algo por bueno.
2. Las secciones y sus campos se declaran en `includes/secciones.php`; no hay SQL
   por sección.
3. Despliegue por GitHub: con pruebas verdes, commit y push a `main`; Actions sube
   por FTP. Nada de FileZilla. Revisa `git status` antes de empujar.
4. Nunca inventes fechas, importes ni números de documentos.
5. Sentencias preparadas, `csrf_ok()` en todo POST, `e()` en todo lo que se imprime.
