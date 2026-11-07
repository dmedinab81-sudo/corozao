# Guion de pruebas funcionales de Corozao

Este documento prepara la verificación funcional por rol. **Ninguno de estos casos se considera ejecutado por estar escrito aquí.** Ejecutarlos solo en una instancia aislada de pruebas, con cuentas y datos sintéticos. No usar producción ni datos de clientes.

## Preparación del entorno

Antes de comenzar, registrar en la bitácora de ejecución:

- URL y nombre identificable del entorno de pruebas.
- Confirmación de que la base está aislada de producción y se puede restaurar.
- Versión/commit bajo prueba y migraciones aplicadas en ese entorno.
- Cuentas de prueba con roles ADMINISTRADOR, CAJERO y MESERO.
- Bodegas, productos, presentaciones, precios, existencias, ventas y pedidos sintéticos necesarios.
- Saldo inicial de cada producto/bodega y medio de pago que se usarán en las verificaciones.

Crear los datos exclusivamente en el entorno de pruebas. Tomar una copia/restauración del estado inicial antes de la ejecución. No incluir contraseñas, tokens ni datos personales reales en evidencias.

## Registro de resultados

Para cada caso anotar: fecha/hora, ejecutor, resultado `PENDIENTE` / `APROBADO` / `FALLIDO` / `BLOQUEADO`, identificadores sintéticos creados, resultado observado y evidencia sin secretos. Si un caso no se ejecutó, conservar `PENDIENTE`; no inferir aprobación.

## Casos funcionales

