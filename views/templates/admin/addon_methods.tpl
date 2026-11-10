<div class="panel">
  <div class="panel-heading"><i class="icon-puzzle-piece"></i> Métodos de pago aportados por addons</div>
  <table class="table">
    <thead><tr><th>Código</th><th>Nombre</th><th>Efectivo</th><th>Addon</th></tr></thead>
    <tbody>
    {foreach from=$jtp_addon_methods item=m}
      <tr><td>{$m.code|escape:'html':'UTF-8'}</td><td>{$m.name|escape:'html':'UTF-8'}</td><td>{if $m.is_cash}Sí{else}No{/if}</td><td>{$m.source|escape:'html':'UTF-8'}</td></tr>
    {/foreach}
    </tbody>
  </table>
</div>
