# Manual operativo de Corozao

Guía para el uso diario del sistema por rol. Describe los flujos disponibles en la versión actual y sus controles. Las acciones se validan en el servidor; los menús visibles dependen del rol y de las bodegas asignadas.

## 1. Acceso y preparación

1. Inicia sesión con tu cuenta activa. Al terminar, usa **Cerrar sesión** desde el panel.
2. El panel de inicio muestra los módulos disponibles para tu rol.
3. El administrador debe asignar las bodegas de operación a cajeros y meseros desde **Acceso por bodega**. El administrador conserva acceso a todas las bodegas.
4. Antes de vender, el administrador debe verificar que el producto esté activo, habilitado para venta y con un precio vigente y tarifa de IVA configurados para la bodega. La presentación debe estar habilitada en esa bodega.
5. Antes de cobrar, debe haber existencias suficientes y una caja abierta por el cajero en la bodega del pedido.

Los formularios de cambio requieren la sesión autenticada y protección CSRF. Si una operación falla, revisa el mensaje y vuelve a consultar el estado antes de repetirla.

## 2. Roles

| Rol | Uso principal |
|---|---|
| **ADMINISTRADOR** | Configura el catálogo comercial, proveedores y asignaciones; registra compras, movimientos y ajustes; consulta reportes; aprueba devoluciones y cargas iniciales; opera pedidos, ventas y caja. |
| **CAJERO** | Consulta el catálogo y disponibilidad, atiende pedidos pendientes, cobra, registra pagos, emite el registro local de factura pendiente, cierra su caja y solicita devoluciones. No aprueba devoluciones ni modifica inventario. |
| **MESERO** | Crea y mantiene pedidos abiertos, los envía a preparación y solicita que pasen a facturación. No cobra ni registra pagos. |

Las cuentas y nombres de rol no se cambian mediante este manual. Los permisos de consulta, creación, confirmación y aprobación son independientes.

## 3. Catálogo, proveedores y precios

### Consultar productos

En **Catálogo de productos** (`/productos.php`) busca por nombre, marca o código y filtra por categoría o estado. Abre el detalle para revisar presentaciones, códigos y datos comerciales visibles según el rol.

La pantalla actual es de consulta. La creación o edición de productos, categorías, presentaciones y códigos no está expuesta como flujo operativo en esta versión; requiere que esos registros ya existan.

### Proveedores

En **Proveedores** (`/proveedores.php`), el administrador puede:

1. Crear un proveedor y guardar su identificación, razón social y contacto.
2. Buscar y abrir el detalle de un proveedor.
3. Editar sus datos o desactivarlo. La desactivación conserva el registro y sus relaciones.
4. Relacionar una presentación/producto con el proveedor. El sistema conserva las restricciones de clave única y las relaciones existentes.

### Disponibilidad y precio por bodega

En **Catálogo comercial** (`/comercial_bodegas.php`), el administrador:

1. Selecciona la bodega.
2. Busca el producto o presentación.
3. Habilita la presentación para venta en esa bodega.
4. Registra el precio, indica si incluye IVA y selecciona la tarifa de IVA correspondiente.
5. Consulta el historial de precios cuando necesite revisar cambios.

El sistema no permite agregar el producto a un pedido si está inactivo, sin validar, no habilitado en esa bodega o sin precio y tarifa vigentes.

### Asignar bodegas a usuarios

En **Acceso por bodega** (`/asignaciones_bodega.php`), el administrador asigna una bodega activa a una cuenta existente. Una asignación inactiva se puede reactivar. Desactivar una asignación retira el acceso operativo a esa bodega; no elimina al usuario ni sus registros.

## 4. Compras e ingreso de mercadería

En **Compras e ingresos** (`/compras.php`), el administrador sigue este flujo:

1. Selecciona proveedor y bodega de destino.
2. Agrega hasta cinco presentaciones, cantidades y costo unitario por presentación. La presentación determina el producto y su conversión a unidades base.
3. Guarda la compra como **BORRADOR**. Se puede editar mientras siga en ese estado.
4. Revisa proveedor, bodega, cantidades y costos; selecciona **Confirmar e ingresar**.

Al confirmar, en una transacción se crea el movimiento de entrada, se actualiza el saldo y se registra auditoría. La compra confirmada no se edita ni elimina desde el flujo normal. Si hay un error, solicita una corrección administrativa auditada.

