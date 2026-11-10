# josratpvpresta · TPV tienda física para PrestaShop

Compatible con PrestaShop 1.7.8, 8 y 9. PHP 7.1 o superior.

## Instalación

1. Módulos > Subir un módulo > `josratpvpresta.zip`.
2. Al instalar se crean:
   - las tablas `jtp_*`;
   - los estados de pedido **Venta TPV** y **Devuelto en TPV**, que no generan factura ni envían emails;
   - el transportista **Venta en tienda (TPV)**, que nunca se ofrece en la tienda online;
   - el cliente **Cliente TPV**, para las ventas de mostrador;
   - la caja **Caja 1**, con series T1 y T1D;
   - los métodos de pago Efectivo, Tarjeta y Bizum.
3. En PrestaShop 1.7 y 8 se amplía el campo de referencia del pedido para que quepa el número de ticket.

## Uso

- **Abrir el TPV:** Módulos > TPV tienda física > Configurar > **Abrir TPV**. El equipo queda autorizado y el TPV queda en `https://tutienda.com/tpv`.
- **Empleados:** en la pestaña Empleados se da a cada uno un PIN y sus permisos. Así pueden entrar con PIN en los equipos autorizados.
- **Caja:** apertura con fondo, entradas y salidas, arqueo por billetes y monedas, e informe Z. Por defecto no se puede cobrar con la caja cerrada.
- **Venta:**
  - búsqueda y escaneo por EAN, UPC, ISBN, MPN o referencia, tanto de producto como de combinación;
  - favoritos y navegación por categorías;
  - variantes de cualquier tipo: botones con un grupo, cuadrícula con dos, selección encadenada con tres o más;
  - descuentos por línea (%, € o precio final) y globales;
  - vales de PrestaShop;
  - cliente con alta rápida y NIF;
  - aparcar tickets;
  - pago mixto con cambio.
- **Ticket:** en navegador o con QZ Tray (ESC/POS o HTML). Incluye ticket regalo, envío por email y código de barras del número de ticket, que sirve para devoluciones.
- **Devoluciones:** escanea el ticket o búscalo en Ventas. Se reembolsa en efectivo, en otro método o como vale de compra (para cambios), y se repone el stock.
- **Atajos de teclado:**

  | Tecla | Acción |
  |---|---|
  | F2 | Buscar |
  | F4 / F12 | Cobrar |
  | F6 | Cliente |
  | F8 | Aparcar |
  | Esc | Cerrar ventana |

## QZ Tray (impresión directa y cajón)

Configuración > **Impresión** tiene una guía paso a paso y el estado actual. En resumen:

1. Instala QZ Tray en el ordenador de la caja (qz.io/download).
2. Consigue el certificado de una de estas dos formas:
   - pulsa **Generar certificado**, y el módulo crea el certificado y la clave privada;
   - o pega en los dos campos el certificado y la clave que genere tu otro programa, y guarda.
