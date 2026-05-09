# Inventory Migration Plan

## Estado actual

- Conexion: `sqlite`
- Base de datos: `database/database.sqlite`
- Respaldo creado: `database/backups/database_2026_05_05_2025.sqlite`
- Migraciones aplicadas: 16
- Todas las migraciones existentes aparecen como ejecutadas, por lo que no se deben editar migraciones antiguas.

## Tablas actuales con registros

| Tabla | Registros |
| --- | ---: |
| categoria_productos | 13 |
| productos | 10 |
| categoria_insumos | 16 |
| insumos | 20 |
| producto_insumos | 10 |
| movimiento_inventarios | 3 |
| categoria_gastos | 16 |
| users | 1 |
| ventas | 0 |
| venta_detalles | 0 |
| gastos | 0 |
| corte_cajas | 0 |

## Diagnostico

El sistema actual separa `productos` e `insumos`. Los productos se venden, los insumos tienen inventario, y las recetas fijas viven en `producto_insumos`. Esto funciona para productos preparados simples, pero no cubre bien productos vendibles que tambien son inventario, productos simples que descuentan directo, ni productos configurables como una nieve doble con dos sabores distintos.

Los movimientos actuales viven en `movimiento_inventarios` y apuntan solo a `insumo_id`. El nuevo nucleo necesita `inventory_movements` apuntando a `inventory_item_id` para que todo lo que tenga stock sea un item central.

## Riesgos

- Hay datos reales en `productos`, `insumos`, categorias, recetas y movimientos.
- No se deben borrar tablas ni columnas existentes en esta fase.
- No se deben ejecutar comandos destructivos como `migrate:fresh`, `migrate:refresh`, `migrate:reset` o `db:wipe`.
- Los seeders de productos e insumos ya no existen en disco, por lo que la migracion de datos no debe depender de seeders.
- Existen textos con codificacion historica mezclada en algunos seeders; el backfill debe respetar los nombres actuales en BD.
- El sistema actual debe seguir funcionando mientras se introduce el nuevo nucleo.

## Estrategia de respaldo

SQLite:

```powershell
New-Item -ItemType Directory -Force database\backups
Copy-Item database\database.sqlite database\backups\database_YYYY_MM_DD_HHMM.sqlite
```

MySQL/MariaDB, si el proyecto cambia de motor:

```bash
mysqldump -u USER -p DATABASE_NAME > database/backups/database_YYYY_MM_DD_HHMM.sql
```

PostgreSQL, si el proyecto cambia de motor:

```bash
pg_dump -U USER -d DATABASE_NAME -f database/backups/database_YYYY_MM_DD_HHMM.sql
```

## Plan por fases

### Fase 1: Crear nucleo nuevo sin tocar datos viejos

Crear tablas nuevas:

- `units`
- `categories`
- `inventory_items`
- `product_recipes`
- `product_option_groups`
- `product_option_items`
- `inventory_movements`
- `sale_detail_components`

Agregar columnas nullable de compatibilidad:

- `productos.product_type`
- `productos.inventory_item_id`
- `productos.category_id`
- `insumos.inventory_item_id`

Estas columnas deben ser nullable para permitir despliegue gradual.

### Fase 2: Modelos y relaciones

Crear modelos:

- `Unit`
- `Category`
- `InventoryItem`
- `ProductRecipe`
- `ProductOptionGroup`
- `ProductOptionItem`
- `InventoryMovement`
- `SaleDetailComponent`

Actualizar relaciones temporales en modelos existentes:

- `Producto` belongsTo `InventoryItem` nullable
- `Producto` belongsTo `Category` nullable
- `Producto` hasMany `ProductRecipe`
- `Producto` hasMany `ProductOptionGroup`
- `Insumo` belongsTo `InventoryItem` nullable
- `VentaDetalle` hasMany `SaleDetailComponent`

### Fase 3: Backfill idempotente

Crear comando:

```powershell
php artisan inventory:migrate-existing-data --dry-run
php artisan inventory:migrate-existing-data
```

Estrategia:

- Migrar `categoria_productos` a `categories` con type `product`.
- Migrar `categoria_insumos` a `categories` con type `inventory_item`.
- Migrar `categoria_gastos` a `categories` con type `expense`.
- Migrar `insumos` a `inventory_items`, conservando stock, costo y estado.
- Asociar `insumos.inventory_item_id`.
- Asociar `productos.category_id` desde su categoria vieja.
- Para productos con receta de un solo insumo y cantidad 1, proponerlos como `simple`.
- Para productos con recetas, crear `product_recipes`.
- Migrar `movimiento_inventarios` a `inventory_movements`.
- No borrar datos viejos.
- Usar `updateOrCreate` y transacciones para evitar duplicados.

