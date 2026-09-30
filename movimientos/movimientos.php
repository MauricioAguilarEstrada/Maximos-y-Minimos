<?php
session_start();

if (!isset($_SESSION['usuario_bd'])) {
    header("Location: ../login/login.html");
    exit;
}

$idUsuarioActual =$_SESSION['usuario_bd']; // Ej: OPR-0002
$nombreUsuarioActual =$_SESSION['nombre_usuario'] ?? 'Usuario';
$rolUsuarioActual = (strpos($idUsuarioActual, 'ADM') === 0) ? 'Administrador' : 'Operador';

// Parámetros Neon
$host = 'ep-royal-fire-b4e0qwit-pooler.c-6.us-east-2.aws.neon.tech';
$dbname = 'neondb';$endpoint_id = 'ep-royal-fire-b4e0qwit';
$dsn = "pgsql:host=$host;port=5432;dbname=$dbname;sslmode=require;options='endpoint=$endpoint_id'";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $data = json_decode(file_get_contents("php://input"), true);
    $accion =$data['accion'] ?? '';

    try {
        // Conexión principal con el usuario activo
        $conn = new PDO($dsn, $_SESSION['usuario_bd'],$_SESSION['password_bd'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);

        // 1. BUSCAR PRODUCTO
        if ($accion === 'buscar_producto') {
            $stmt =$conn->prepare("SELECT idproducto, nombre, stockactual, stockminimo, stockmaximo FROM public.productos WHERE codigodebarras = :codigo AND estatus = true");
            $stmt->execute([':codigo' =>$data['codigo']]);
            $producto =$stmt->fetch();
            
            if ($producto) {
                echo json_encode(['success' => true, 'producto' => $producto]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Producto no encontrado o inactivo.']);
            }
            exit;
        }

        // 2. AUTORIZAR CON CREDENCIALES DE ADMINISTRADOR DIRECTO EN POSTGRES
        if ($accion === 'autorizar_admin') {
            $adminUser =$data['adminUser'] ?? '';
            $adminPass =$data['password'] ?? '';
            
            if(strpos($adminUser, 'ADM') !== 0) {
                echo json_encode(['success' => false, 'message' => 'El usuario proporcionado no tiene prefijo de Administrador (ADM-XXXX).']);
                exit;
            }

            try {
                // Intentamos conectar a Postgres con las credenciales dadas en el modal
                $adminConn = new PDO($dsn, $adminUser,$adminPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                echo json_encode(['success' => true]);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'message' => 'Credenciales de administrador incorrectas.']);
            }
            exit;
        }

        // 3. REGISTRAR EL MOVIMIENTO (Llama al Procedure y el Trigger actualiza el stock)
        if ($accion === 'registrar_movimiento') {
            $idProducto =$data['idProducto'];
            $cantidad = (int)$data['cantidad'];
            $tipo =$data['tipo']; // 'entrada' o 'salida'

            $motivoStr = $tipo === 'entrada' ? 'Entrada' : 'Salida';
$stmtTipo = $conn->prepare("SELECT idtipodemovimiento FROM public.tipodemovimiento WHERE motivo = :motivo");
$stmtTipo->execute([':motivo' => $motivoStr]);
$idTipoMovimiento = $stmtTipo->fetchColumn();

// Si no existe, lo insertamos al vuelo simulando tu lógica original
if (!$idTipoMovimiento) {
    $stmtInsertTipo = $conn->prepare("INSERT INTO public.tipodemovimiento (motivo) VALUES (:motivo) RETURNING idtipodemovimiento");
    $stmtInsertTipo->execute([':motivo' => $motivoStr]);
    $idTipoMovimiento = $stmtInsertTipo->fetchColumn();
}

            // Construir JSON para el detalle
            $json_detalles = json_encode([
                ['idproducto' => $idProducto, 'cantidad' =>$cantidad]
            ]);

            // Invocar el procedimiento almacenado
            $stmtMov =$conn->prepare("CALL public.registrar_movimiento_completo(:usuario, :tipo, :notas, :detalles)");
            $stmtMov->execute([
                ':usuario'  => $idUsuarioActual,
                ':tipo'     => $idTipoMovimiento,
                ':notas'    => "Registro desde módulo de escáner",
                ':detalles' => $json_detalles
            ]);
            
            echo json_encode(['success' => true, 'message' => 'Movimiento registrado con éxito.']);
            exit;
        }

    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error de BD: ' . $e->getMessage()]);
        exit;
    }
}

