# Cómo crear addons para josratpvpresta

Un addon es un **módulo de PrestaShop independiente** (recomendado: `josratpvpresta_<nombre>`) que se engancha a los hooks que crea la base. La base funciona sola; los addons solo añaden cosas.

Plantilla completa y funcional: **`josratpvpresta_ejemplo`** (método de pago con cobro en JS, botón en la pantalla, bloques de ticket, cola, API propia y panel en la configuración).

## Reglas

1. **Sin pestañas en el menú lateral.** La configuración del addon va en su `getContent()` (Módulos > Configurar) con HelperForm/HelperList nativos.
2. **No tocar tablas de la base.** Cada addon crea las suyas (`PREFIX_jtp_<addon>_*`). Para leer datos de la base, usa la API pública (abajo) o las tablas en modo lectura.
3. **Declara la versión mínima de la base** en `actionJtpAddonRegister` (`requires`). Si la base es más antigua, el addon aparece como incompatible y la base no carga sus métodos de pago, assets ni bloques de ticket.
4. `$this->dependencies = ['josratpvpresta'];` en el constructor.
5. Nada de overrides.

## Hooks PHP

Todos se registran con `$this->registerHook([...])`. Los parámetros marcados con `&` llegan por referencia: modifícalos directamente en `$params[...]`.

| Hook | Cuándo | Parámetros | Devuelve |
|---|---|---|---|
| `actionJtpAddonRegister` | Listado de addons | `base_version` | `['name','version','requires','description']` |
| `actionJtpPosAssets` | Carga de la pantalla de venta | — | `['js' => [urls], 'css' => [urls]]` |
| `actionJtpPaymentMethods` | Métodos de pago | — | `[['code','name','is_cash','allow_change','js'], ...]` |
| `actionJtpCartLineBefore` | Antes de añadir/cambiar cantidad | `cart`, `action` (add/qty), `&id_product`, `&id_product_attribute`, `&quantity`, `&errors`, `id_employee` | — (añade mensajes a `errors` para bloquear) |
| `actionJtpCartTotals` | Tras calcular el carrito | `cart`, `&lines`, `&totals` | — |
| `actionJtpCheckoutValidate` | Antes de crear el pedido | `cart`, `register`, `id_employee`, `&payments`, `total`, `&errors` | — |
| `actionJtpTicketNumber` | Al numerar venta o devolución | `&number`, `counter`, `register`, `order` \| `return` | — |
| `actionJtpDocumentType` | Tipo de documento de la venta | `&type` (`simplified`/`full`), `order`, `customer`, `address` | — |
| `actionJtpSaleCompleted` | Venta registrada | `sale` (con `payments`), `order`, `payments` | — |
| `actionJtpReturnCompleted` | Devolución registrada | `return` (con `lines`), `sale`, `order` | — |
| `actionJtpTicketBlocks` | Al generar cualquier ticket | `type` (sale/return/z/movement), `&ticket`, y `sale`/`order`/`return`/`summary` | lista de bloques |
| `actionJtpSessionOpened` / `actionJtpSessionClosed` | Apertura / cierre de caja | `session`, `register` / `session`, `summary` | — |
| `actionJtpCashMovement` | Entrada/salida manual | `id_movement`, `id_session`, `type`, `amount`, `reason` | — |
| `actionJtpCustomerCreated` | Cliente creado desde el TPV | `customer` | — |
| `actionJtpQueueProcess` | Procesar un trabajo de la cola (solo se llama al addon dueño) | `job` (payload decodificado), `&result` | rellena `result['ok']`, `result['error']`, `result['data']` |
| `actionJtpApi` | Llamada desde JS a la API del addon (solo al addon destino) | `call`, `params`, `id_employee`, `register`, `&response`, `&error` | — |
| `displayJtpAdminConfig` | Pestaña Addons de la configuración del TPV | — | HTML |
| `displayJtpAdminSale` | Bloque "Venta TPV" de la ficha del pedido | `sale` | HTML |

Además, la base usa `actionValidateOrder`, así que un addon puede escuchar los pedidos web con los hooks normales de PrestaShop (p. ej. `actionOrderStatusPostUpdate` para mandar pedidos web a FacturaScripts al llegar a un estado).

### Bloques de ticket

```php
['type' => 'text'|'title'|'pair'|'separator'|'qr'|'barcode'|'image',
 'value' => '...', 'label' => '...', 'align' => 'left'|'center'|'right',
 'bold' => true, 'position' => 'top'|'header'|'bottom']
```

`top` va bajo los datos de la tienda, `header` bajo el título y número del ticket, `bottom` (por defecto) al final. Se imprimen igual en HTML (navegador y email) y en ESC/POS (QZ Tray). `qr` genera un QR a partir de un texto o URL (en el ticket por email, si es una URL, sale como enlace con su `label`). `image` imprime una imagen (URL, data URI o PNG en base64), por ejemplo el QR de Verifactu que devuelve FacturaScripts. Con `&ticket` también se puede cambiar el título (`$params['ticket']['title'] = 'FACTURA SIMPLIFICADA'`) o el número mostrado.

### Cola de trabajos (envíos a sistemas externos)

```php
JtpQueue::push('miaddon', 'enviar_albaran', ['id_sale' => 5], 'sale', 5);
```

