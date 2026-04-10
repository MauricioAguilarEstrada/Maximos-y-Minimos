<?php
session_start();
require_once '../cnfg/conexionBD.php';

// Verificación de sesión
if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../login/login.html");
    exit;
}

$idUsuarioActual = $_SESSION['usuario_id'];
$rolUsuarioActual = $_SESSION['usuario_rol'];
$folioUsuarioActual = $_SESSION['usuario_folio'];
$nombreUsuarioActual = $_SESSION['usuario_nombre'] ?? 'Usuario';

// Variables para estadísticas y actividad
$totalEntradas = 0;
$totalSalidas = 0;
$actividades = [];

try {
    $db = new ConexionBD();
    $conn = $db->getConnection();

    // 1. Obtener estadísticas totales de este usuario
    $queryStats = "
        SELECT T.MOTIVO, SUM(D.CANTIDAD) as TOTAL_ITEMS 
        FROM MOVIMIENTOS M 
        INNER JOIN DETALLESMOVIMIENTOS D ON M.IDMOVIMIENTO = D.IDMOVIMIENTO
        INNER JOIN TIPODEMOVIMIENTO T ON M.IDTIPODEMOVIMIENTO = T.IDTIPODEMOVIMIENTO
        WHERE M.IDUSUARIO = :idUsuario
        GROUP BY T.MOTIVO
    ";
    $stmtStats = $conn->prepare($queryStats);
    $stmtStats->execute([':idUsuario' => $idUsuarioActual]);
    
    while ($row = $stmtStats->fetch(PDO::FETCH_ASSOC)) {
        if ($row['MOTIVO'] === 'Entrada') $totalEntradas = $row['TOTAL_ITEMS'];
        if ($row['MOTIVO'] === 'Salida') $totalSalidas = $row['TOTAL_ITEMS'];
    }

    // 2. Obtener las últimas 15 actividades (Movimientos) del usuario
    $queryActividad = "
        SELECT TOP 15 
            T.MOTIVO, P.NOMBRE as PRODUCTO, D.CANTIDAD,
            FORMAT(M.FECHAHORA, 'dd/MM/yyyy HH:mm') AS FECHA_MOV
        FROM MOVIMIENTOS M
        INNER JOIN DETALLESMOVIMIENTOS D ON M.IDMOVIMIENTO = D.IDMOVIMIENTO
        INNER JOIN PRODUCTOS P ON D.IDPRODUCTO = P.IDPRODUCTO
        INNER JOIN TIPODEMOVIMIENTO T ON M.IDTIPODEMOVIMIENTO = T.IDTIPODEMOVIMIENTO
        WHERE M.IDUSUARIO = :idUsuario
        ORDER BY M.IDMOVIMIENTO DESC
    ";
    $stmtAct = $conn->prepare($queryActividad);
    $stmtAct->execute([':idUsuario' => $idUsuarioActual]);
    $actividades = $stmtAct->fetchAll(PDO::FETCH_ASSOC);

} catch(PDOException $e) {
    $error_bd = "No se pudieron cargar los datos del perfil: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi Perfil - Gestión de Stock</title>
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
            <h2 class="h3 mb-0 text-gray-800">Mi Perfil</h2>
        </div>

        <?php if(isset($error_bd)): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?= $error_bd ?></div>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-4 mb-4">
                <div class="card shadow-sm border-0 rounded-3 text-center overflow-hidden h-100">
                    <div class="bg-primary" style="height: 100px; background-color: var(--azul-rey) !important;"></div>
                    <div class="card-body position-relative pb-4">
                        <div class="mb-3" style="margin-top: -60px;">
                            <div class="d-inline-flex align-items-center justify-content-center bg-white rounded-circle shadow-sm" style="width: 100px; height: 100px; border: 4px solid white;">
                                <i class="fas fa-user text-secondary" style="font-size: 3rem;"></i>
                            </div>
                        </div>
                        <h4 class="fw-bold mb-1"><?= htmlspecialchars($nombreUsuarioActual) ?></h4>
                        <p class="text-muted mb-3 fw-bold">Folio: <span class="text-dark"><?= htmlspecialchars($folioUsuarioActual) ?></span></p>
                        
                        <span class="badge <?= $rolUsuarioActual === 'Administrador' ? 'bg-primary' : 'bg-secondary' ?> px-3 py-2 rounded-pill mb-4 shadow-sm" style="font-size: 0.9rem;">
                            <i class="fas <?= $rolUsuarioActual === 'Administrador' ? 'fa-user-shield' : 'fa-user' ?> me-2"></i><?= htmlspecialchars($rolUsuarioActual) ?>
                        </span>

                        <hr class="text-muted">

                        <div class="row text-center mt-4">
                            <div class="col-6 border-end">
                                <h5 class="fw-bold text-success mb-0"><?= number_format($totalEntradas) ?></h5>
                                <small class="text-muted">Entradas (Unid.)</small>
                            </div>
                            <div class="col-6">
                                <h5 class="fw-bold text-danger mb-0"><?= number_format($totalSalidas) ?></h5>
                                <small class="text-muted">Salidas (Unid.)</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-8 mb-4">
                <div class="card shadow-sm border-0 rounded-3 h-100">
                    <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-history me-2"></i> Mi Actividad Reciente
                        </h6>
                    </div>
                    <div class="card-body p-4">
                        <?php if(empty($actividades)): ?>
                            <div class="text-center text-muted py-5">
                                <i class="fas fa-box-open fa-3x mb-3 text-light"></i>
                                <p>Aún no has realizado ningún movimiento en el sistema.</p>
                            </div>
                        <?php else: ?>
                            <div class="list-group list-group-flush">
                                <?php foreach($actividades as $act): 
                                    $esEntrada = $act['MOTIVO'] === 'Entrada';
                                    $icono = $esEntrada ? 'fa-arrow-down text-success' : 'fa-arrow-up text-danger';
                                    $bgIcon = $esEntrada ? 'bg-success' : 'bg-danger';
                                    $textoAccion = $esEntrada ? 'Ingresaste' : 'Retiraste';
                                ?>
                                <div class="list-group-item px-0 py-3 d-flex align-items-center border-bottom">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="<?= $bgIcon ?> bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center" style="width: 45px; height: 45px;">
                                            <i class="fas <?= $icono ?>"></i>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="mb-0">
                                            <strong><?= $textoAccion ?> <?= $act['CANTIDAD'] ?> unidades</strong> de 
                                            <span class="text-primary fw-bold"><?= htmlspecialchars($act['PRODUCTO']) ?></span>
                                        </p>
                                        <small class="text-muted"><i class="far fa-clock me-1"></i><?= $act['FECHA_MOV'] ?? 'N/D' ?></small>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('btn-cerrar-sesion').addEventListener('click', (e) => {
            e.preventDefault();
            localStorage.clear();
            window.location.href = '../cnfg/logout.php';
        });
    </script>
</body>
</html>