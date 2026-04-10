<?php
session_start();
require_once '../cnfg/conexionBD.php';

if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../login/login.html");
    exit;
}

$idUsuarioActual = $_SESSION['usuario_id'];
$rolUsuarioActual = $_SESSION['usuario_rol'];
$folioUsuarioActual = $_SESSION['usuario_folio'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $data = json_decode(file_get_contents("php://input"), true);
    $accion = $data['accion'] ?? '';

    try {
        $db = new ConexionBD();
        $conn = $db->getConnection();

        // 1. BUSCAR PRODUCTO POR CÓDIGO DE BARRAS
        if ($accion === 'buscar_producto') {
            $stmt = $conn->prepare("SELECT IDPRODUCTO, NOMBRE, STOCKACTUAL, STOCKMINIMO, STOCKMAXIMO FROM PRODUCTOS WHERE CODIGODEBARRAS = :codigo AND ESTATUS = 1");
            $stmt->execute([':codigo' => $data['codigo']]);
            
            // LA SOLUCIÓN: Extraer directamente en lugar de contar
            $producto = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($producto) {
                echo json_encode(['success' => true, 'producto' => $producto]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Producto no encontrado o inactivo.']);
            }
            exit;
        }

        // 2. AUTORIZAR CON CONTRASEÑA DE ADMINISTRADOR
        if ($accion === 'autorizar_admin') {
            $stmt = $conn->prepare("SELECT IDUSUARIO FROM USUARIOS WHERE ROL = 'Administrador' AND PASSWRD = :pass AND ESTATUS = 1");
            $stmt->execute([':pass' => $data['password']]);
            
            // LA SOLUCIÓN: Extraer directamente en lugar de contar
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($admin) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Contraseña incorrecta o el usuario no es Administrador.']);
            }
            exit;
        }

        // 3. REGISTRAR EL MOVIMIENTO Y ACTUALIZAR STOCK
        if ($accion === 'registrar_movimiento') {
            $idProducto = $data['idProducto'];
            $cantidad = (int)$data['cantidad'];
            $tipo = $data['tipo']; // 'entrada' o 'salida'

            // --- NUEVO CANDADO DE BACKEND: EVITAR NEGATIVOS ---
            if ($tipo === 'salida') {
                $stmtCheck = $conn->prepare("SELECT STOCKACTUAL FROM PRODUCTOS WHERE IDPRODUCTO = :id");
                $stmtCheck->execute([':id' => $idProducto]);
                $stockEnBaseDeDatos = $stmtCheck->fetchColumn();
                
                if ($cantidad > $stockEnBaseDeDatos) {
                    echo json_encode(['success' => false, 'message' => "Operación cancelada: Intento de retirar más unidades de las que existen en físico."]);
                    exit;
                }
            }
            
            // --- CORRECCIÓN 1: INICIAMOS TRANSACCIÓN DESDE AQUÍ ARRIBA ---
            $conn->beginTransaction();

            // Verificamos o creamos el tipo de movimiento en la tabla TIPODEMOVIMIENTO
            $motivoStr = $tipo === 'entrada' ? 'Entrada' : 'Salida';
            $stmtTipo = $conn->prepare("SELECT IDTIPODEMOVIMIENTO FROM TIPODEMOVIMIENTO WHERE MOTIVO = :motivo");
            $stmtTipo->execute([':motivo' => $motivoStr]);
            
            $tipoMov = $stmtTipo->fetch(PDO::FETCH_ASSOC);
            
            if ($tipoMov) {
                $idTipoMovimiento = $tipoMov['IDTIPODEMOVIMIENTO'];
            } else {
                // Si no existe, lo insertamos
                $stmtInsertTipo = $conn->prepare("INSERT INTO TIPODEMOVIMIENTO (MOTIVO) VALUES (:motivo)");
                $stmtInsertTipo->execute([':motivo' => $motivoStr]);
                $idTipoMovimiento = $conn->lastInsertId();
            }

            // A) Insertar en MOVIMIENTOS
            $stmtMov = $conn->prepare("INSERT INTO MOVIMIENTOS (IDUSUARIO, IDTIPODEMOVIMIENTO, NOTAS) VALUES (:idUsuario, :idTipo, :notas)");
            $stmtMov->execute([
                ':idUsuario' => $idUsuarioActual,
                ':idTipo' => $idTipoMovimiento,
                ':notas' => "Registro desde módulo de escáner"
            ]);
            $idMovimiento = $conn->lastInsertId();

            // B) Insertar en DETALLESMOVIMIENTOS
            $stmtDet = $conn->prepare("INSERT INTO DETALLESMOVIMIENTOS (CANTIDAD, IDPRODUCTO, IDMOVIMIENTO) VALUES (:cantidad, :idProducto, :idMovimiento)");
            $stmtDet->execute([
                ':cantidad' => $cantidad,
                ':idProducto' => $idProducto,
                ':idMovimiento' => $idMovimiento
            ]);

            // C) Actualizar STOCKACTUAL en PRODUCTOS
            $operador = $tipo === 'entrada' ? '+' : '-';
            $stmtUpd = $conn->prepare("UPDATE PRODUCTOS SET STOCKACTUAL = STOCKACTUAL {$operador} :cantidad, FECHAMODIFICACION = GETDATE() WHERE IDPRODUCTO = :idProducto");
            $stmtUpd->execute([
                ':cantidad' => $cantidad,
                ':idProducto' => $idProducto
            ]);

            $conn->commit(); 
            
            echo json_encode(['success' => true, 'message' => 'Movimiento registrado con éxito.']);
            exit;
        }

    } catch(PDOException $e) {
        // --- CORRECCIÓN 2: VERIFICAR SI HAY TRANSACCIÓN ANTES DE DESHACER ---
        if(isset($conn) && $conn->inTransaction()) { 
            $conn->rollBack(); 
        }
        // Ahora sí, enviamos el error REAL de SQL Server al navegador
        echo json_encode(['success' => false, 'message' => 'Error de BD: ' . $e->getMessage()]);
        exit;
    }
}

