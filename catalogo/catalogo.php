<?php
session_start();
// Asegúrate de que esta ruta apunte correctamente a tu archivo de conexión
require_once '../cnfg/conexionBD.php'; 

// =======================================================================
// 1. LÓGICA DE BACKEND (PHP + PDO) PARA PETICIONES AJAX (POST)
// =======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $data = json_decode(file_get_contents("php://input"), true);
    $accion = $data['accion'] ?? '';

    try {
        $baseDeDatos = new ConexionBD();
        $conn = $baseDeDatos->getConnection();

        if ($accion === 'agregar') {
            // Insertamos el nuevo producto. Estatus 1 = Activo. Stock inicial 0.
            $query = "INSERT INTO PRODUCTOS (CODIGODEBARRAS, NOMBRE, DESCRIPCION, CATEGORIA, STOCKACTUAL, STOCKMINIMO, STOCKMAXIMO, ESTATUS) 
                      VALUES (:codigo, :nombre, :descripcion, :categoria, 0, :min, :max, 1)";
            $stmt = $conn->prepare($query);
            $stmt->execute([
                ':codigo' => $data['codigo'],
                ':nombre' => $data['nombre'],
                ':descripcion' => $data['descripcion'],
                ':categoria' => $data['abc'],
                ':min' => $data['min'],
                ':max' => $data['max']
            ]);
            echo json_encode(['success' => true, 'message' => 'Producto agregado correctamente']);
            exit;
        } 
        elseif ($accion === 'editar') {
            // Actualizamos los datos del producto
            $query = "UPDATE PRODUCTOS 
                      SET CODIGODEBARRAS = :codigo, NOMBRE = :nombre, DESCRIPCION = :descripcion, 
                          CATEGORIA = :categoria, STOCKMINIMO = :min, STOCKMAXIMO = :max, FECHAMODIFICACION = GETDATE() 
                      WHERE CODIGODEBARRAS = :codigo_original";
            $stmt = $conn->prepare($query);
            $stmt->execute([
                ':codigo' => $data['codigo'],
                ':nombre' => $data['nombre'],
                ':descripcion' => $data['descripcion'],
                ':categoria' => $data['abc'],
                ':min' => $data['min'],
                ':max' => $data['max'],
                ':codigo_original' => $data['codigo_original']
            ]);
            echo json_encode(['success' => true, 'message' => 'Producto actualizado correctamente']);
            exit;
        } 
        elseif ($accion === 'eliminar') {
            // Baja LÓGICA: Cambiamos Estatus a 0 en lugar de hacer un DELETE
            $query = "UPDATE PRODUCTOS SET ESTATUS = 0, FECHAMODIFICACION = GETDATE() WHERE CODIGODEBARRAS = :codigo";
            $stmt = $conn->prepare($query);
            $stmt->execute([':codigo' => $data['codigo']]);
            echo json_encode(['success' => true, 'message' => 'Producto dado de baja exitosamente']);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Acción no válida']);
        exit;

    } catch(PDOException $e) {
        // Si el código de barras ya existe, SQL Server devolverá un error de restricción (Constraint)
        if ($e->getCode() == 23000) {
            echo json_encode(['success' => false, 'message' => 'El código de barras ya está registrado.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error de BD: ' . $e->getMessage()]);
        }
        exit;
    }
}