## 5. Existencias, Kardex y movimientos

### Consultar existencias y Kardex

En **Inventario y Kardex** (`/inventario.php`), selecciona una bodega y, si quieres, busca un producto o limita el período.

- **Existencias** muestra el saldo actual por producto.
- **Kardex** muestra los movimientos confirmados que afectan a la bodega. Una transferencia en tránsito puede aparecer mientras espera recepción.

La consulta no modifica saldos.

### Transferir entre bodegas

En **Transferencias** (`/transferencias.php`), el administrador:

1. Crea un borrador con bodegas de origen y destino distintas, productos y cantidades.
2. Revisa el borrador y selecciona **Despachar y descontar origen**. El sistema verifica saldo suficiente y registra el movimiento **EN TRÁNSITO**.
3. Cuando llegue la mercadería, selecciona **Recibir y sumar destino**. La recepción confirma el movimiento y aumenta el saldo de destino.

No se edita una transferencia ya despachada ni se vuelve a despachar una confirmada. La recepción actual registra la cantidad despachada completa; diferencias físicas requieren el procedimiento de ajuste autorizado.

### Ajustes positivos o negativos

En **Ajustes de inventario** (`/ajustes.php`), el administrador selecciona bodega, producto, tipo, cantidad y un motivo obligatorio. Primero se guarda el movimiento como borrador; después se confirma.

El ajuste positivo suma existencias. El negativo se rechaza si el saldo no alcanza. La confirmación y la auditoría se guardan junto con el cambio del saldo. No elimines ni alteres movimientos confirmados.

## 6. Conteos físicos y carga inicial

### Registrar un conteo

En **Reportes, conciliación y conteos** (`/reportes.php`), el administrador:

1. Selecciona una bodega y abre un conteo. Solo puede haber un conteo abierto por bodega.
2. Añade cada producto y registra la cantidad física. El sistema captura el saldo teórico existente en ese instante y calcula la diferencia.
3. Cierra el conteo cuando haya al menos una lectura.

El conteo es una lectura de control: **no modifica existencias**. Al cerrar, no se pueden añadir más lecturas.

### Autorizar una carga inicial

En **Inventario inicial** (`/cargas_iniciales.php`), el administrador selecciona un conteo cerrado y revisa qué productos son elegibles. Solo se permite cargar desde la cantidad física guardada en el conteo cuando:

- la cantidad teórica era cero;
- la cantidad física es positiva;
- el producto y la bodega están activos;
- no hay saldo ni movimientos previos de ese producto en esa bodega.

Marca los productos elegibles y confirma. No se ingresan cantidades manuales en este formulario. La operación actualiza saldos, crea un movimiento de ingreso y registra auditoría en una transacción. Se pueden confirmar productos por lotes; una línea ya cargada no se vuelve a sumar.

## 7. Pedidos, venta y caja

### Crear y atender un pedido

En **Pedidos** (`/pedidos.php`), el mesero:

1. Selecciona una bodega permitida y agrega presentaciones y cantidades.
2. Guarda el pedido **ABIERTO**. Puede actualizar cantidades mientras el pedido siga abierto o en preparación.
3. Selecciona **Enviar a preparación** para cambiar a **EN_PREPARACION**. Puede añadir productos mientras se permita editar el pedido.
4. Al finalizar la atención, selecciona **Solicitar facturación**. El pedido pasa a **PENDIENTE_FACTURACION** para que el cajero continúe.

El sistema registra quién creó el pedido y quién realizó cambios relevantes. El mesero no cobra ni cierra caja.

### Abrir caja y cobrar

En **Ventas y caja** (`/ventas.php`), el cajero:

1. Abre una caja para la bodega del pedido e ingresa el fondo inicial. La caja debe corresponder a la fecha operativa actual.
2. Selecciona un pedido **PENDIENTE_FACTURACION** y revisa el detalle y total calculado por el sistema.
3. Ingresa un descuento como valor monetario fijo, si corresponde. No se ingresa como porcentaje y no puede superar el total.
4. Elige entre consumidor final o registrar datos para una factura. Si registra factura, completa tipo e identificación y nombre; los datos opcionales se validan cuando se ingresan.
5. Registra uno o varios pagos entre efectivo, transferencia y tarjeta de crédito. La suma debe ser igual al total después del descuento. Agrega referencia cuando corresponda.
6. Confirma el cobro.

