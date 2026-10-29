<!doctype html>
<html lang="es">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>Emergencia</title>
    @vite('resources/js/emergency.ts')
  </head>
  <body>
    <main>
      <h1>Asistencia de emergencia</h1>
      <p>
        Si necesitas ayuda, pulsa el botón. Se avisará a tus contactos de
        emergencia con tu ubicación.
      </p>

      <button id="sos" type="button" aria-describedby="sos-help">
        SOS
      </button>
      <p id="sos-help">
        Si no puedes usar el botón, llama a la línea de emergencia.
      </p>

      <div id="emergency-root"></div>
    </main>

    <script>
      window.__EMERGENCY__ = {
        code: @json($code),
        bffBaseUrl: @json($bffBaseUrl),
        fallbackPhone: '+57 300 123 4567',
      };
    </script>
  </body>
</html>
