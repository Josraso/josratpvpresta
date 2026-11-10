<div class="card mt-2" id="jtp-order-side">
  <div class="card-header">
    <h3 class="card-header-title">Venta TPV</h3>
  </div>
  <div class="card-body">
    <p class="mb-1"><strong>Ticket:</strong> {$jtp_sale.ticket_number|escape:'html':'UTF-8'}</p>
    <p class="mb-1"><strong>Caja:</strong> {$jtp_register|escape:'html':'UTF-8'}</p>
    <p class="mb-1"><strong>Empleado:</strong> {$jtp_employee|escape:'html':'UTF-8'}</p>
    <p class="mb-1"><strong>Documento:</strong> {if $jtp_sale.doc_type == 'full'}Factura completa{else}Factura simplificada{/if}</p>
    <p class="mb-1"><strong>Cobro:</strong>
      {foreach from=$jtp_sale.payments item=p name=pay}{$p.name|escape:'html':'UTF-8'} {$p.amount|string_format:"%.2f"}{if !$smarty.foreach.pay.last} + {/if}{/foreach}
      {if $jtp_sale.change_given > 0} (cambio {$jtp_sale.change_given|string_format:"%.2f"}){/if}
    </p>
    {if $jtp_returns}
      <p class="mb-1 mt-2"><strong>Devoluciones:</strong></p>
      <ul class="mb-0">
      {foreach from=$jtp_returns item=r}
        <li>{$r.ticket_number|escape:'html':'UTF-8'} · {$r.total|string_format:"%.2f"} · {$r.refund_name|escape:'html':'UTF-8'} · {$r.date_add|escape:'html':'UTF-8'}</li>
      {/foreach}
      </ul>
    {/if}
    {$jtp_addons_html nofilter}
  </div>
</div>