El sistema descuenta existencias, registra venta, pagos, movimiento y auditoría como una operación atómica. Un pedido facturado no vuelve a cobrarse.

### Facturación electrónica y SRI

La selección de factura registra los datos del destinatario y deja la estructura local en estado **PENDIENTE**. Esta versión **no genera XML, firma, transmite ni obtiene autorización del SRI**. El consumidor final evita solicitar los datos de factura.

### Cerrar caja

Al finalizar el turno, declara lo contado/recibido para cada medio que solicite el formulario y cierra la caja. Si hay una diferencia, escribe su motivo. El cierre guarda esperado, declarado, diferencia y conciliación; no se reabre desde el flujo normal.

Si hubo devoluciones, el efectivo esperado ya descuenta las salidas en efectivo. Declara además las transferencias y tarjetas reembolsadas para conciliarlas por separado.

## 8. Devoluciones

### Solicitud por cajero

En **Devoluciones** (`/devoluciones.php`), el cajero busca la venta confirmada por número, selecciona cantidades disponibles y escribe el motivo. Enviar la solicitud no cambia inventario ni caja; queda **PENDIENTE** de aprobación.

Las cantidades ya solicitadas o devueltas quedan reservadas para no devolver más unidades que las vendidas. Una solicitud rechazada libera esa cantidad.

### Revisión y aprobación por administrador

El administrador abre cada solicitud pendiente y, por cada producto, elige una opción:

- **Vendible:** se devuelve a existencias mediante un movimiento positivo auditado.
- **No vendible:** no se suma al saldo de inventario.

El sistema calcula el reembolso con los precios y el descuento de la venta, y lo distribuye proporcionalmente entre los medios de pago originales. Para transferencias y tarjetas exige una referencia del reembolso.

Si el reembolso es mayor que cero, el administrador debe tener una caja abierta en la bodega de la venta. El efectivo disponible debe alcanzar para una devolución en efectivo. La aprobación registra pago, caja, movimiento de inventario cuando corresponde y auditoría en una misma transacción. El administrador también puede rechazar la solicitud con un motivo obligatorio.

## 9. Reportes

En **Reportes y conteos** (`/reportes.php`), selecciona rango de fechas y, opcionalmente, bodega, usuario, producto o medio. La página presenta ventas, pagos, cantidades por producto, cierres, conciliaciones, conteos y —si la migración está instalada— devoluciones aprobadas con responsable, artículos, vendibilidad y reembolsos.

En el filtro de usuario del bloque de devoluciones, el usuario corresponde a quien aprobó. El reporte consulta movimientos históricos; no corrige ni reconstruye registros.

## 10. Reglas y situaciones frecuentes

- **No aparece una bodega:** solicita al administrador que revise la asignación de tu usuario y que la bodega esté activa.
- **Un producto no se puede añadir al pedido:** revisa estado activo, validación comercial, habilitación en la bodega, precio vigente y tarifa de IVA.
- **No se puede confirmar una compra o transferencia:** revisa que los productos estén activos y que el saldo alcance en la bodega de origen.
- **No puedes cobrar:** verifica estado del pedido, caja abierta en la bodega correcta, fecha operativa, precios y existencias.
- **No se aprueba un reembolso:** abre caja en la bodega de la venta; para reembolso efectivo, verifica el efectivo disponible; registra referencia para transferencia o tarjeta.
- **No se permite cargar inventario inicial:** esa línea ya tiene saldo o historial, el teórico no era cero, el físico no es positivo o la bodega/producto está inactivo. No fuerces el proceso; usa el procedimiento administrativo apropiado y auditado.
- Las ventas, compras, transferencias, ajustes, cargas y devoluciones confirmadas conservan su historial. Las correcciones deben usar un procedimiento autorizado; no borres filas ni modifiques directamente saldos o movimientos.

## 11. Funciones fuera de la interfaz actual

Esta versión no incorpora la integración con el SRI ni pantallas de alta/edición para productos, categorías, presentaciones, códigos, bodegas o cuentas de usuario. El panel y los permisos no sustituyen esos flujos administrativos. No completes esos pasos editando datos directamente en producción sin el procedimiento correspondiente.