| ID | Rol | Flujo y pasos | Resultado esperado |
|---|---|---|---|
| AUT-01 | Sin sesión | Abrir directamente una página operativa y enviar una solicitud de cambio. | Se requiere autenticación; no se modifica ningún registro. |
| AUT-02 | MESERO | Intentar acceder directamente a compras, ajustes, transferencias, caja/ventas y administración de usuarios. | El servidor deniega cada operación aunque se conozca la URL; no basta con ocultar el menú. |
| AUT-03 | CAJERO | Intentar confirmar un ajuste/transferencia, aprobar una devolución y administrar proveedores/usuarios. | Operaciones denegadas por rol; ningún saldo o estado cambia. |
| AUT-04 | Cualquier rol autenticado | Enviar un formulario de modificación sin token CSRF válido. | Solicitud rechazada; no se persiste el cambio. |
| PRO-01 | ADMINISTRADOR | Crear producto con categoría existente, primera presentación y código nuevo; buscarlo por código, nombre y marca. | Producto, presentación y código se guardan juntos; aparece en listado y filtros. |
| PRO-02 | ADMINISTRADOR | Intentar crear un código duplicado o una presentación duplicada para el mismo producto. | Se informa el conflicto y la transacción revierte cualquier fila parcial. |
| PRO-03 | ADMINISTRADOR | Editar datos del producto, añadir otra presentación/código, desactivar y reactivar producto/presentación. | Cambios auditados; historial y relaciones previas se conservan; el producto inactivo deja de estar disponible para venta. |
| PRO-04 | CAJERO / MESERO | Consultar el detalle de producto y sus precios con asignación a una y varias bodegas. | Cada rol consulta solo precios de bodegas asignadas; no puede crear ni modificar datos de catálogo. |
| USR-01 | ADMINISTRADOR | Crear una cuenta de cada rol; probar usuario duplicado, contraseña corta y confirmación distinta. | Roles limitados al enum existente; hash no reversible; duplicado y contraseñas inválidas se rechazan. |
| USR-02 | ADMINISTRADOR | Editar nombre, usuario, rol y estado; cambiar contraseña desde el formulario separado. | Cambios auditados sin hash ni contraseña en la auditoría; nuevo acceso valida con `password_verify`. |
| USR-03 | ADMINISTRADOR | Intentar desactivar o cambiar de rol al único administrador activo. | Operación bloqueada y permanece al menos un administrador activo. |
| USR-04 | CAJERO / MESERO | Abrir directamente `/usuarios.php` y enviar POST con rol cambiado manualmente. | Servidor devuelve denegación y no modifica cuentas. |
| PED-01 | MESERO | Crear pedido en bodega asignada con productos/precios válidos. | Pedido `ABIERTO`, detalle y usuario creador correctos; existencias sin cambio. |
| PED-02 | MESERO | Cambiar cantidades y añadir una línea mientras el pedido sea editable; enviarlo a preparación y pedir facturación. | Transiciones válidas `ABIERTO` → `EN_PREPARACION` → `PENDIENTE_FACTURACION`; autoría de cambios correcta. |
| PED-03 | MESERO | Enviar cantidades inválidas o intentar editar un pedido que ya no esté abierto/en preparación. | Validación o denegación; el pedido conserva sus valores previos. |
| INV-01 | ADMINISTRADOR | Crear compra borrador, editarla y confirmarla con existencias iniciales conocidas. | Una sola confirmación crea movimiento/auditoría y aumenta el saldo en la cantidad base esperada. |
| INV-02 | ADMINISTRADOR | Reenviar la confirmación de compra o repetirla con la misma clave de idempotencia. | No se crea un segundo movimiento ni se duplica el saldo. |
| INV-03 | ADMINISTRADOR | Despachar transferencia válida y recibirla en la bodega destino. | Despacho descuenta origen una vez; recepción suma destino una vez; estado y Kardex reflejan tránsito/confirmación. |
| INV-04 | ADMINISTRADOR | Despachar cantidad mayor al saldo disponible; repetir despacho/recepción ya procesados. | Operación sin saldo suficiente se rechaza; reintentos no duplican movimientos. |
| INV-05 | ADMINISTRADOR | Crear ajuste con motivo; confirmar uno positivo y uno negativo válido. Probar negativo superior al saldo. | Los ajustes confirmados afectan el saldo exactamente una vez y quedan auditados; negativo excesivo se rechaza. |
| INV-06 | ADMINISTRADOR | Registrar y cerrar un conteo; corregir y retirar lecturas antes de cargarlas; intentar cambiar una línea ya aplicada a carga inicial. | El conteo no cambia inventario; cada corrección/retiro queda auditado; lecturas aplicadas a una carga quedan bloqueadas. |
| INV-07 | ADMINISTRADOR | Cargar una línea elegible de inventario inicial y repetir el intento. | Carga solo desde el conteo cerrado; crea un ingreso auditable una vez. Línea inelegible o duplicada no aumenta saldo. |
| VEN-01 | CAJERO | Abrir caja para bodega permitida; probar cobros cruzando medianoche, antes de las 24 horas y al cumplir las 24 horas; intentar abrir otra caja concurrente para el mismo usuario/bodega. | Se permiten cobros hasta completar 24 horas desde apertura; después se exige cerrar. La restricción de caja abierta única se respeta. |
| VEN-02 | CAJERO | Cobrar pedido pendiente con descuento monetario, un medio de pago y luego con pagos divididos. | Total/descuento calculados por servidor; pago exacto confirma venta, pagos, movimiento de salida y auditoría en una unidad. |
| VEN-03 | CAJERO | Probar pagos cuya suma sea menor o mayor al total, saldo insuficiente y doble envío del cobro. | Casos inválidos no confirman venta ni descuentan inventario; reintento no duplica la venta. |
| VEN-04 | CAJERO | Cobrar una vez como consumidor final y otra solicitando factura con datos sintéticos. | Se almacenan los datos elegidos; factura electrónica queda pendiente. No se afirma emisión/autorización SRI. |
| CAJ-01 | CAJERO | Cerrar caja con conteos por medio exactos; luego probar diferencia con observación. | Totales esperados, declarados, diferencia y conciliación guardados; explicación obligatoria cuando aplique. Caja cerrada no vuelve a cobrar. |
| DEV-01 | CAJERO | Solicitar devolución parcial con motivo y cantidades dentro de lo vendido. | Solicitud pendiente; sin cambios de caja ni inventario; cantidades solicitadas quedan reservadas. |
| DEV-02 | CAJERO | Intentar devolver más unidades que las vendidas o duplicar la solicitud. | Servidor rechaza exceso/doble aplicación; cantidades y saldo permanecen consistentes. |
| DEV-03 | ADMINISTRADOR | Aprobar devolución en caja abierta de la bodega de venta, marcando una línea vendible y otra no vendible. | Reembolso proporcional por medios originales; solo la línea vendible reintegra stock; caja, movimiento y auditoría consistentes. |
| DEV-04 | ADMINISTRADOR | Intentar aprobar sin caja apropiada, sin efectivo suficiente o sin referencia para transferencia/tarjeta. | Aprobación se rechaza íntegramente; solicitud sigue pendiente y no cambia caja/inventario. |
| DEV-05 | ADMINISTRADOR | Rechazar solicitud pendiente con motivo; intentar resolverla otra vez. | Rechazo y motivo auditados; reserva liberada; no hay reembolso ni movimiento de stock; no se resuelve dos veces. |
| REP-01 | ADMINISTRADOR / CAJERO | Consultar reportes con rangos y filtros; comparar un conjunto sintético conocido. | Totales y filtros corresponden a los registros del período y bodegas autorizados; consulta no altera datos. |