// =======================================================================
// CARGAR HISTORIAL DE ÚLTIMOS MOVIMIENTOS (GET)
// =======================================================================
$ultimosMovimientos = [];
try {
    $connGET = new PDO($dsn, $_SESSION['usuario_bd'],$_SESSION['password_bd'], [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    // Consulta adaptada a la sintaxis de PostgreSQL
    $queryHistorial = "
        SELECT 
            T.motivo AS \"MOTIVO\", P.nombre AS \"PRODUCTO\", D.cantidad AS \"CANTIDAD\", U.acceso AS \"USUARIO\",
            TO_CHAR(M.fechahora, 'DD/MM/YYYY HH24:MI') AS \"FECHA_MOV\"
        FROM public.movimientos M
        INNER JOIN public.detallesmovimientos D ON M.idmovimiento = D.idmovimiento
        INNER JOIN public.productos P ON D.idproducto = P.idproducto
        INNER JOIN public.tipodemovimiento T ON M.idtipodemovimiento = T.idtipodemovimiento
        INNER JOIN public.usuarios U ON M.idusuario = U.idusuario
        ORDER BY M.idmovimiento DESC
        LIMIT 10
    ";
    $stmtHist = $connGET->query($queryHistorial);
    $ultimosMovimientos =$stmtHist->fetchAll();
} catch(PDOException $e) {}
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
                                        <th class="text-end pe-4">Fecha/Hora</th> 
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(empty($ultimosMovimientos)): ?>
                                        <tr><td colspan="4" class="text-center py-4 text-muted">No hay movimientos recientes.</td></tr>
                                    <?php else: ?>
                                        <?php foreach($ultimosMovimientos as$mov): 
                                            $badge =$mov['MOTIVO'] === 'Entrada' ? 'bg-success' : 'bg-danger';
                                            $signo =$mov['MOTIVO'] === 'Entrada' ? '+' : '-';
                                        ?>
                                        <tr>
                                            <td class="px-4"><span class="badge <?= $badge ?>"><?= $mov['MOTIVO'] ?></span></td>
                                            <td><?= htmlspecialchars($mov['PRODUCTO']) ?></td>
                                            <td class="text-center fw-bold text-muted"><?= $signo .$mov['CANTIDAD'] ?></td>
                                            <td class="text-center text-muted"><?= htmlspecialchars($mov['USUARIO']) ?></td>
                                            <td class="text-end pe-4 text-muted"><small><?= $mov['FECHA_MOV'] ?? 'N/D' ?></small></td>
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
                    <p class="text-muted">Se requiere credencial de Administrador para forzar el movimiento en la base de datos.</p>
                    
                    <div class="form-floating mb-3">
                        <input type="text" class="form-control" id="adminUser" placeholder="Usuario (Ej. ADM-0001)">
                        <label for="adminUser">Usuario Administrador</label>
                    </div>
                    <div class="form-floating mb-3">
                        <input type="password" class="form-control" id="adminPassword" placeholder="Contraseña">
                        <label for="adminPassword">Contraseña</label>
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
            document.getElementById('btn-cerrar-sesion').addEventListener('click', (e) => {
                e.preventDefault();
                localStorage.clear();
                window.location.href = '../cnfg/logout.php';
            });

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

            document.body.addEventListener('click', (e) => {
                if (!e.target.closest('.modal') && e.target !== inputCantidad && e.target !== inputEscaner) {
                    if(inputCantidad.disabled){ inputEscaner.focus(); }
                }
            });

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
                            
                            lblNombreProducto.innerText = `Producto: ${productoSeleccionado.nombre}`;
                            lblStockActual.innerText = productoSeleccionado.stockactual;
                            lblStockMin.innerText = productoSeleccionado.stockminimo;
                            lblStockMax.innerText = productoSeleccionado.stockmaximo;
                            
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

            formMovimiento.addEventListener('submit', (e) => {
                e.preventDefault(); 
                if(!productoSeleccionado) return;

                const tipo = document.querySelector('input[name="tipoMovimiento"]:checked').value;
                const cantidad = parseInt(inputCantidad.value);
                
                if(isNaN(cantidad) || cantidad <= 0) {
                    document.getElementById('textoAlerta').innerText = 'Ingresa una cantidad válida mayor a 0.';
                    modalAlerta.show();
                    return;
                }

                let stockActual = parseInt(productoSeleccionado.stockactual) || 0;
                let max = parseInt(productoSeleccionado.stockmaximo) || 0;
                let min = parseInt(productoSeleccionado.stockminimo) || 0;
                let stockResultante = tipo === 'entrada' ? stockActual + cantidad : stockActual - cantidad;

                if (tipo === 'salida' && stockResultante < 0) {
                    document.getElementById('textoAlerta').innerText = `Stock insuficiente. Solo tienes ${stockActual} unidades disponibles en físico.`;
                    modalAlerta.show();
                    return; 
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

                procesarMovimiento(tipo, cantidad);
            });

            document.getElementById('btnAutorizar').addEventListener('click', async () => {
                const user = document.getElementById('adminUser').value;
                const password = document.getElementById('adminPassword').value;
                
                if(user === '' || password === '') {
                    alert('Debe ingresar el usuario y contraseña del administrador');
                    return;
                }
                
                try {
                    const response = await fetch('movimientos.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ accion: 'autorizar_admin', adminUser: user, password: password })
                    });
                    const result = await response.json();

                    if (result.success) {
                        const tipo = document.querySelector('input[name="tipoMovimiento"]:checked').value;
                        const cantidad = parseInt(inputCantidad.value);
                        
                        modalAlertaStock.hide();
                        document.getElementById('adminUser').value = '';
                        document.getElementById('adminPassword').value = '';
                        procesarMovimiento(tipo, cantidad);
                    } else {
                        alert(result.message);
                    }
                } catch (error) {
                    alert('Error al verificar credenciales.');
                }
            });

            async function procesarMovimiento(tipo, cantidad) {
                btnConfirmar.disabled = true;

                const payload = {
                    accion: 'registrar_movimiento',
                    idProducto: productoSeleccionado.idproducto,
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
                        window.location.reload(); 
                    } else {
                        alert('Error de base de datos: ' + result.message);
                        btnConfirmar.disabled = false;
                    }
                } catch (error) {
                    console.error("Error al guardar:", error);
                    alert("Se perdió la conexión con el servidor.");
                    btnConfirmar.disabled = false;
                }
            }

            document.getElementById('modalAlerta').addEventListener('hidden.bs.modal', () => inputEscaner.focus());
        });
    </script>
</body>
</html>