// =======================================================================
// 2. LÓGICA PARA CARGAR LA VISTA HTML (GET)
// =======================================================================
// Traemos todos los productos activos de la base de datos
$productos = [];
try {
    $baseDeDatos = new ConexionBD();
    $conn = $baseDeDatos->getConnection();
    $stmt = $conn->query("SELECT * FROM PRODUCTOS WHERE ESTATUS = 1 ORDER BY NOMBRE ASC");
    $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $error_bd = "No se pudo cargar el catálogo: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Inventario - Catálogo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../Assets/style.css">
    
    <script>
        // Protección de ruta (Frontend) - OPCIONAL si ya proteges por $_SESSION
        if (localStorage.getItem('sesion_iniciada') !== 'true') {
            window.location.href = '../login/login.html';
        }
    </script>
</head>
<body>

    <nav id="sidebar" class="d-flex flex-column shadow-lg">
        <div class="brand-logo py-4 text-center mb-3">
            <i class="fas fa-boxes fa-2x mb-2"></i>
            <h5 class="mb-0 fw-bold">Gestión de Stock</h5>
        </div>
        <ul class="nav flex-column mb-auto">
            <li class="nav-item admin-only">
                <a href="#" class="nav-link active"><i class="fas fa-book me-3"></i> Catálogo</a>
            </li>
            <li class="nav-item">
                <a href="../movimientos/movimientos.php" class="nav-link"><i class="fas fa-exchange-alt me-3"></i> Movimientos</a>
            </li>
            <li class="nav-item admin-only">
                <a href="../reportes/reportes.html" class="nav-link"><i class="fas fa-chart-line me-3"></i> Reportes</a>
            </li>
            <li class="nav-item admin-only">
                <a href="../usuarios/usuarios.html" class="nav-link"><i class="fas fa-user-cog me-3"></i> Usuarios</a>
            </li>
        </ul>
        <div class="mt-auto mb-4 px-3">
            <button id="btn-cerrar-sesion" class="btn btn-outline-light w-100"><i class="fas fa-sign-out-alt me-2"></i> Salir</button>
        </div>
    </nav>

    <main id="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="h3 mb-0">Catálogo de Productos</h2>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAgregar">
                <i class="fas fa-plus me-2"></i> Agregar Producto
            </button>
        </div>

        <?php if(isset($error_bd)): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?= $error_bd ?></div>
        <?php endif; ?>

        <div class="card shadow-sm border-0 rounded-3">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="px-4">Código</th>
                                <th>Nombre del Producto</th>
                                <th>ABC</th>
                                <th class="text-center">Stock</th> <th class="text-center">Min</th>
                                <th class="text-center">Max</th>
                                <th class="text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="listaCatalogos">
                            <?php if(!empty($productos)): ?>
                                <?php foreach($productos as $prod): 
                                    $cat = trim($prod['CATEGORIA']);
                                    $badgeColor = $cat == 'A' ? 'bg-danger' : ($cat == 'B' ? 'bg-warning text-dark' : 'bg-success');
                                    
                                    // Lógica para el color del stock actual
                                    $stock = $prod['STOCKACTUAL'];
                                    $min = $prod['STOCKMINIMO'];
                                    $max = $prod['STOCKMAXIMO'];
                                    $colorStock = 'text-success fw-bold'; // Óptimo (Verde)
                                    if ($stock < $min) $colorStock = 'text-danger fw-bold'; // Crítico (Rojo)
                                    elseif ($stock > $max) $colorStock = 'text-warning text-dark fw-bold'; // Excedente (Amarillo)
                                ?>
                                <tr data-codigo="<?= htmlspecialchars($prod['CODIGODEBARRAS']) ?>" 
                                    data-nombre="<?= htmlspecialchars($prod['NOMBRE']) ?>" 
                                    data-desc="<?= htmlspecialchars($prod['DESCRIPCION']) ?>"
                                    data-abc="<?= $cat ?>" 
                                    data-min="<?= $min ?>" 
                                    data-max="<?= $max ?>">
                                    
                                    <td class="px-4 fw-bold row-codigo"><?= htmlspecialchars($prod['CODIGODEBARRAS']) ?></td>
                                    <td class="row-nombre">
                                        <?= htmlspecialchars($prod['NOMBRE']) ?><br>
                                        <small class="text-muted row-desc"><?= htmlspecialchars($prod['DESCRIPCION']) ?></small>
                                    </td>
                                    <td><span class="badge <?= $badgeColor ?> row-abc">Clase <?= $cat ?></span></td>
                                    
                                    <td class="text-center <?= $colorStock ?> fs-5"><?= $stock ?></td>
                                    
                                    <td class="text-center row-min"><?= $min ?></td>
                                    <td class="text-center row-max"><?= $max ?></td>
                                    <td class="text-center">
                                        <button class="btn btn-sm btn-outline-primary btn-editar" title="Editar">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger btn-eliminar" title="Dar de Baja">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">No hay productos registrados.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <div class="modal fade" id="modalAgregar" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header border-0">
                    <h5 class="modal-title fw-bold">Nuevo Registro de Producto</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="formNuevoProducto">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Código de Barras</label>
                                <input type="text" class="form-control" id="add-codigo" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Nombre del Producto</label>
                                <input type="text" class="form-control" id="add-nombre" required>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label">Descripción</label>
                                <input type="text" class="form-control" id="add-descripcion">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Categoría ABC</label>
                                <select class="form-select" id="add-abc">
                                    <option value="A">Clase A (Crítico)</option>
                                    <option value="B">Clase B (Regular)</option>
                                    <option value="C" selected>Clase C (Baja rotación)</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Mínimo</label>
                                <input type="number" class="form-control" id="add-min" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Máximo</label>
                                <input type="number" class="form-control" id="add-max" required>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-0">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Guardar Producto</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalEditar" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header border-0">
                    <h5 class="modal-title fw-bold text-primary">Editar Producto</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="formEditarProducto">
                    <div class="modal-body">
                        <input type="hidden" id="edit-codigo-original">
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Código de Barras</label>
                                <input type="text" class="form-control" id="edit-codigo" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Nombre</label>
                                <input type="text" class="form-control" id="edit-nombre" required>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label">Descripción</label>
                                <input type="text" class="form-control" id="edit-descripcion">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Categoría ABC</label>
                                <select class="form-select" id="edit-abc">
                                    <option value="A">Clase A (Crítico)</option>
                                    <option value="B">Clase B (Regular)</option>
                                    <option value="C">Clase C (Baja rotación)</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Mínimo</label>
                                <input type="number" class="form-control" id="edit-min" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Máximo</label>
                                <input type="number" class="form-control" id="edit-max" required>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-0">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Actualizar Cambios</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalEliminar" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content text-center">
                <div class="modal-body p-5">
                    <i class="fas fa-exclamation-triangle text-warning fa-4x mb-3"></i>
                    <h5 class="fw-bold">¿Dar de baja este producto?</h5>
                    <p class="text-muted" id="txt-eliminar-nombre">El producto pasará a estado inactivo.</p>
                    <input type="hidden" id="delete-codigo">
                    
                    <div class="d-flex justify-content-center gap-3 mt-4">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                        <button type="button" class="btn btn-danger" id="btnConfirmarEliminar">Sí, dar de baja</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // CERRAR SESIÓN (Integrado)
        document.getElementById('btn-cerrar-sesion').addEventListener('click', (e) => {
            e.preventDefault();
            localStorage.clear();
            window.location.href = '../login/logout.php';
        });

        // Modales
        const modalAgregar = new bootstrap.Modal(document.getElementById('modalAgregar'));
        const modalEditar = new bootstrap.Modal(document.getElementById('modalEditar'));
        const modalEliminar = new bootstrap.Modal(document.getElementById('modalEliminar'));

        let filaActual = null;

        // --- 1. AGREGAR PRODUCTO ---
        document.getElementById('formNuevoProducto').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const payload = {
                accion: 'agregar',
                codigo: document.getElementById('add-codigo').value,
                nombre: document.getElementById('add-nombre').value,
                descripcion: document.getElementById('add-descripcion').value,
                abc: document.getElementById('add-abc').value,
                min: document.getElementById('add-min').value,
                max: document.getElementById('add-max').value
            };

            const response = await fetch('catalogo.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const result = await response.json();

            if(result.success) {
                // En lugar de inyectar código (que puede fallar si la tabla estaba vacía), recargamos para ver los datos frescos de SQL
                alert(result.message);
                window.location.reload();
            } else {
                alert("Error: " + result.message);
            }
        });

        // --- 2. ABRIR MODALES PARA EDITAR/ELIMINAR ---
        document.getElementById('listaCatalogos').addEventListener('click', function(e) {
            const btnEditar = e.target.closest('.btn-editar');
            const btnEliminar = e.target.closest('.btn-eliminar');

            if (btnEditar) {
                filaActual = btnEditar.closest('tr');
                
                document.getElementById('edit-codigo-original').value = filaActual.dataset.codigo;
                document.getElementById('edit-codigo').value = filaActual.dataset.codigo;
                document.getElementById('edit-nombre').value = filaActual.dataset.nombre;
                document.getElementById('edit-descripcion').value = filaActual.dataset.desc;
                document.getElementById('edit-abc').value = filaActual.dataset.abc;
                document.getElementById('edit-min').value = filaActual.dataset.min;
                document.getElementById('edit-max').value = filaActual.dataset.max;
                
                modalEditar.show();
            }

            if (btnEliminar) {
                filaActual = btnEliminar.closest('tr');
                document.getElementById('delete-codigo').value = filaActual.dataset.codigo;
                document.getElementById('txt-eliminar-nombre').innerText = `El producto "${filaActual.dataset.nombre}" pasará a inactivo.`;
                modalEliminar.show();
            }
        });

        // --- 3. GUARDAR EDICIÓN ---
        document.getElementById('formEditarProducto').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const payload = {
                accion: 'editar',
                codigo_original: document.getElementById('edit-codigo-original').value,
                codigo: document.getElementById('edit-codigo').value,
                nombre: document.getElementById('edit-nombre').value,
                descripcion: document.getElementById('edit-descripcion').value,
                abc: document.getElementById('edit-abc').value,
                min: document.getElementById('edit-min').value,
                max: document.getElementById('edit-max').value
            };

            const response = await fetch('catalogo.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const result = await response.json();

            if(result.success) {
                alert(result.message);
                window.location.reload();
            } else {
                alert("Error: " + result.message);
            }
        });

        // --- 4. CONFIRMAR BAJA ---
        document.getElementById('btnConfirmarEliminar').addEventListener('click', async function() {
            const payload = {
                accion: 'eliminar',
                codigo: document.getElementById('delete-codigo').value
            };

            const response = await fetch('catalogo.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const result = await response.json();

            if(result.success) {
                filaActual.remove(); // Desaparece visualmente de la tabla
                modalEliminar.hide();
                alert(result.message);
            } else {
                alert("Error: " + result.message);
            }
        });
    </script>
</body>
</html>