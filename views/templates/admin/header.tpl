<div class="panel" id="jtp-header">
  <div class="panel-heading"><i class="icon-shopping-cart"></i> TPV tienda física <span class="badge">v{$jtp_version|escape:'html':'UTF-8'}</span></div>
  {if $jtp_multishop_all}
    <div class="alert alert-warning">Selecciona una tienda concreta en el selector de tiendas para configurar sus cajas y abrir su TPV.</div>
  {/if}
  <div class="row">
    <div class="col-lg-8">
      <p>Abre la pantalla de venta en este equipo. El equipo quedará <strong>autorizado</strong> y después los empleados podrán entrar con su PIN en <a href="{$jtp_pos_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener">{$jtp_pos_url|escape:'html':'UTF-8'}</a>.</p>
      {foreach from=$jtp_registers item=reg}
        <a class="btn btn-primary btn-lg" style="margin:0 8px 8px 0" href="{$reg.url|escape:'html':'UTF-8'}" target="_blank" rel="noopener"><i class="icon-play"></i> Abrir TPV · {$reg.name|escape:'html':'UTF-8'}</a>
      {foreachelse}
        <div class="alert alert-info">No hay cajas activas en esta tienda. Crea una en la pestaña Cajas.</div>
      {/foreach}
    </div>
  </div>
</div>
<ul class="nav nav-tabs" id="jtp-tabs" style="margin-bottom:15px">
  {foreach from=$jtp_tabs item=t}
    <li class="nav-item{if $t.active} active{/if}"><a class="nav-link{if $t.active} active{/if}" href="{$t.url|escape:'html':'UTF-8'}"><i class="{$t.icon|escape:'html':'UTF-8'}"></i> {$t.label|escape:'html':'UTF-8'}</a></li>
  {/foreach}
</ul>
