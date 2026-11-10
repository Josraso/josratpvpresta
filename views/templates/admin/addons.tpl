<div class="panel">
  <div class="panel-heading"><i class="icon-puzzle-piece"></i> Addons del TPV</div>
  <p class="help-block">Todos los addons que hay en la carpeta de módulos (josratpvpresta_*), instalados o no. Para desinstalar uno, usa Módulos &gt; Gestor de módulos.</p>
  <table class="table">
    <thead><tr><th>Addon</th><th>Módulo</th><th>Versión</th><th>Requiere base</th><th>Estado</th><th></th></tr></thead>
    <tbody>
    {foreach from=$jtp_addons item=a}
      <tr>
        <td><strong>{$a.name|escape:'html':'UTF-8'}</strong><br><small>{$a.description|escape:'html':'UTF-8'}</small>
          {if $a.missing}<br><small class="text-danger">Necesita: {$a.missing_txt|escape:'html':'UTF-8'}</small>{/if}
          {if $a.broken}<br><small class="text-danger">{$a.broken|escape:'html':'UTF-8'}</small>{/if}
        </td>
        <td>{$a.module|escape:'html':'UTF-8'}</td>
        <td>{$a.version|escape:'html':'UTF-8'}{if $a.upgrade}<br><small class="text-muted">instalada {$a.db_version|escape:'html':'UTF-8'}</small>{/if}</td>
        <td>{$a.requires|escape:'html':'UTF-8'}</td>
        <td>
          {if !$a.installed}<span class="label label-default">No instalado</span>
          {elseif !$a.enabled}<span class="label label-warning">Desactivado</span>
          {else}<span class="label label-success">Activo</span>{/if}
          {if !$a.compatible}<br><span class="label label-danger">Actualiza la base</span>{/if}
          {if $a.upgrade}<br><span class="label label-info">Actualización pendiente</span>{/if}
        </td>
        <td class="text-right">
          <form method="post" action="{$a.action_url|escape:'html':'UTF-8'}" style="display:inline">
            <div class="btn-group">
            {if !$a.installed}
              <button type="submit" name="jtpAddonAction" value="install" class="btn btn-primary"{if $a.missing || $a.broken} disabled{/if}><i class="icon-download"></i> Instalar</button>
            {else}
              {if $a.upgrade}<button type="submit" name="jtpAddonAction" value="upgrade" class="btn btn-default"><i class="icon-refresh"></i> Actualizar</button>{/if}
              {if $a.enabled}
                <a class="btn btn-default" href="{$a.configure|escape:'html':'UTF-8'}"><i class="icon-wrench"></i> Configurar</a>
                <button type="submit" name="jtpAddonAction" value="disable" class="btn btn-default" onclick="return confirm('¿Desactivar {$a.name|escape:'javascript':'UTF-8'}?');"><i class="icon-off"></i> Desactivar</button>
              {else}
                <button type="submit" name="jtpAddonAction" value="enable" class="btn btn-success"{if $a.missing || $a.broken} disabled{/if}><i class="icon-check"></i> Activar</button>
              {/if}
            {/if}
            </div>
          </form>
        </td>
      </tr>
    {foreachelse}
      <tr><td colspan="6" class="text-center">No hay addons en la carpeta de módulos. Los addons son módulos independientes (josratpvpresta_*): súbelos desde Módulos &gt; Subir un módulo.</td></tr>
    {/foreach}
    </tbody>
  </table>
</div>
{$jtp_addons_html nofilter}
<div class="panel">
  <div class="panel-heading"><i class="icon-code"></i> Hooks disponibles para addons</div>
  <table class="table">
    {foreach from=$jtp_hooks key=h item=d}<tr><td><code>{$h|escape:'html':'UTF-8'}</code></td><td>{$d|escape:'html':'UTF-8'}</td></tr>{/foreach}
  </table>
  <p>Documentación completa en <code>modules/josratpvpresta/ADDONS.md</code>.</p>
</div>
