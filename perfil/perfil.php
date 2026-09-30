<?php
session_start();

// 1. Verificación de sesión segura (Arquitectura PostgreSQL)
if (!isset($_SESSION['usuario_bd'])) {
    header("Location: ../login/login.html");
    exit;
}

$idUsuarioActual = $_SESSION['usuario_bd']; // El folio (Ej. OPR-0001)
$rolUsuarioActual = (strpos($idUsuarioActual, 'ADM') === 0) ? 'Administrador' : 'Operador';
$folioUsuarioActual = $idUsuarioActual;
$nombreUsuarioActual = $_SESSION['nombre_usuario'] ?? 'Usuario';

// Parámetros Neon
$host = 'ep-royal-fire-b4e0qwit-pooler.c-6.us-east-2.aws.neon.tech';
$dbname = 'neondb';
$endpoint_id = 'ep-royal-fire-b4e0qwit-pooler';
$dsn = "pgsql:host=$host;port=5432;dbname=$dbname;sslmode=require;options='endpoint=$endpoint_id'";

// Variables para estadísticas y actividad
$totalEntradas = 0;
$totalSalidas = 0;
$actividades = [];

try {
    $conn = new PDO($dsn, $_SESSION['usuario_bd'], $_SESSION['password_bd'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // 1. CTE: Agrupar estadísticas totales del usuario en memoria
    $queryStats = '
        WITH totales AS (
            SELECT m.idusuario, t.motivo, SUM(d.cantidad) as total_items 
            FROM public.movimientos m 
            INNER JOIN public.detallesmovimientos d ON m.idmovimiento = d.idmovimiento
            INNER JOIN public.tipodemovimiento t ON m.idtipodemovimiento = t.idtipodemovimiento
            WHERE m.idusuario = (SELECT idusuario FROM public.usuarios WHERE acceso = :acceso)
            GROUP BY m.idusuario, t.motivo
        )
        SELECT motivo AS "MOTIVO", total_items AS "TOTAL_ITEMS" FROM totales
    ';
    $stmtStats = $conn->prepare($queryStats);
    $stmtStats->execute([':acceso' => $idUsuarioActual]);
    
    while ($row = $stmtStats->fetch()) {
        if ($row['MOTIVO'] === 'Entrada') $totalEntradas = $row['TOTAL_ITEMS'];
        if ($row['MOTIVO'] === 'Salida') $totalSalidas = $row['TOTAL_ITEMS'];
    }

    // 2. CTE: Aislar las últimas 15 actividades del usuario (Usando LIMIT y TO_CHAR)
    $queryActividad = '
        WITH historial_reciente AS (
            SELECT 
                t.motivo, p.nombre as producto, d.cantidad,
                TO_CHAR(m.fechahora, \'DD/MM/YYYY HH24:MI\') as fecha_mov,
                m.idmovimiento
            FROM public.movimientos m
            INNER JOIN public.detallesmovimientos d ON m.idmovimiento = d.idmovimiento
            INNER JOIN public.productos p ON d.idproducto = p.idproducto
            INNER JOIN public.tipodemovimiento t ON m.idtipodemovimiento = t.idtipodemovimiento
            WHERE m.idusuario = (SELECT idusuario FROM public.usuarios WHERE acceso = :acceso)
        )
        SELECT motivo AS "MOTIVO", producto AS "PRODUCTO", cantidad AS "CANTIDAD", fecha_mov AS "FECHA_MOV"
        FROM historial_reciente
        ORDER BY idmovimiento DESC
        LIMIT 15
    ';
    $stmtAct = $conn->prepare($queryActividad);
    $stmtAct->execute([':acceso' => $idUsuarioActual]);
    $actividades = $stmtAct->fetchAll();

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
                                            <strong><?= $textoAccion ?> <?= htmlspecialchars($act['CANTIDAD']) ?> unidades</strong> de 
                                            <span class="text-primary fw-bold"><?= htmlspecialchars($act['PRODUCTO']) ?></span>
                                        </p>
                                        <small class="text-muted"><i class="far fa-clock me-1"></i><?= htmlspecialchars($act['FECHA_MOV']) ?? 'N/D' ?></small>
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