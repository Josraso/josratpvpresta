<div class="panel">
  <div class="panel-heading"><i class="icon-exchange"></i> Cola de trabajos de addons
    <span class="badge">{$jtp_counts.pending|intval} pendientes</span>
    <span class="badge badge-danger">{$jtp_counts.error|intval} con error</span>
  </div>
  <p>Los addons (p. ej. envío a FacturaScripts) dejan aquí sus trabajos. Se procesan con el cron o con el botón.</p>
  <p><strong>Cron (cada 5 minutos):</strong> <code>{$jtp_cron|escape:'html':'UTF-8'}</code></p>
  <p>
    <a class="btn btn-primary" href="{$jtp_url|escape:'html':'UTF-8'}&jtpQueueRun=1"><i class="icon-play"></i> Procesar ahora</a>
    <a class="btn btn-default" href="{$jtp_url|escape:'html':'UTF-8'}&jtpQueueRetryAll=1"><i class="icon-refresh"></i> Reintentar todos los errores</a>
  </p>
  <table class="table">
    <thead><tr><th>ID</th><th>Addon</th><th>Acción</th><th>Referencia</th><th>Estado</th><th>Intentos</th><th>Error</th><th>Próximo intento</th><th></th></tr></thead>
    <tbody>
    {foreach from=$jtp_jobs item=j}
      <tr>
        <td>{$j.id_job|intval}</td>
        <td>{$j.addon|escape:'html':'UTF-8'}</td>
        <td>{$j.action|escape:'html':'UTF-8'}</td>
        <td>{$j.ref_type|escape:'html':'UTF-8'} {if $j.ref_id}#{$j.ref_id|intval}{/if}</td>
        <td>
          {if $j.status == 'done'}<span class="label label-success">Hecho</span>
          {elseif $j.status == 'error'}<span class="label label-danger">Error</span>
          {elseif $j.status == 'running'}<span class="label label-info">En curso</span>
          {else}<span class="label label-warning">Pendiente</span>{/if}
        </td>
        <td>{$j.attempts|intval}</td>
        <td><small>{$j.last_error|escape:'html':'UTF-8'|truncate:160}</small></td>
        <td>{if $j.status != 'done'}{$j.next_try|escape:'html':'UTF-8'}{/if}</td>
        <td class="text-right">
          {if $j.status != 'done'}<a class="btn btn-default btn-xs" href="{$jtp_url|escape:'html':'UTF-8'}&jtpQueueRetry=1&id_job={$j.id_job|intval}"><i class="icon-refresh"></i> Reintentar</a>{/if}
          <a class="btn btn-default btn-xs" href="{$jtp_url|escape:'html':'UTF-8'}&jtpQueueDelete=1&id_job={$j.id_job|intval}" onclick="return confirm('¿Eliminar el trabajo?');"><i class="icon-trash"></i></a>
        </td>
      </tr>
    {foreachelse}
      <tr><td colspan="9" class="text-center">No hay trabajos en la cola.</td></tr>
    {/foreach}
    </tbody>
  </table>
</div>