3. Pulsa **Descargar certificado (override.crt)**; el navegador lo guarda ya con ese nombre y dáselo a QZ Tray en ese ordenador, de una de estas dos formas:
   - icono de QZ Tray > Advanced > Site Manager > Allowed > **+** > elige el archivo;
   - o cópialo tal cual en la carpeta de QZ Tray (`C:\Program Files\QZ Tray\`) y reinicia QZ Tray.
4. Elige el modo **QZ Tray** y el formato **ESC/POS**. Los códigos QR se imprimen por defecto **como imagen**, que funciona en cualquier impresora ESC/POS; el comando QR nativo es opcional porque no todas lo tienen.
5. En **Cajas**, escribe el nombre exacto de la impresora.
6. En el TPV, ⚙ > *Ver impresoras* / *Ticket de prueba* / *Abrir cajón*.
7. La barra superior del TPV muestra si QZ Tray está conectado (punto verde). El TPV se conecta al abrirse y se reconecta solo.
8. Si una impresión falla, sale una ventana con el motivo y las opciones *Reintentar*, *Imprimir con el navegador* o *No imprimir*. En modo QZ ya no se abre el diálogo de impresión de Windows por sorpresa.

## Numeración

- **Formato:** `SERIE-AÑO-NNNNNN`, por ejemplo `T1-2026-000123`. Se reinicia cada año y es atómica, así que nunca se repite aunque haya varias cajas.
- **Pedidos del TPV:** el número de ticket se usa como referencia del pedido.
- **Pedidos web:** opcionalmente llevan referencia numérica con su propia serie (`W-2026-000001`).

## Facturación

Los pedidos del TPV nunca generan factura de PrestaShop. En Configuración > General puedes desactivar también las facturas de toda la tienda, para facturar desde FacturaScripts con el addon correspondiente.

## Addons

Módulos independientes que amplían el TPV. Cada uno va en su propio módulo y su propio repositorio (se irán publicando).

| Addon | Versión | Qué hace |
|---|---|---|
| `josratpvpresta_fs` · TPV · FacturaScripts | 1.6.1 | Envía ventas del TPV, pedidos web y devoluciones a FacturaScripts como albaranes o facturas. |
| `josratpvpresta_fsprod` · TPV · Productos con FacturaScripts | 1.0.5 | Sincroniza productos, combinaciones, precio y stock entre PrestaShop y FacturaScripts. |
| `josratpvpresta_ecotasa` · TPV · Ecotasa | 1.0.2 | Ecotasas (neumáticos, RAEE, baterías...) por tipo, separadas en la web, el TPV, la factura y FacturaScripts, sin overrides. |
| `josratpvpresta_recargo` · TPV · Recargo de equivalencia | 1.0.3 | Recargo de equivalencia para clientes minoristas, en la tienda online y en el TPV, sin overrides. |
| `josratpvpresta_etiquetas` · TPV · Etiquetas | 1.0.1 | Etiquetas de producto y de estantería con código de barras (rollo, A4 adhesivo o impresora de tickets) y EAN internos. |
| `josratpvpresta_informes` · TPV · Informes | 1.0.1 | Informes de ventas web y TPV, productos y margen, stock, ranking de días, facturas, IVA (web frente a FacturaScripts) y de los addons. |
| `josratpvpresta_puntos` · TPV · Puntos de fidelidad | 1.0.1 | Puntos por compra en la tienda online y en el TPV, canjeables como descuento, con caducidad. |
| `josratpvpresta_taller` · TPV · Taller | 1.0.2 | Órdenes de reparación en el TPV: vehículos, recambios, mano de obra, estados con aviso al cliente, señal y cobro. |
| `josratpvpresta_ejemplo` · TPV · Addon de ejemplo | 1.0.1 | Plantilla de addon: método de pago, botón, bloque de ticket y cola. Punto de partida para addons nuevos. |

Cómo crear un addon: ver `ADDONS.md` y el módulo de ejemplo `josratpvpresta_ejemplo`.

## Desinstalar

- Las ventas, cajas y tickets se conservan en la base de datos.
- El transportista queda eliminado.
- Si se habían desactivado las facturas de PrestaShop, se restaura el ajuste anterior.

## 1.1.15 · PrestaShop 9.2 con nginx

Los enlaces de las pestañas y los formularios de configuración son ahora completos (`/carpeta-admin/index.php?controller=AdminModules…`) en vez de relativos. En PrestaShop 9 la página de configuración tiene una dirección nueva, y con los enlaces relativos los servidores con nginx (Plesk incluido) daban un 404 al cambiar de pestaña o al guardar. En 1.7.8 y 8 la dirección es la misma de siempre. Además, se quita un aviso de PHP 8.2+ al crear el transportista del TPV en la instalación.

## 1.1.16 · Tipo fiscal de las ventas (JtpFiscal)

Nueva clase `JtpFiscal`, que usan los addons de FacturaScripts e informes para clasificar cada venta igual. Se basa en lo que cobró PrestaShop y en el país de la dirección con la que calculó los impuestos (Internacional > Impuestos > "Basado en"):

- **Nacional:** venta en el país de la tienda.
- **Intracomunitaria:** empresa de otro país de la UE con NIF-IVA en la dirección y todo al 0 % (por ejemplo, con el módulo vatchecker). Causa de exención art. 25.
- **Exportación:** fuera de la UE, o Canarias, Ceuta y Melilla, con todo al 0 %. Causa art. 21.
- **OSS:** particular de otro país de la UE con IVA cobrado (el IVA de su país). No va en el 303 sino en el 369.
- **Mixta:** empresa de la UE o exportación con unas líneas al 0 % y otras con IVA. No debería pasar; se avisa.

**Ventas en tienda con IVA del país de la tienda.** El carrito del TPV lleva como dirección de facturación la del cliente (la del ticket y la de FacturaScripts) y como entrega la dirección de la tienda ("TPV", con el NIF del cliente). Solo en el TPV, PrestaShop calcula los impuestos con la de entrega, así que una venta en tienda lleva siempre el IVA español, aunque el cliente tenga dirección en otro país o sea una empresa de la UE con NIF-IVA. El recargo de equivalencia se sigue aplicando. La configuración de la web no cambia: la web sigue usando la dirección que tengas en Internacional > Impuestos (y el IVA de cada país, si lo tienes configurado).

## 1.1.17 · Mantenimiento: limpiar los datos de prueba

Nueva pestaña **Mantenimiento** en la configuración del TPV. Solo la ven los superadministradores. Sirve para borrar los datos que han generado el TPV y sus addons mientras se probaba y empezar de cero.

**Qué se puede borrar** (cada addon añade lo suyo):
- **TPV:**
  - ventas y cobros, devoluciones, cierres de caja y movimientos de efectivo;
  - tickets en curso y aparcados, y la cola de trabajos;
  - contadores de numeración.
- **FacturaScripts:** registro de envíos y rectificativas, y enlaces de direcciones con clientes y contactos de FS.
- **Productos FS:** historial de sincronización.
- **Puntos:** movimientos y canjes. Los vales de canje sin usar se desactivan.
- **Taller:** órdenes con sus líneas, pagos e historial, y vehículos.

**Qué no se toca:**
- Nada de PrestaShop: pedidos, clientes, productos, stock, carritos.
- La configuración de los módulos: cajas, métodos de pago, empleados, dispositivos, formatos de etiquetas, tipos de ecotasa, enlaces de productos con FS.
- Nada de FacturaScripts.

**Contadores.** Solo se reinicia un contador si no quedan pedidos en PrestaShop ni documentos en FacturaScripts con esa numeración. Si no, el primer número nuevo coincidiría con uno de prueba. Para empezar desde 1 en ese caso, cambia el nombre de la serie (pestaña Cajas, o "Serie de pedidos web" en General).

**Seguridad.**
- Hay que escribir BORRAR para confirmar.
- Antes de borrar se guarda una copia SQL en `var/josratpvpresta-copias/` con un nombre imposible de adivinar. También se puede descargar con el botón "Descargar copia". Para restaurarla, ejecútala en la base de datos.
- Queda apuntado en el registro de PrestaShop quién lo hizo y cuándo.
- Se borra con DELETE: los números internos no vuelven a empezar, así nada nuevo se confunde con lo antiguo.

Para addons nuevos: el hook `actionJtpPurgeList` devuelve los elementos (`key`, `group`, `label`, `tables`) y `actionJtpPurgeBefore` se llama antes de borrar.

## 1.1.18 · Estados de pedido del TPV

- **Apartado "Estados del TPV"** en la pestaña General:
  - muestra los dos estados propios ("Venta TPV" y "Devuelto en TPV") con su id y si están bien, borrados, sin crear o con las opciones cambiadas;
  - **Regenerar estados** los crea si faltan, los recupera si están borrados y les devuelve las opciones correctas: pagado y enviado, sin correo, sin factura y sin albarán. "Venta TPV" cuenta en las estadísticas y "Devuelto en TPV" no.
  - No toca los pedidos que ya tienen esos estados.
- **Selectores "Estado de las ventas" y "Estado de las devoluciones totales".** Por defecto, los del TPV. Si eliges un estado de PrestaShop, avisa de lo que implica: correos, factura, albarán, stock…
- **Reparación automática.** Si al cobrar falta el estado de ventas (o el elegido ya no existe), el TPV regenera el suyo y lo apunta en el registro de PrestaShop. Así una venta nunca falla por el estado.

## 1.1.19 · Solo los estados propios del TPV

- **Solo cuentan como estados del TPV los creados por el módulo.** Si el id guardado en la configuración apunta a otro estado (por ejemplo, tras una migración), se ignora y no se toca.
- **Búsqueda.** El TPV busca sus propios estados ("Venta TPV" y "Devuelto en TPV" del módulo). Si no los encuentra, los crea de nuevo.
  - Pasa al abrir la pestaña General y también al cobrar.
- **Regenerar estados** solo modifica estados del TPV.

## 1.1.20 · Referencia de los pedidos

Los dos interruptores de referencia pasan a ser selectores con tres opciones, en la pestaña General.

- **Pedidos del TPV:**
  - número de ticket (T1-2026-000123), la opción por defecto;
  - ID del pedido (95298);
  - no tocar, y se queda la referencia que pongan PrestaShop u otro módulo.
- **Pedidos web:**
  - no tocar, la opción por defecto;
  - serie (W-2026-000123);
  - ID del pedido.

Con "ID del pedido" la pone el propio TPV al crear el pedido, aunque otro módulo de referencias no actúe en las ventas del TPV. Si un carrito se divide en varios pedidos, todos llevan el ID del primero, igual que PrestaShop les da la misma referencia.

Al actualizar se mantiene lo que tenías con los interruptores.

## 1.2.0 · Dirección del cliente por defecto y actualizaciones

- **Dirección del cliente por defecto:** en Configuración > General, debajo del cliente por defecto, se elige qué dirección suya usan las ventas sin identificar. Hacía falta cuando el cliente por defecto ya existía antes del TPV (por ejemplo «Clientes de contado») y tiene varias direcciones: el TPV cogía la última con NIF, que podía ser la de otra persona. Si no se elige ninguna, se usa su dirección «TPV» (la de la tienda) y, si no tiene, se le crea. Al cambiar de cliente por defecto, la dirección vuelve a «Automática» hasta que se elija otra.
- **Servidor de actualizaciones de josra:** aviso diario de la instalación, bloque «Actualizaciones» en la configuración (comprobar y actualizar desde la tienda, con copia previa) y, en PrestaShop 8.1+, la versión nueva en Gestor de módulos > Actualizaciones.

