<div class="panel" id="jtp-qz-guide">
  <div class="panel-heading"><i class="icon-print"></i> Configurar QZ Tray paso a paso</div>
  <div class="row">
    <div class="col-lg-7">
      <ol>
        <li><strong>Instala QZ Tray</strong> en el ordenador de la caja (descarga en <a href="https://qz.io/download/" target="_blank" rel="noopener">qz.io/download</a>). Arranca solo y queda como icono junto al reloj.</li>
        <li><strong>Certificado:</strong> pulsa <em>Generar certificado</em> (abajo), o pega el certificado y la clave que genera tu otro programa en los campos de arriba y guarda. Con uno de los dos basta.</li>
        <li><strong>Descarga el certificado</strong> y dáselo a QZ Tray en el ordenador de la caja, para que no pida permiso en cada impresión. El archivo se descarga ya como <code>override.crt</code>. Hay dos maneras:
          <ul>
            <li>Icono de QZ Tray &gt; <em>Advanced</em> &gt; <em>Site Manager</em> &gt; pestaña <em>Allowed</em> &gt; botón <strong>+</strong> &gt; selecciona el archivo descargado.</li>
            <li>O copia el archivo descargado (<code>override.crt</code>) en la carpeta de instalación de QZ Tray (en Windows, <code>C:\Program Files\QZ Tray\</code>) y reinicia QZ Tray.</li>
          </ul>
        </li>
        <li>Arriba elige <strong>Modo de impresión: QZ Tray</strong> y <strong>Formato: ESC/POS</strong>. Si la impresora no es térmica, elige HTML.</li>
        <li>En la pestaña <a href="{$jtp_registers_url|escape:'html':'UTF-8'}">Cajas</a> escribe el <strong>nombre exacto de la impresora</strong> de cada caja. Si lo dejas vacío, se usa la predeterminada de Windows.</li>
        <li>Abre el TPV y pulsa el engranaje <strong>⚙</strong> &gt; <em>Ver impresoras</em> para ver los nombres, luego <em>Ticket de prueba</em> y <em>Abrir cajón</em>.</li>
      </ol>
      <p class="help-block">Si QZ Tray muestra una ventana preguntando si permites imprimir, es que no tiene el certificado: instálalo como se indica arriba. En el TPV, el indicador de la barra superior dice si QZ Tray está conectado; si una impresión falla, sale el motivo con opciones para reintentar o imprimir con el navegador.</p>
      <p class="help-block">El cajón portamonedas se abre a través de la impresora (conectado por RJ11), solo con QZ Tray. Los acentos y la ñ se imprimen con la página de códigos CP850.</p>
    </div>
    <div class="col-lg-5">
      <div class="well">
        <p><strong>Estado</strong></p>
        <p>Modo actual: {if $jtp_qz_mode == 'qz'}<span class="label label-success">QZ Tray</span>{else}<span class="label label-default">Navegador</span>{/if}</p>
        <p>Certificado: {if $jtp_qz_has_cert}<span class="label label-success">Cargado</span>{if $jtp_qz_info} {$jtp_qz_info.cn|escape:'html':'UTF-8'} · caduca {$jtp_qz_info.to|escape:'html':'UTF-8'}{/if}{else}<span class="label label-warning">No</span>{/if}</p>
        <p>Clave privada: {if $jtp_qz_has_key}<span class="label label-success">Guardada</span>{else}<span class="label label-warning">No</span>{/if}</p>
        <p>
          <a class="btn btn-default" href="{$jtp_qz_generate|escape:'html':'UTF-8'}" {if $jtp_qz_has_cert}onclick="return confirm('Se sustituirá el certificado actual. Tendrás que volver a darlo de alta en QZ Tray en cada caja. ¿Continuar?');"{/if}><i class="icon-key"></i> Generar certificado</a>
          {if $jtp_qz_has_cert}<a class="btn btn-primary" download="override.crt" href="{if $jtp_qz_cert_b64}data:application/x-x509-ca-cert;base64,{$jtp_qz_cert_b64|escape:'html':'UTF-8'}{else}{$jtp_qz_download|escape:'html':'UTF-8'}{/if}"><i class="icon-download"></i> Descargar certificado (override.crt)</a>{/if}
        </p>
      </div>
    </div>
  </div>
</div>