### Fase 4: Servicios

Crear servicios:

- `InventoryMovementService`
- `InventoryEntryService`
- `SaleStockDeductionService`
- `ProductConfigurationService`

El servicio de entrada debe recalcular costo promedio ponderado:

```text
nuevo_promedio =
(stock_actual * costo_promedio_actual + cantidad_entrada * costo_entrada)
/
(stock_actual + cantidad_entrada)
```

La entrada debe actualizar stock, promedio, y crear `inventory_movements` con `stock_after` y `average_cost_after`.

### Fase 5: Venta gradual

- Producto simple descuenta directo `products.inventory_item_id`.
- Producto preparado descuenta `product_recipes`.
- Producto configurable valida grupos y opciones elegidas.
- Cada consumo se registra en `sale_detail_components`.
- Mientras se completa la transicion, `VentaService` e `InventarioService` actuales deben seguir funcionando.

### Fase 6: Administracion operativa de productos

- `/productos/recetas` permite cambiar el tipo de producto:
  - `simple`: descuenta un `inventory_item` directo.
  - `prepared`: descuenta receta fija.
  - `configurable`: descuenta opciones elegidas al vender.
- La receta fija legacy se sincroniza con `product_recipes` cuando el insumo ya tiene `inventory_item_id`.
- Los productos configurables pueden tener grupos como `Sabores` y opciones como `Nieve fresa` o `Nieve vainilla`.
- Al marcar un producto simple, el `inventory_item` directo se marca como vendible.
- Esta fase no elimina la receta legacy ni cambia el POS todavia para capturar opciones configurables desde UI.

### Fase 7: Punto de venta configurable

- `/ventas/nueva` abre un panel de configuracion cuando el producto es `configurable`.
- El cajero puede elegir cantidades por opcion dentro de cada grupo, por ejemplo:
  - Producto: `Nieve doble`
  - Grupo: `Sabores`
  - Opciones: `Nieve fresa x 1`, `Nieve vainilla x 1`
- El carrito conserva las opciones elegidas como `selected_options`.
- `VentaService` recibe esas opciones y descuenta el inventario correcto mediante `SaleStockDeductionService`.
- La venta crea `sale_detail_components` para auditoria de lo consumido.
- El flujo anterior de productos simples/preparados sigue funcionando.

### Fase 8: Flujos completos en interfaz

- `/insumos` crea automaticamente un `inventory_item` por cada insumo nuevo.
- Los insumos de helado, por ejemplo `Helado de queso con zarzamora`, quedan disponibles como sabores configurables.
- `/productos` permite crear productos como:
  - `prepared`: producto preparado con receta fija.
  - `simple`: producto que descuenta un `inventory_item` directo.
  - `configurable`: producto que crea un grupo configurable inicial, como `Sabores`.
- `/productos/recetas` incluye un atajo para buscar inventario por texto y agregarlo al grupo `Sabores`.
- Flujo esperado:
  1. Registrar sabores en `/insumos`.
  2. Crear `Nieve doble` en `/productos` como `configurable`, grupo `Sabores`, requeridas `2`.
  3. Entrar a `/productos/recetas`, seleccionar `Nieve doble` y agregar sabores encontrados por `helado`.
  4. Vender en `/ventas/nueva`, elegir sabores y cobrar.

## Comandos seguros

```powershell
php artisan migrate
php artisan inventory:migrate-existing-data --dry-run
php artisan inventory:migrate-existing-data
php artisan test --compact tests\Feature\InventoryMigrationTest.php
```

## Comandos prohibidos

```powershell
php artisan migrate:fresh
php artisan migrate:refresh
php artisan migrate:reset
php artisan db:wipe
```

## Criterio de rollback

Como esta fase no borra tablas ni columnas, el rollback principal es operativo:

1. Detener el uso de servicios nuevos.
2. Restaurar `database/backups/database_2026_05_05_2025.sqlite` si fuera necesario.
3. Si solo se requiere revertir codigo, remover referencias a los modelos/servicios nuevos.

No se recomienda ejecutar rollback de migraciones sobre datos vivos sin respaldo inmediato.
