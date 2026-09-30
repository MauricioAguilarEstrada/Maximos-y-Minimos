<?php
session_start();

if (!isset($_SESSION['usuario_bd'])) {
    header("Location: ../login/login.html");
    exit;
}

$idUsuarioActual = $_SESSION['usuario_bd']; 
$rolUsuarioActual = (strpos($idUsuarioActual, 'ADM') === 0) ? 'Administrador' : 'Operador';

// Parámetros Neon
$host = 'ep-royal-fire-b4e0qwit-pooler.c-6.us-east-2.aws.neon.tech';
$dbname = 'neondb';
$endpoint_id = 'ep-royal-fire-b4e0qwit';
$dsn = "pgsql:host=$host;port=5432;dbname=$dbname;sslmode=require;options='endpoint=$endpoint_id'";

// =======================================================================
// 1. LÓGICA DE BACKEND (PHP + PDO) PARA PETICIONES AJAX (POST)
// =======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $data = json_decode(file_get_contents("php://input"), true);
    $accion = $data['accion'] ?? '';

    try {
        $conn = new PDO($dsn, $_SESSION['usuario_bd'], $_SESSION['password_bd'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);

        if ($accion === 'agregar') {
            $query = "INSERT INTO public.productos (codigodebarras, nombre, descripcion, categoria, stockactual, stockminimo, stockmaximo, estatus) 
                      VALUES (:codigo, :nombre, :descripcion, :categoria, 0, :min, :max, true)";
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
            $query = "UPDATE public.productos 
                      SET codigodebarras = :codigo, nombre = :nombre, descripcion = :descripcion, 
                          categoria = :categoria, stockminimo = :min, stockmaximo = :max, fechamodificacion = CURRENT_TIMESTAMP 
                      WHERE codigodebarras = :codigo_original";
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
        elseif ($accion === 'cambiar_estatus') {
            $estatusBool = $data['estatus'] == '1' ? 'true' : 'false';
            $query = "UPDATE public.productos SET estatus = $estatusBool, fechamodificacion = CURRENT_TIMESTAMP WHERE codigodebarras = :codigo";
            $stmt = $conn->prepare($query);
            $stmt->execute([':codigo' => $data['codigo']]);
            echo json_encode(['success' => true, 'message' => 'El estatus del producto ha sido actualizado.']);
            exit;
        }
        elseif ($accion === 'autorizar_admin') {
            $adminUser = $data['adminUser'] ?? '';
            $adminPass = $data['password'] ?? '';
            
            if(strpos($adminUser, 'ADM') !== 0) {
                echo json_encode(['success' => false, 'message' => 'El usuario proporcionado no es un Administrador (ADM-XXXX).']);
                exit;
            }

            try {
                $adminConn = new PDO($dsn, $adminUser, $adminPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                echo json_encode(['success' => true]);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'message' => 'Credenciales de administrador incorrectas.']);
            }
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Acción no válida']);
        exit;

    } catch(PDOException $e) {
        if ($e->getCode() == '23505') { // 23505 es el código de Postgres para "Unique Violation"
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
$productos = [];
try {
    $connGET = new PDO($dsn, $_SESSION['usuario_bd'], $_SESSION['password_bd'], [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    
    $query = "
        SELECT * FROM public.productos 
        ORDER BY 
            estatus DESC,
            CASE 
                WHEN stockactual < stockminimo OR stockactual > stockmaximo THEN 0
                ELSE 1
            END ASC,
            nombre ASC
    ";
    $stmt = $connGET->query($query);
    $productos = $stmt->fetchAll();
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
</head>
<body>

    <nav id="sidebar" class="d-flex flex-column shadow-lg">
        <div class="brand-logo py-4 text-center mb-3">
            <i class="fas fa-boxes fa-2x mb-2"></i>
            <h5 class="mb-0 fw-bold">Gestión de Stock</h5>
            <small class="text-white-50"><?= htmlspecialchars($rolUsuarioActual) ?> (<?= htmlspecialchars($idUsuarioActual) ?>)</small>
        </div>
        <ul class="nav flex-column mb-auto">
            <li class="nav-item">
                <a href="../panelAdmin/panelAdmin.php" class="nav-link"><i class="fas fa-home me-3"></i> Inicio</a>
            </li>
            <li class="nav-item">
                <a href="../perfil/perfil.php" class="nav-link"><i class="fas fa-user-circle me-3"></i> Mi Perfil</a>
            </li>
            <li class="nav-item">
                <a href="../catalogo/catalogo.php" class="nav-link"><i class="fas fa-book me-3"></i> Catálogo</a>
            </li>
            <li class="nav-item">
                <a href="../movimientos/movimientos.php" class="nav-link"><i class="fas fa-exchange-alt me-3"></i> Movimientos</a>
            </li>
            
            <li class="nav-item <?= ($rolUsuarioActual === 'Operador') ? 'd-none' : '' ?>">
                <a href="../reportes/reportes.php" class="nav-link"><i class="fas fa-chart-line me-3"></i> Reportes</a>
            </li>
            <li class="nav-item <?= ($rolUsuarioActual === 'Operador') ? 'd-none' : '' ?>">
                <a href="../usuarios/usuarios.php" class="nav-link"><i class="fas fa-user-cog me-3"></i> Usuarios</a>
            </li>
        </ul>
        <div class="mt-auto mb-4 px-3">
            <button id="btn-cerrar-sesion" class="btn btn-outline-light w-100"><i class="fas fa-sign-out-alt me-2"></i> Salir</button>
        </div>
    </nav>

    <main id="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="h3 mb-0">Catálogo de Productos</h2>
            <button class="btn btn-primary" id="btnAgregarPrincipal">
                <i class="fas fa-plus me-2"></i> Agregar Producto
            </button>
        </div>

        <?php if(isset($error_bd)): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?= $error_bd ?></div>
        <?php endif; ?>

        <div class="card shadow-sm border-0 rounded-3 mb-4">
            <div class="card-body p-3 d-flex flex-wrap gap-3 align-items-center bg-white rounded-3">
                <div class="flex-grow-1">
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0 text-muted"><i class="fas fa-search"></i></span>
                        <input type="text" class="form-control bg-light border-start-0 ps-0" id="inputBuscador" placeholder="Buscar por código, nombre o descripción..." autocomplete="off">
                    </div>
                </div>
                <div style="min-width: 200px;">
                    <select id="filtro-abc" class="form-select text-secondary border-secondary">
                        <option value="ALL">Todas las Categorías</option>
                        <option value="A">Clase A (Crítico)</option>
                        <option value="B">Clase B (Regular)</option>
                        <option value="C">Clase C (Baja rotación)</option>
                    </select>
                </div>
                <div style="min-width: 180px;">
                    <select id="filtro-estatus" class="form-select text-secondary border-secondary">
                        <option value="ALL">Todos los Estatus</option>
                        <option value="1">Activos</option>
                        <option value="0">Inactivos</option>
                    </select>
                </div>
            </div>
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
                                <th class="text-center">Stock</th>
                                <th class="text-center">Límites (Mín/Máx)</th>
                                <th class="text-center">Estatus</th>
                                <th class="text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="listaCatalogos">
                            <?php $totalCriticos = 0; ?>
                            <?php if(!empty($productos)): ?>
                                <?php foreach($productos as $prod): 
                                    $cat = trim($prod['categoria']);
                                    $badgeColor = $cat == 'A' ? 'bg-danger' : ($cat == 'B' ? 'bg-warning text-dark' : 'bg-success');
                                    
                                    $stock = $prod['stockactual'];
                                    $min = $prod['stockminimo'];
                                    $max = $prod['stockmaximo'];
                                    
                                    $esCritico = ($stock < $min || $stock > $max);
                                    if ($esCritico && $prod['estatus'] == true) {
                                        $totalCriticos++;
                                    }

                                    $colorStock = $esCritico ? 'text-danger fw-bold' : 'text-success fw-bold'; 
                                    $estatusTexto = $prod['estatus'] == true ? 'Activo' : 'Inactivo';
                                    $badgeEstatus = $prod['estatus'] == true ? 'bg-success' : 'bg-secondary';

                                    $claseFila = '';
                                    if ($prod['estatus'] == false) {
                                        $claseFila = 'bg-light text-muted';
                                    } elseif ($esCritico) {
                                        $claseFila = 'table-warning';
                                    }
                                ?>
                                <tr data-codigo="<?= htmlspecialchars($prod['codigodebarras']) ?>" 
                                    data-nombre="<?= htmlspecialchars($prod['nombre']) ?>" 
                                    data-desc="<?= htmlspecialchars($prod['descripcion']) ?>"
                                    data-abc="<?= $cat ?>" 
                                    data-min="<?= $min ?>" 
                                    data-max="<?= $max ?>"
                                    data-estatus="<?= $prod['estatus'] ? '1' : '0' ?>"
                                    class="<?= $claseFila ?>">
                                    
                                    <td class="px-4 fw-bold row-codigo"><?= htmlspecialchars($prod['codigodebarras']) ?></td>
                                    <td class="row-nombre">
                                        <?= htmlspecialchars($prod['nombre']) ?><br>
                                        <small class="text-muted row-desc"><?= htmlspecialchars($prod['descripcion']) ?></small>
                                    </td>
                                    <td><span class="badge <?= $badgeColor ?> row-abc">Clase <?= $cat ?></span></td>
                                    <td class="text-center <?= $colorStock ?> fs-5"><?= $stock ?></td>
                                    <td class="text-center">
                                        <small class="text-muted">Min: <span class="fw-bold"><?= $min ?></span> / Max: <span class="fw-bold"><?= $max ?></span></small>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge <?= $badgeEstatus ?>"><?= $estatusTexto ?></span>
                                    </td>
                                    <td class="text-center">
                                        <button class="btn btn-sm btn-outline-primary btn-editar admin-only" title="Editar">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        
                                        <?php if($prod['estatus'] == true): ?>
                                            <button class="btn btn-sm btn-outline-warning btn-estatus ms-1" data-accion="0" title="Pausar / Desactivar Producto">
                                                <i class="fas fa-ban"></i>
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-outline-success btn-estatus ms-1" data-accion="1" title="Activar Producto">
                                                <i class="fas fa-check-circle"></i>
                                            </button>
                                        <?php endif; ?>
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

    <div class="modal fade" id="modalAlertaInicial" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header bg-warning text-dark border-0">
                    <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i> Alertas de Inventario</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center p-4">
                    <h5 class="mb-3 fw-bold text-dark">¡Atención!</h5>
                    <p class="text-muted fs-5">Actualmente hay <strong><span id="txt-num-criticos" class="text-danger"></span> producto(s)</strong> con niveles críticos.</p>
                    <p class="text-muted">Se muestran resaltados en amarillo al inicio de tu catálogo.</p>
                </div>
                <div class="modal-footer justify-content-center border-0 pb-4">
                    <button type="button" class="btn btn-warning fw-bold px-5 rounded-pill" data-bs-dismiss="modal">Entendido</button>
                </div>
            </div>
        </div>
    </div>

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

    <div class="modal fade" id="modalAutorizarCat" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header bg-warning text-dark border-0">
                    <h5 class="modal-title"><i class="fas fa-lock me-2"></i> Autorización Requerida</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center p-4">
                    <h5 class="mb-4" id="txt-motivo-auth">Se requiere permiso de un Administrador para continuar.</h5>
                    <div class="form-floating mb-3">
                        <input type="text" class="form-control" id="adminUserCat" placeholder="Usuario (Ej. ADM-0001)">
                        <label for="adminUserCat">Usuario Administrador</label>
                    </div>
                    <div class="form-floating mb-3">
                        <input type="password" class="form-control" id="adminPassCat" placeholder="Contraseña">
                        <label for="adminPassCat">Contraseña de Administrador</label>
                    </div>
                </div>
                <div class="modal-footer justify-content-center border-0 pb-4">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-warning fw-bold" id="btnAutorizarCat">Autorizar</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const totalCriticos = <?= $totalCriticos ?? 0 ?>;
            if (totalCriticos > 0) {
                document.getElementById('txt-num-criticos').innerText = totalCriticos;
                const modalAlertaInicial = new bootstrap.Modal(document.getElementById('modalAlertaInicial'));
                modalAlertaInicial.show();
            }

            const rolUsuario = "<?= $rolUsuarioActual ?>";
            if (rolUsuario === 'Operador') {
                const elementosAdmin = document.querySelectorAll('.admin-only');
                elementosAdmin.forEach(el => el.style.display = 'none');
            }

            document.getElementById('btn-cerrar-sesion').addEventListener('click', (e) => {
                e.preventDefault();
                localStorage.clear();
                window.location.href = '../cnfg/logout.php';
            });

            const modalAgregar = new bootstrap.Modal(document.getElementById('modalAgregar'));
            const modalEditar = new bootstrap.Modal(document.getElementById('modalEditar'));
            const modalAutorizarCat = new bootstrap.Modal(document.getElementById('modalAutorizarCat'));

            const inputBuscador = document.getElementById('inputBuscador');
            const filtroAbc = document.getElementById('filtro-abc');
            const filtroEstatus = document.getElementById('filtro-estatus');
            const filasTabla = document.querySelectorAll('#listaCatalogos tr');

            function aplicarFiltros() {
                const texto = inputBuscador.value.toLowerCase();
                const abc = filtroAbc.value;
                const estatus = filtroEstatus.value;

                filasTabla.forEach(fila => {
                    if(!fila.hasAttribute('data-codigo')) return;

                    const codigo = fila.dataset.codigo.toLowerCase();
                    const nombre = fila.dataset.nombre.toLowerCase();
                    const desc = fila.dataset.desc.toLowerCase();
                    const filaAbc = fila.dataset.abc;
                    const filaEstatus = fila.dataset.estatus;

                    const cumpleTexto = codigo.includes(texto) || nombre.includes(texto) || desc.includes(texto);
                    const cumpleAbc = (abc === 'ALL') || (filaAbc === abc);
                    const cumpleEstatus = (estatus === 'ALL') || (filaEstatus === estatus);

                    fila.style.display = (cumpleTexto && cumpleAbc && cumpleEstatus) ? '' : 'none';
                });
            }

            inputBuscador.addEventListener('input', aplicarFiltros);
            filtroAbc.addEventListener('change', aplicarFiltros);
            filtroEstatus.addEventListener('change', aplicarFiltros);

            let tipoAccionPendiente = null; 
            let prodPendiente = null;
            let estatusPendiente = null;

            document.getElementById('btnAgregarPrincipal').addEventListener('click', () => {
                if (rolUsuario === 'Operador') {
                    tipoAccionPendiente = 'agregar';
                    document.getElementById('txt-motivo-auth').innerText = 'Se requiere permiso de Administrador para dar de alta un producto nuevo.';
                    document.getElementById('adminUserCat').value = '';
                    document.getElementById('adminPassCat').value = '';
                    modalAutorizarCat.show();
                } else {
                    modalAgregar.show();
                }
            });

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
                    alert(result.message);
                    window.location.reload();
                } else {
                    alert("Error: " + result.message);
                }
            });

            document.getElementById('listaCatalogos').addEventListener('click', async function(e) {
                const btnEditar = e.target.closest('.btn-editar');
                if (btnEditar) {
                    const filaActual = btnEditar.closest('tr');
                    document.getElementById('edit-codigo-original').value = filaActual.dataset.codigo;
                    document.getElementById('edit-codigo').value = filaActual.dataset.codigo;
                    document.getElementById('edit-nombre').value = filaActual.dataset.nombre;
                    document.getElementById('edit-descripcion').value = filaActual.dataset.desc;
                    document.getElementById('edit-abc').value = filaActual.dataset.abc;
                    document.getElementById('edit-min').value = filaActual.dataset.min;
                    document.getElementById('edit-max').value = filaActual.dataset.max;
                    
                    modalEditar.show();
                }

                const btnEstatus = e.target.closest('.btn-estatus');
                if (btnEstatus) {
                    const filaActual = btnEstatus.closest('tr');
                    const codigo = filaActual.dataset.codigo;
                    const nuevoEstatus = btnEstatus.dataset.accion;
                    
                    const accionTexto = nuevoEstatus == '1' ? 'REACTIVAR' : 'DAR DE BAJA';
                    if(!confirm(`¿Estás seguro de que deseas ${accionTexto} este producto?`)) return;

                    if (rolUsuario === 'Operador') {
                        tipoAccionPendiente = 'estatus';
                        prodPendiente = codigo;
                        estatusPendiente = nuevoEstatus;
                        document.getElementById('txt-motivo-auth').innerText = 'Se requiere permiso de Administrador para modificar el estatus de este producto.';
                        document.getElementById('adminUserCat').value = '';
                        document.getElementById('adminPassCat').value = '';
                        modalAutorizarCat.show();
                    } else {
                        ejecutarCambioEstatus(codigo, nuevoEstatus);
                    }
                }
            });

            document.getElementById('btnAutorizarCat').addEventListener('click', async () => {
                const user = document.getElementById('adminUserCat').value;
                const password = document.getElementById('adminPassCat').value;
                
                if(user === '' || password === '') {
                    alert('Debe ingresar el usuario y contraseña del administrador');
                    return;
                }
                
                try {
                    const response = await fetch('catalogo.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ accion: 'autorizar_admin', adminUser: user, password: password })
                    });
                    const result = await response.json();

                    if (result.success) {
                        modalAutorizarCat.hide();
                        
                        if (tipoAccionPendiente === 'estatus') {
                            ejecutarCambioEstatus(prodPendiente, estatusPendiente);
                        } else if (tipoAccionPendiente === 'agregar') {
                            modalAgregar.show();
                        }
                    } else {
                        alert(result.message);
                    }
                } catch (error) {
                    alert('Error al verificar credenciales.');
                }
            });

            async function ejecutarCambioEstatus(codigo, estatus) {
                const payload = { accion: 'cambiar_estatus', codigo: codigo, estatus: estatus };
                const response = await fetch('catalogo.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const result = await response.json();

                if(result.success) {
                    window.location.reload();
                } else {
                    alert("Error: " + result.message);
                }
            }

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
        });
    </script>
</body>
</html>