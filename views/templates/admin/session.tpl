<div class="panel">
  <div class="panel-heading"><i class="icon-money"></i> Sesión {$jtp_s.session.id_session|intval} · {$jtp_s.register|escape:'html':'UTF-8'}</div>
  <div class="row">
    <div class="col-lg-6">
      <table class="table">
        <tr><th>Apertura</th><td>{$jtp_s.session.date_open|escape:'html':'UTF-8'} · {$jtp_s.employee_open|escape:'html':'UTF-8'}</td></tr>
        <tr><th>Cierre</th><td>{$jtp_s.session.date_close|escape:'html':'UTF-8'} · {$jtp_s.employee_close|escape:'html':'UTF-8'}</td></tr>
        <tr><th>Ventas</th><td>{$jtp_s.sales_count|intval} · {displayPrice price=$jtp_s.sales_total}</td></tr>
        <tr><th>Devoluciones</th><td>{$jtp_s.returns_count|intval} · {displayPrice price=$jtp_s.returns_total}</td></tr>
        {if $jtp_s.session.note}<tr><th>Nota</th><td>{$jtp_s.session.note|escape:'html':'UTF-8'}</td></tr>{/if}
      </table>
      <h4>Por método de pago</h4>
      <table class="table">
        {foreach from=$jtp_s.by_method item=m}<tr><td>{$m.name|escape:'html':'UTF-8'} ({$m.n|intval})</td><td class="text-right">{displayPrice price=$m.amount}</td></tr>{/foreach}
        {foreach from=$jtp_s.returns_by_method item=m}<tr><td>Devolución · {$m.name|escape:'html':'UTF-8'} ({$m.n|intval})</td><td class="text-right">-{displayPrice price=$m.amount}</td></tr>{/foreach}
      </table>
    </div>
    <div class="col-lg-6">
      <h4>Efectivo</h4>
      <table class="table">
        <tr><td>Fondo inicial</td><td class="text-right">{displayPrice price=$jtp_s.cash.opening}</td></tr>
        <tr><td>Ventas en efectivo</td><td class="text-right">{displayPrice price=$jtp_s.cash.sales}</td></tr>
        <tr><td>Devoluciones en efectivo</td><td class="text-right">{displayPrice price=$jtp_s.cash.refunds}</td></tr>
        <tr><td>Entradas</td><td class="text-right">{displayPrice price=$jtp_s.cash.in}</td></tr>
        <tr><td>Salidas</td><td class="text-right">{displayPrice price=$jtp_s.cash.out}</td></tr>
        <tr><th>Esperado</th><th class="text-right">{displayPrice price=$jtp_s.cash.expected}</th></tr>
        {if $jtp_s.session.status == 'closed'}
          <tr><th>Contado</th><th class="text-right">{displayPrice price=$jtp_s.session.counted_cash}</th></tr>
          <tr><th>Diferencia</th><th class="text-right">{displayPrice price=$jtp_s.session.difference}</th></tr>
        {/if}
      </table>
      {if $jtp_s.movements}
        <h4>Entradas y salidas</h4>
        <table class="table">
          {foreach from=$jtp_s.movements item=mv}<tr><td>{$mv.date_add|escape:'html':'UTF-8'}</td><td>{$mv.employee|escape:'html':'UTF-8'}</td><td>{$mv.reason|escape:'html':'UTF-8'}</td><td class="text-right">{displayPrice price=$mv.amount}</td></tr>{/foreach}
        </table>
      {/if}
    </div>
  </div>
  <div class="panel-footer"><a class="btn btn-default" href="{$jtp_back|escape:'html':'UTF-8'}"><i class="process-icon-back"></i> Volver</a></div>
</div>