// =======================================================================
// CARGAR HISTORIAL DE ÚLTIMOS MOVIMIENTOS (GET)
// =======================================================================
$ultimosMovimientos = [];
try {
    $db = new ConexionBD();
    $conn = $db->getConnection();
    // Consulta con JOIN para traer información completa
    $queryHistorial = "
        SELECT TOP 10 
            T.MOTIVO, P.NOMBRE as PRODUCTO, D.CANTIDAD, U.ACCESO as USUARIO
        FROM MOVIMIENTOS M
        INNER JOIN DETALLESMOVIMIENTOS D ON M.IDMOVIMIENTO = D.IDMOVIMIENTO
        INNER JOIN PRODUCTOS P ON D.IDPRODUCTO = P.IDPRODUCTO
        INNER JOIN TIPODEMOVIMIENTO T ON M.IDTIPODEMOVIMIENTO = T.IDTIPODEMOVIMIENTO
        INNER JOIN USUARIOS U ON M.IDUSUARIO = U.IDUSUARIO
        ORDER BY M.IDMOVIMIENTO DESC
    ";
    $stmtHist = $conn->query($queryHistorial);
    $ultimosMovimientos = $stmtHist->fetchAll();
} catch(PDOException $e) {
    // Manejo de error silencioso para la vista
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Inventario - Movimientos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../Assets/style.css">
</head>
<body>
    <nav id="sidebar" class="d-flex flex-column shadow-lg">
        <div class="brand-logo py-4 text-center mb-3">
            <i class="fas fa-boxes fa-2x mb-2"></i>
            <h5 class="mb-0 fw-bold">Gestión de Stock</h5>
            <small class="text-white-50"><?= htmlspecialchars($rolUsuarioActual) ?> (<?= htmlspecialchars($folioUsuarioActual) ?>)</small>
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
            
            <li class="nav-item <?= (isset($_SESSION['usuario_rol']) && $_SESSION['usuario_rol'] === 'Operador') ? 'd-none' : '' ?>">
                <a href="../reportes/reportes.php" class="nav-link"><i class="fas fa-chart-line me-3"></i> Reportes</a>
            </li>
            <li class="nav-item <?= (isset($_SESSION['usuario_rol']) && $_SESSION['usuario_rol'] === 'Operador') ? 'd-none' : '' ?>">
                <a href="../usuarios/usuarios.php" class="nav-link"><i class="fas fa-user-cog me-3"></i> Usuarios</a>
            </li>
        </ul>
        
        <div class="mt-auto mb-4 px-3">
            <a href="#" id="btn-cerrar-sesion" class="btn btn-outline-light w-100 text-start">
                <i class="fas fa-sign-out-alt me-2"></i> Cerrar sesión
            </a>
        </div>
    </nav>

    <main id="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="h3 mb-0 text-gray-800">Registro de Movimientos</h2>
        </div>

        <div class="row">
            <div class="col-md-5">
                <div class="card shadow-sm border-0 mb-4 rounded-3">
                    <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-barcode me-2"></i> Captura de Producto
                        </h6>
                    </div>
                    <div class="card-body p-4">
                        <form id="formMovimiento">
                            <div class="mb-4">
                                <label class="form-label fw-bold">Tipo de Movimiento</label>
                                <div class="d-flex gap-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="tipoMovimiento" id="movEntrada" value="entrada" checked>
                                        <label class="form-check-label text-success fw-bold" for="movEntrada">
                                            <i class="fas fa-arrow-down me-1"></i> Entrada
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="tipoMovimiento" id="movSalida" value="salida">
                                        <label class="form-check-label text-danger fw-bold" for="movSalida">
                                            <i class="fas fa-arrow-up me-1"></i> Salida
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="inputEscaner" class="form-label fw-bold">Código de Barras</label>
                                <input type="text" class="form-control form-control-lg bg-light" id="inputEscaner" 
                                       placeholder="Escanea o teclea el código..." autofocus autocomplete="off">
                            </div>

                            <div id="infoProducto" class="alert alert-info d-none mb-3">
                                <strong id="lblNombreProducto">Producto: -</strong><br>
                                <small>Stock Actual: <span id="lblStockActual" class="fw-bold">-</span></small> | 
                                <small>Min: <span id="lblStockMin">-</span></small> | 
                                <small>Max: <span id="lblStockMax">-</span></small>
                            </div>

                            <div class="mb-4">
                                <label for="inputCantidad" class="form-label fw-bold">Cantidad</label>
                                <input type="number" class="form-control form-control-lg" id="inputCantidad" 
                                       placeholder="0" min="1" disabled>
                            </div>

                            <button type="submit" class="btn btn-primary w-100 btn-lg" id="btnConfirmar" disabled>
                                Confirmar Movimiento
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-7">
                <div class="card shadow-sm border-0 rounded-3">
                    <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-list me-2"></i> Últimos Movimientos
                        </h6>
                    </div>
                    <div class="card-body p-0 mt-3">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th class="px-4">Tipo</th>
                                        <th>Producto</th>
                                        <th class="text-center">Cant.</th>
                                        <th class="text-center">Usuario</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(empty($ultimosMovimientos)): ?>
                                        <tr><td colspan="4" class="text-center py-4 text-muted">No hay movimientos recientes.</td></tr>
                                    <?php else: ?>
                                        <?php foreach($ultimosMovimientos as $mov): 
                                            $badge = $mov['MOTIVO'] === 'Entrada' ? 'bg-success' : 'bg-danger';
                                            $signo = $mov['MOTIVO'] === 'Entrada' ? '+' : '-';
                                        ?>
                                        <tr>
                                            <td class="px-4"><span class="badge <?= $badge ?>"><?= $mov['MOTIVO'] ?></span></td>
                                            <td><?= htmlspecialchars($mov['PRODUCTO']) ?></td>
                                            <td class="text-center fw-bold text-muted"><?= $signo . $mov['CANTIDAD'] ?></td>
                                            <td class="text-center text-muted"><?= htmlspecialchars($mov['USUARIO']) ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <div class="modal fade" id="modalAlertaStock" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header bg-warning text-dark border-0">
                    <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i> Advertencia de Stock</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center p-4">
                    <h5 id="textoAlertaStock" class="mb-4">El movimiento supera los límites permitidos.</h5>
                    <p class="text-muted">Se requiere contraseña de Administrador para forzar el movimiento.</p>
                    
                    <div class="form-floating mb-3">
                        <input type="password" class="form-control" id="adminPassword" placeholder="Contraseña">
                        <label for="adminPassword">Contraseña de Administrador</label>
                    </div>
                </div>
                <div class="modal-footer justify-content-center border-0 pb-4">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-warning fw-bold" id="btnAutorizar">Autorizar y Guardar</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalAlerta" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header bg-danger text-white border-0">
                    <h5 class="modal-title"><i class="fas fa-times-circle me-2"></i> Error</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center p-4">
                    <h5 id="textoAlerta" class="fw-bold text-dark mb-0">El producto escaneado no existe.</h5>
                </div>
                <div class="modal-footer justify-content-center border-0 pb-4">
                    <button type="button" class="btn btn-danger px-5 rounded-pill" data-bs-dismiss="modal">Aceptar</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // --- CIERRE DE SESIÓN ---
            document.getElementById('btn-cerrar-sesion').addEventListener('click', (e) => {
                e.preventDefault();
                localStorage.clear();
                // Ruta correcta hacia tu archivo en la carpeta cnfg
                window.location.href = '../cnfg/logout.php';
            });

            // --- VARIABLES GLOBALES ---
            const inputEscaner = document.getElementById('inputEscaner');
            const inputCantidad = document.getElementById('inputCantidad');
            const btnConfirmar = document.getElementById('btnConfirmar');
            const formMovimiento = document.getElementById('formMovimiento');
            
            const infoProducto = document.getElementById('infoProducto');
            const lblNombreProducto = document.getElementById('lblNombreProducto');
            const lblStockActual = document.getElementById('lblStockActual');
            const lblStockMin = document.getElementById('lblStockMin');
            const lblStockMax = document.getElementById('lblStockMax');

            const modalAlerta = new bootstrap.Modal(document.getElementById('modalAlerta'));
            const modalAlertaStock = new bootstrap.Modal(document.getElementById('modalAlertaStock'));
            
            let productoSeleccionado = null;

            // Mantener el foco en el escáner
            document.body.addEventListener('click', (e) => {
                if (!e.target.closest('.modal') && e.target !== inputCantidad && e.target !== inputEscaner) {
                    if(inputCantidad.disabled){ inputEscaner.focus(); }
                }
            });

            // 1. ESCUCHAR ESCÁNER (Buscar Producto en SQL Server)
            inputEscaner.addEventListener('keypress', async function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    const codigo = this.value.trim();
                    
                    if (codigo !== '') {
                        const response = await fetch('movimientos.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ accion: 'buscar_producto', codigo: codigo })
                        });
                        const result = await response.json();

                        if (result.success) {
                            productoSeleccionado = result.producto;
                            
                            lblNombreProducto.innerText = `Producto: ${productoSeleccionado.NOMBRE}`;
                            lblStockActual.innerText = productoSeleccionado.STOCKACTUAL;
                            lblStockMin.innerText = productoSeleccionado.STOCKMINIMO;
                            lblStockMax.innerText = productoSeleccionado.STOCKMAXIMO;
                            
                            infoProducto.classList.remove('d-none');
                            inputCantidad.disabled = false;
                            btnConfirmar.disabled = false;
                            inputCantidad.focus();
                        } else {
                            document.getElementById('textoAlerta').innerText = result.message;
                            modalAlerta.show();
                            this.value = '';
                        }
                    }
                }
            });

            // 2. VALIDAR CANTIDADES Y LÍMITES (Ahora escuchamos el 'submit' del form)
            formMovimiento.addEventListener('submit', (e) => {
                e.preventDefault(); // Evita la recarga de la página al dar Enter o Clic
                if(!productoSeleccionado) return;

                const tipo = document.querySelector('input[name="tipoMovimiento"]:checked').value;
                const cantidad = parseInt(inputCantidad.value);
                
                if(isNaN(cantidad) || cantidad <= 0) {
                    document.getElementById('textoAlerta').innerText = 'Ingresa una cantidad válida mayor a 0.';
                    modalAlerta.show();
                    return;
                }

                // Cálculo de límites (Con protección por si la base de datos devuelve null)
                let stockActual = parseInt(productoSeleccionado.STOCKACTUAL) || 0;
                let max = parseInt(productoSeleccionado.STOCKMAXIMO) || 0;
                let min = parseInt(productoSeleccionado.STOCKMINIMO) || 0;
                let stockResultante = tipo === 'entrada' ? stockActual + cantidad : stockActual - cantidad;

                // --- NUEVO CANDADO: EVITAR STOCK NEGATIVO ---
                if (tipo === 'salida' && stockResultante < 0) {
                    document.getElementById('textoAlerta').innerText = `Stock insuficiente. Solo tienes ${stockActual} unidades disponibles en físico.`;
                    modalAlerta.show();
                    return; // Detenemos el proceso aquí mismo
                }

                if (tipo === 'entrada' && stockResultante > max) {
                    document.getElementById('textoAlertaStock').innerText = `La entrada supera el stock máximo permitido (${max}).`;
                    modalAlertaStock.show();
                    return;
                }

                if (tipo === 'salida' && stockResultante < min) {
                    document.getElementById('textoAlertaStock').innerText = `La salida dejaría el stock por debajo del mínimo permitido (${min}).`;
                    modalAlertaStock.show();
                    return;
                }

                // Si todo está dentro de los límites, guardamos de inmediato
                procesarMovimiento(tipo, cantidad);
            });

            // 3. AUTORIZACIÓN DE ADMINISTRADOR (Desde el Modal)
            document.getElementById('btnAutorizar').addEventListener('click', async () => {
                const password = document.getElementById('adminPassword').value;
                if(password === '') {
                    alert('Debe ingresar la contraseña de administrador');
                    return;
                }
                
                try {
                    const response = await fetch('movimientos.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ accion: 'autorizar_admin', password: password })
                    });
                    const result = await response.json();

                    if (result.success) {
                        const tipo = document.querySelector('input[name="tipoMovimiento"]:checked').value;
                        const cantidad = parseInt(inputCantidad.value);
                        
                        modalAlertaStock.hide();
                        document.getElementById('adminPassword').value = '';
                        procesarMovimiento(tipo, cantidad);
                    } else {
                        alert(result.message);
                    }
                } catch (error) {
                    alert('Error al verificar contraseña. Revisa la consola.');
                }
            });

            // 4. GUARDAR MOVIMIENTO EN LA BASE DE DATOS
            async function procesarMovimiento(tipo, cantidad) {
                // Deshabilitamos el botón temporalmente para evitar doble envío rápido
                btnConfirmar.disabled = true;

                const payload = {
                    accion: 'registrar_movimiento',
                    idProducto: productoSeleccionado.IDPRODUCTO,
                    tipo: tipo,
                    cantidad: cantidad
                };

                try {
                    const response = await fetch('movimientos.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload)
                    });
                    
                    const result = await response.json();

                    if (result.success) {
                        alert(result.message);
                        window.location.reload(); // Recarga la página para mostrar la tabla actualizada
                    } else {
                        alert('Error de base de datos: ' + result.message);
                        btnConfirmar.disabled = false; // Rehabilitamos si hubo error
                    }
                } catch (error) {
                    console.error("Error al guardar:", error);
                    alert("Se perdió la conexión con el servidor o la sesión expiró. Por favor recarga la página.");
                    btnConfirmar.disabled = false;
                }
            }

            // Devolver foco al escáner al cerrar modales
            document.getElementById('modalAlerta').addEventListener('hidden.bs.modal', () => inputEscaner.focus());
        });
    </script>
</body>
</html>