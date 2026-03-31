<?php
// =======================================================================
// 1. LÓGICA DE BACKEND (PHP + PDO)
// =======================================================================
// Aquí procesamos las peticiones AJAX que vienen del frontend.
// Nota: Asegúrate de tener habilitada la extensión pdo_sqlsrv en tu php.ini

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    // Recibimos los datos enviados en formato JSON
    $data = json_decode(file_get_contents("php://input"), true);
    $accion = $data['accion'] ?? '';

    /* // ESQUELETO DE CONEXIÓN PDO A SQL SERVER (Descomentar para usar)
    try {
        $conn = new PDO("sqlsrv:server=localhost\\SQLEXPRESS;Database=InventarioDB", "usuario", "password");
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error de conexión: ' . $e->getMessage()]);
        exit;
    }
    */

    // Simulador de respuestas del servidor
    if ($accion === 'agregar') {
        // Ejecutarías: INSERT INTO PRODUCTO (...) VALUES (...)
        echo json_encode(['success' => true, 'message' => 'Producto agregado correctamente']);
        exit;
    } 
    elseif ($accion === 'editar') {
        // Ejecutarías: UPDATE PRODUCTO SET NOMBRE = ?, CMAXIMA = ? ... WHERE IDPRODUCTO = ?
        echo json_encode(['success' => true, 'message' => 'Producto actualizado correctamente']);
        exit;
    } 
    elseif ($accion === 'eliminar') {
        // Ejecutarías: UPDATE PRODUCTO SET ESTATUS = 'Inactivo', FECHBAJA = GETDATE() WHERE IDPRODUCTO = ?
        echo json_encode(['success' => true, 'message' => 'Producto dado de baja exitosamente']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Acción no válida']);
    exit;
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
        // Protección de ruta (Frontend)
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
            <li class="nav-item">
                <a href="../panelAdmin/panelAdmin.php" class="nav-link"><i class="fas fa-home me-3"></i> Inicio</a>
            </li>
            <li class="nav-item admin-only">
                <a href="../catalogo/catalogo.html" class="nav-link active"><i class="fas fa-book me-3"></i> Catálogo</a>
            </li>
            <li class="nav-item">
                <a href="../movimientos/movimientos.html" class="nav-link"><i class="fas fa-exchange-alt me-3"></i> Movimientos</a>
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

        <div class="card shadow-sm border-0 rounded-3">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="px-4">Código</th>
                                <th>Nombre del Producto</th>
                                <th>ABC</th>
                                <th class="text-center">Min</th>
                                <th class="text-center">Max</th>
                                <th class="text-center">Empaque</th>
                                <th class="text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="listaCatalogos">
                            <tr data-codigo="7501020304" data-nombre="Cable Cobre 12" data-abc="A" data-min="20" data-max="200" data-empaque="Unidad">
                                <td class="px-4 fw-bold row-codigo">7501020304</td>
                                <td class="row-nombre">Cable Cobre 12</td>
                                <td><span class="badge bg-danger row-abc">Clase A</span></td>
                                <td class="text-center row-min">20</td>
                                <td class="text-center row-max">200</td>
                                <td class="text-center row-empaque">Unidad</td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-outline-primary btn-editar" title="Editar">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger btn-eliminar" title="Dar de Baja">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
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
                                <label class="form-label">Nombre</label>
                                <input type="text" class="form-control" id="add-nombre" required>
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
                                <label class="form-label">Estándar Empaque</label>
                                <select class="form-select" id="add-empaque">
                                    <option value="Unidad">Por Unidad</option>
                                    <option value="Caja">Por Caja (Volumen)</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Mínimo</label>
                                <input type="number" class="form-control" id="add-min" required>
                            </div>
                            <div class="col-md-2">
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
                            <div class="col-md-4">
                                <label class="form-label">Categoría ABC</label>
                                <select class="form-select" id="edit-abc">
                                    <option value="A">Clase A (Crítico)</option>
                                    <option value="B">Clase B (Regular)</option>
                                    <option value="C">Clase C (Baja rotación)</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Estándar Empaque</label>
                                <select class="form-select" id="edit-empaque">
                                    <option value="Unidad">Por Unidad</option>
                                    <option value="Caja">Por Caja (Volumen)</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Mínimo</label>
                                <input type="number" class="form-control" id="edit-min" required>
                            </div>
                            <div class="col-md-2">
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
        // Cerrar sesión
        document.getElementById('btn-cerrar-sesion').addEventListener('click', () => {
            localStorage.clear();
            window.location.href = '../login/login.html';
        });

        // Instancias de los modales
        const modalAgregar = new bootstrap.Modal(document.getElementById('modalAgregar'));
        const modalEditar = new bootstrap.Modal(document.getElementById('modalEditar'));
        const modalEliminar = new bootstrap.Modal(document.getElementById('modalEliminar'));

        // Variable para guardar la fila que estamos editando actualmente
        let filaActual = null;

        // --- 1. LÓGICA: AGREGAR PRODUCTO ---
        document.getElementById('formNuevoProducto').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const payload = {
                accion: 'agregar',
                codigo: document.getElementById('add-codigo').value,
                nombre: document.getElementById('add-nombre').value,
                abc: document.getElementById('add-abc').value,
                empaque: document.getElementById('add-empaque').value,
                min: document.getElementById('add-min').value,
                max: document.getElementById('add-max').value
            };

            // Enviar a PHP
            const response = await fetch('catalogo.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const result = await response.json();

            if(result.success) {
                // Agregar fila a la tabla dinámicamente
                const tabla = document.getElementById('listaCatalogos');
                const badgeColor = payload.abc === 'A' ? 'bg-danger' : (payload.abc === 'B' ? 'bg-warning' : 'bg-success');
                
                const row = `
                    <tr data-codigo="${payload.codigo}" data-nombre="${payload.nombre}" data-abc="${payload.abc}" data-min="${payload.min}" data-max="${payload.max}" data-empaque="${payload.empaque}">
                        <td class="px-4 fw-bold row-codigo">${payload.codigo}</td>
                        <td class="row-nombre">${payload.nombre}</td>
                        <td><span class="badge ${badgeColor} row-abc">Clase ${payload.abc}</span></td>
                        <td class="text-center row-min">${payload.min}</td>
                        <td class="text-center row-max">${payload.max}</td>
                        <td class="text-center row-empaque">${payload.empaque}</td>
                        <td class="text-center">
                            <button class="btn btn-sm btn-outline-primary btn-editar" title="Editar"><i class="fas fa-edit"></i></button>
                            <button class="btn btn-sm btn-outline-danger btn-eliminar" title="Dar de Baja"><i class="fas fa-trash"></i></button>
                        </td>
                    </tr>`;
                tabla.innerHTML += row;
                
                modalAgregar.hide();
                this.reset();
                alert(result.message);
            }
        });

        // --- 2. DELEGACIÓN DE EVENTOS PARA EDITAR Y ELIMINAR ---
        // Usamos delegación en la tabla porque las filas se generan dinámicamente
        document.getElementById('listaCatalogos').addEventListener('click', function(e) {
            const btnEditar = e.target.closest('.btn-editar');
            const btnEliminar = e.target.closest('.btn-eliminar');

            if (btnEditar) {
                filaActual = btnEditar.closest('tr');
                
                // Llenar el formulario del modal de edición
                document.getElementById('edit-codigo-original').value = filaActual.dataset.codigo;
                document.getElementById('edit-codigo').value = filaActual.dataset.codigo;
                document.getElementById('edit-nombre').value = filaActual.dataset.nombre;
                document.getElementById('edit-abc').value = filaActual.dataset.abc;
                document.getElementById('edit-empaque').value = filaActual.dataset.empaque;
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

        // --- 3. LÓGICA: GUARDAR EDICIÓN ---
        document.getElementById('formEditarProducto').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const payload = {
                accion: 'editar',
                codigo_original: document.getElementById('edit-codigo-original').value,
                codigo: document.getElementById('edit-codigo').value,
                nombre: document.getElementById('edit-nombre').value,
                abc: document.getElementById('edit-abc').value,
                empaque: document.getElementById('edit-empaque').value,
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
                // Actualizar los datos del dataset en el HTML
                filaActual.dataset.codigo = payload.codigo;
                filaActual.dataset.nombre = payload.nombre;
                filaActual.dataset.abc = payload.abc;
                filaActual.dataset.min = payload.min;
                filaActual.dataset.max = payload.max;
                filaActual.dataset.empaque = payload.empaque;

                // Actualizar lo visual en la tabla
                const badgeColor = payload.abc === 'A' ? 'bg-danger' : (payload.abc === 'B' ? 'bg-warning' : 'bg-success');
                filaActual.querySelector('.row-codigo').innerText = payload.codigo;
                filaActual.querySelector('.row-nombre').innerText = payload.nombre;
                filaActual.querySelector('.row-abc').className = `badge ${badgeColor} row-abc`;
                filaActual.querySelector('.row-abc').innerText = `Clase ${payload.abc}`;
                filaActual.querySelector('.row-min').innerText = payload.min;
                filaActual.querySelector('.row-max').innerText = payload.max;
                filaActual.querySelector('.row-empaque').innerText = payload.empaque;

                modalEditar.hide();
                alert(result.message);
            }
        });

        // --- 4. LÓGICA: CONFIRMAR BAJA (ELIMINAR) ---
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
                // Removemos la fila de la vista
                filaActual.remove();
                modalEliminar.hide();
                alert(result.message);
            }
        });
    </script>
</body>
</html>