## Verificación al terminar

Para operaciones confirmadas, reconciliar cada saldo con el Kardex y sus documentos fuente. Verificar que cada cambio registre usuario, fecha/hora, acción, entidad y valores relevantes sin secretos. Comparar el estado final con los efectos esperados, revisar que no haya duplicados y restaurar el entorno de pruebas si corresponde.

## Límites de este guion

- No cubre emisión, firma, transmisión ni autorización de comprobantes en el SRI.
- No habilita procedimientos de anulación de ventas/facturas ni modifica movimientos confirmados.
- Los pasos requieren un entorno de pruebas y cuentas/datos sintéticos que todavía deben confirmarse antes de iniciar la ejecución.

## Búsqueda AJAX y venta directa (pendiente de ejecutar en entorno de pruebas)

| ID | Rol | Acción | Resultado esperado |
|---|---|---|---|
| BUS-01 | MESERO | Buscar por código, nombre, marca, categoría, contenido y presentación; probar menos de dos caracteres y teclado. | Resultados descriptivos y restringidos a productos vendibles y precio vigente de su bodega; mínimo de caracteres no consulta; teclado puede elegir resultado. |
| BUS-02 | ADMINISTRADOR | Buscar desde compras en una bodega asignada y revisar la respuesta de red. | Solo productos/presentaciones activos; la respuesta no expone precios de venta. |
| PED-04 | MESERO | Añadir, editar cantidad y quitar presentaciones mediante la tabla dinámica; guardar y revisar el pedido. | No hay filas fijas; se preserva detalle, auditoría y validación de servidor. |
| COM-03 | ADMINISTRADOR | Añadir varias presentaciones, cambiar cantidades/costos, quitar una línea, guardar borrador y confirmarlo. | El costo ingresado es independiente del precio de venta; el saldo cambia solo al confirmar. |
| COM-04 | ADMINISTRADOR | Con la migración de IVA instalada, ingresar costos antes de IVA con distintas tarifas, incluyendo 0; guardar y confirmar. | El servidor calcula IVA por separado, totaliza neto e impuesto, registra el detalle en auditoría y mantiene el costo neto como costo del movimiento de inventario. Sin tarifa no se confirma. |
| COM-05 | ADMINISTRADOR | Con datos sintéticos y la migración instalada, revertir la compra confirmada más reciente; después intentar duplicar, revertir una compra con movimientos posteriores o pendientes y una con saldo insuficiente. | La reversión crea una SALIDA vinculada, copia cantidades/costos netos, ajusta saldo y audita todo en una transacción; conserva la compra original; duplicados, secuencia insegura y saldo insuficiente se rechazan sin cambios parciales. Revertir compras del mismo producto en orden descendente de fecha/ID. |
| VEN-05 | CAJERO | Intentar búsqueda y venta directa sin permiso, con bodega ajena, precio/IVA enviados falsos, pago incorrecto, saldo insuficiente y reenvío de solicitud. | Se deniega el rol/bodega no permitidos; importes del navegador se ignoran; fallas no dejan pago, venta, movimiento ni saldo parcial; repetición no duplica. |
| VEN-06 | CAJERO | Con migración aplicada, caja abierta y datos sintéticos, hacer venta directa con un producto y con pagos divididos. | Una sola venta sin pedido, snapshots por línea, pagos exactos, salida de inventario, factura local pendiente y auditoría en transacción. |
| VEN-07 | CAJERO | Abrir el comprobante interno de una venta propia, imprimir/guardar PDF y probar con el ID de una venta ajena. | Muestra líneas, IVA, descuento, total y pagos; salida legible en papel/PDF; venta ajena devuelve 404; aviso visible de que no es comprobante tributario ni fue enviada al SRI. |