- Se procesa con el cron (URL en Configuración > Cola) o con el botón "Procesar ahora".
- Si falla, se reintenta con espera creciente (1, 2, 4, 8… min, máx. 6 h), hasta `JTP_QUEUE_MAX_ATTEMPTS`.
- `JtpQueue::findByRef('miaddon', 'sale', 5)` devuelve el estado (para mostrar "enviado / error").
- La pantalla de la cola permite reintentar o borrar trabajos.

### API pública (estable entre versiones menores)

```php
$api = Module::getInstanceByName('josratpvpresta')->api();
$api->version();
$api->sale($idSale);            // venta + pagos
$api->saleByOrder($idOrder);
$api->saleReturn($idReturn);    // devolución + líneas
$api->ticket($idSale);          // ticket estructurado
$api->register($idRegister);
$api->queue($addon, $action, $payload, $refType, $refId);
$api->queueStatus($addon, $refType, $refId);
$api->nextNumber($serie);       // numeración atómica SERIE-AÑO-000001
$api->paymentMethods();
$api->isDefaultCustomer($idCustomer);
```

## JavaScript (pantalla de venta)

Tu JS se carga después del núcleo (`actionJtpPosAssets`), así que `window.JTP` ya existe.

```js
JTP.api('cart.get')                                  // API de la base
JTP.addonApi('miaddon', 'miLlamada', {a: 1})         // API del addon (hook actionJtpApi)
JTP.registerAction({id, label, onClick, visible})    // botón en la barra del ticket
JTP.registerMenuItem({id, label, onClick})           // botón en la barra superior
JTP.registerPaymentHandler('codigo', function (amount, ctx) {
  return Promise.resolve({ok: true, reference: 'AUT123'});  // o {ok:false, error:'...'}
});
JTP.on('evento', fn)
JTP.ui.modal({title, body, footer, size}) / JTP.ui.toast(msg, 'ok'|'error') / JTP.ui.confirm() / JTP.ui.prompt()
JTP.print.print(ticket, {gift, drawer}) / JTP.print.openDrawer()
JTP.h('div.clase', {attrs}, [hijos])                 // crear elementos
JTP.state                                            // empleado, caja, sesión, carrito, permisos…
```

Desde 1.1.7:

```js
JTP.registerSaleButton({id, label, visible(ctx), onClick(ctx, btn)})  // botón en "Venta completada" y en el detalle de la venta
                                                     // ctx = {id_sale, ticket?, sale?, customer?}; onClick puede devolver una promesa
JTP.print.printPdf(base64, {name})                   // PDF a la impresora A4 de la caja (QZ) o al navegador; devuelve 'a4'|'browser'
JTP.print.openPdf(base64, name, ventanaAbierta?)     // abrir un PDF en una pestaña
JTP.print.hasA4()                                    // ¿la caja tiene impresora A4 con QZ?
JTP.ui.openCustomerForm(cliente|null) / JTP.ui.editCustomer(id)   // alta o edición de cliente (promesa)
```

- `payment:modal` recibe `{modal, total, payments, extra, options, cart}`: el addon puede añadir controles en `options`
  y rellenar `extra`, que viaja con el cobro y llega a `actionJtpCheckoutValidate` y `actionJtpSaleCompleted` como `$params['extra']`.
- La impresión automática al cobrar lleva `opts.auto = true` en `ticket:beforePrint`.
- Clientes: `customer.get`, `customer.update` (API) y `billing_missing` en el cliente del carrito (qué falta para factura completa).

Eventos: `ready`, `cart:updated`, `customer:changed`, `product:selected`, `sale:completed`, `return:completed`, `session:opened`, `session:closed`, `payment:modal`, `scan` (lectura de código de barras), `ticket:beforePrint` (`ctx.cancel = true` para impedir), `ticket:html` (modificar `ctx.html`), `ticket:escpos` (modificar `ctx.data`).

## Datos útiles

- Cada venta es un **pedido nativo** de PrestaShop (`module = josratpvpresta`) más una fila en `jtp_sale` (ticket, caja, sesión, empleado, tipo de documento, cambio) y sus pagos en `jtp_sale_payment`.
- Devoluciones: `jtp_return` y `jtp_return_line` (sin abonos de PrestaShop). El pedido pasa a "Devuelto en TPV" solo si se devuelve entero.
- NIF del cliente: `address.vat_number` o `address.dni`. En países que exigen DNI, `-` significa "sin NIF" (usa `JtpCustomerService::nif($valor)`).
- Numeración: `JtpCounter::next($serie)` es atómico y se reinicia cada año.

## Guion para el addon de FacturaScripts

- `actionJtpSaleCompleted`, y `actionOrderStatusPostUpdate` para pedidos web con el estado configurado → `JtpQueue::push()`.
- `actionJtpQueueProcess` → crear o buscar el cliente en FS por NIF o email (dirección de facturación de PrestaShop) y crear un albarán o una factura simplificada con `numero2` = número de ticket. Así se evitan duplicados.
- `actionJtpReturnCompleted` → albarán negativo o rectificativa.
- `actionJtpTicketBlocks` → QR de Verifactu y título del documento, cuando se facture en FS.
- `displayJtpAdminSale` → estado del envío en la ficha del pedido.
- La pantalla de sincronización va en el `getContent()` del addon (HelperList).
