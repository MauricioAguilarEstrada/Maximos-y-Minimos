<?php
session_start();
require_once '../cnfg/conexionBD.php';

// 1. PROTECCIÓN DE RUTA Y ROL (Solo Administradores)
if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_rol'] !== 'Administrador') {
    header("Location: ../panelAdmin/panelAdmin.php");
    exit;
}

// =======================================================================
// 2. API PARA GENERAR REPORTES (Llamadas AJAX / GET)
// =======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['accion']) && $_GET['accion'] === 'generar_reporte') {
    header('Content-Type: application/json');
    
    // Recibimos filtros (Si el usuario seleccionó un filtro específico)
    $filtroUsuario = $_GET['usuario'] ?? 'ALL';
    
    try {
        $db = new ConexionBD();
        $conn = $db->getConnection();
        
        $datosReporte = [
            'kpis' => ['entradas' => 0, 'salidas' => 0],
            'grafica_usuarios' => [],
            'top_productos' => [],
            'detalle' => []
        ];

        // Condición de usuario para las consultas
        $whereUsuario = $filtroUsuario !== 'ALL' ? " AND M.IDUSUARIO = :idUsuario " : "";

        // --- A) Obtener KPIs (Total Entradas y Salidas en Cantidad) ---
        $queryKPI = "
            SELECT T.MOTIVO, SUM(D.CANTIDAD) as TOTAL
            FROM DETALLESMOVIMIENTOS D
            INNER JOIN MOVIMIENTOS M ON D.IDMOVIMIENTO = M.IDMOVIMIENTO
            INNER JOIN TIPODEMOVIMIENTO T ON M.IDTIPODEMOVIMIENTO = T.IDTIPODEMOVIMIENTO
            WHERE 1=1 $whereUsuario
            GROUP BY T.MOTIVO
        ";
        $stmtKPI = $conn->prepare($queryKPI);
        if($filtroUsuario !== 'ALL') $stmtKPI->bindParam(':idUsuario', $filtroUsuario);
        $stmtKPI->execute();
        
        while($row = $stmtKPI->fetch()) {
            if ($row['MOTIVO'] === 'Entrada') $datosReporte['kpis']['entradas'] = $row['TOTAL'];
            if ($row['MOTIVO'] === 'Salida') $datosReporte['kpis']['salidas'] = $row['TOTAL'];
        }

        // --- B) Entradas y Salidas por Usuario (Para Gráfica) ---
        $queryUsu = "
            SELECT U.NOMBRE, T.MOTIVO, SUM(D.CANTIDAD) as TOTAL
            FROM DETALLESMOVIMIENTOS D
            INNER JOIN MOVIMIENTOS M ON D.IDMOVIMIENTO = M.IDMOVIMIENTO
            INNER JOIN TIPODEMOVIMIENTO T ON M.IDTIPODEMOVIMIENTO = T.IDTIPODEMOVIMIENTO
            INNER JOIN USUARIOS U ON M.IDUSUARIO = U.IDUSUARIO
            WHERE 1=1 $whereUsuario
            GROUP BY U.NOMBRE, T.MOTIVO
            ORDER BY U.NOMBRE
        ";
        $stmtUsu = $conn->prepare($queryUsu);
        if($filtroUsuario !== 'ALL') $stmtUsu->bindParam(':idUsuario', $filtroUsuario);
        $stmtUsu->execute();
        $datosReporte['grafica_usuarios'] = $stmtUsu->fetchAll();

        // --- C) Top 5 Productos con más salidas (Consideración Extra) ---
        $queryTop = "
            SELECT TOP 5 P.NOMBRE, SUM(D.CANTIDAD) as TOTAL_SALIDAS
            FROM DETALLESMOVIMIENTOS D
            INNER JOIN MOVIMIENTOS M ON D.IDMOVIMIENTO = M.IDMOVIMIENTO
            INNER JOIN PRODUCTOS P ON D.IDPRODUCTO = P.IDPRODUCTO
            INNER JOIN TIPODEMOVIMIENTO T ON M.IDTIPODEMOVIMIENTO = T.IDTIPODEMOVIMIENTO
            WHERE T.MOTIVO = 'Salida' $whereUsuario
            GROUP BY P.NOMBRE
            ORDER BY TOTAL_SALIDAS DESC
        ";
        $stmtTop = $conn->prepare($queryTop);
        if($filtroUsuario !== 'ALL') $stmtTop->bindParam(':idUsuario', $filtroUsuario);
        $stmtTop->execute();
        $datosReporte['top_productos'] = $stmtTop->fetchAll();

        // --- D) Detalle en Tabla (Últimos 50 movimientos del filtro) ---
        $queryDetalle = "
            SELECT TOP 50 
                T.MOTIVO, P.NOMBRE as PRODUCTO, D.CANTIDAD, U.NOMBRE as USUARIO
            FROM MOVIMIENTOS M
            INNER JOIN DETALLESMOVIMIENTOS D ON M.IDMOVIMIENTO = D.IDMOVIMIENTO
            INNER JOIN PRODUCTOS P ON D.IDPRODUCTO = P.IDPRODUCTO
            INNER JOIN TIPODEMOVIMIENTO T ON M.IDTIPODEMOVIMIENTO = T.IDTIPODEMOVIMIENTO
            INNER JOIN USUARIOS U ON M.IDUSUARIO = U.IDUSUARIO
            WHERE 1=1 $whereUsuario
            ORDER BY M.IDMOVIMIENTO DESC
        ";
        $stmtDetalle = $conn->prepare($queryDetalle);
        if($filtroUsuario !== 'ALL') $stmtDetalle->bindParam(':idUsuario', $filtroUsuario);
        $stmtDetalle->execute();
        $datosReporte['detalle'] = $stmtDetalle->fetchAll();

        echo json_encode(['success' => true, 'data' => $datosReporte]);
        exit;

    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error de BD: ' . $e->getMessage()]);
        exit;
    }
}

// =======================================================================
// 3. CARGAR LISTA DE USUARIOS PARA EL SELECTOR (GET)
// =======================================================================
$listaUsuariosFiltro = [];
try {
    $db = new ConexionBD();
    $conn = $db->getConnection();
    $stmt = $conn->query("SELECT IDUSUARIO, NOMBRE FROM USUARIOS ORDER BY NOMBRE ASC");
    $listaUsuariosFiltro = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    // Silencioso
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Inventario - Reportes</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="../Assets/style.css">
</head>
<body>
    <nav id="sidebar" class="d-flex flex-column shadow-lg">
        <div class="brand-logo py-4 text-center mb-3">
            <i class="fas fa-boxes fa-2x mb-2"></i>
            <h5 class="mb-0 fw-bold">Gestión de Stock</h5>
            <small class="text-white-50">
                <?= isset($_SESSION['usuario_rol']) ? htmlspecialchars($_SESSION['usuario_rol']) : 'Usuario' ?> 
                (<?= isset($_SESSION['usuario_folio']) ? htmlspecialchars($_SESSION['usuario_folio']) : 'Sin Folio' ?>)
            </small>
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
            <h2 class="h3 mb-0 text-gray-800">Reportes de Movimientos</h2>
            
            <div class="d-flex gap-2">
                <select id="filtro-usuario" class="form-select border-secondary text-secondary shadow-sm">
                    <option value="ALL">Todos los Usuarios</option>
                    <?php foreach($listaUsuariosFiltro as $usr): ?>
                        <option value="<?= $usr['IDUSUARIO'] ?>"><?= htmlspecialchars($usr['NOMBRE']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-primary" id="btn-actualizar">
                    <i class="fas fa-sync-alt"></i> Actualizar
                </button>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card shadow-sm border-0 rounded-3 h-100">
                    <div class="card-body d-flex align-items-center p-4">
                        <div class="flex-shrink-0 me-4">
                            <div class="bg-success bg-opacity-10 p-3 rounded-circle text-success">
                                <i class="fas fa-arrow-down fa-2x"></i>
                            </div>
                        </div>
                        <div>
                            <h6 class="text-muted mb-1 text-uppercase fw-bold">Unidades Entrantes (Histórico)</h6>
                            <h2 class="mb-0 fw-bold text-success" id="kpi-entradas">0</h2>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card shadow-sm border-0 rounded-3 h-100">
                    <div class="card-body d-flex align-items-center p-4">
                        <div class="flex-shrink-0 me-4">
                            <div class="bg-danger bg-opacity-10 p-3 rounded-circle text-danger">
                                <i class="fas fa-arrow-up fa-2x"></i>
                            </div>
                        </div>
                        <div>
                            <h6 class="text-muted mb-1 text-uppercase fw-bold">Unidades Salientes (Histórico)</h6>
                            <h2 class="mb-0 fw-bold text-danger" id="kpi-salidas">0</h2>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-8">
                <div class="card shadow-sm border-0 rounded-3 h-100">
                    <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-chart-bar me-2"></i> Movimientos por Usuario
                        </h6>
                    </div>
                    <div class="card-body">
                        <canvas id="graficaUsuarios" height="100"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card shadow-sm border-0 rounded-3 h-100">
                    <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-fire me-2"></i> Top 5 Productos (Salidas)
                        </h6>
                    </div>
                    <div class="card-body">
                        <canvas id="graficaProductos"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm border-0 rounded-3">
            <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
                <h6 class="m-0 font-weight-bold text-primary">
                    <i class="fas fa-list me-2"></i> Desglose de Movimientos (Últimos 50)
                </h6>
            </div>
            <div class="card-body p-0 mt-3">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="px-4">Tipo</th>
                                <th>Producto</th>
                                <th class="text-center">Cantidad</th>
                                <th>Usuario Responsable</th>
                            </tr>
                        </thead>
                        <tbody id="tablaReporte">
                            <tr><td colspan="4" class="text-center py-4 text-muted">Cargando reporte...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            
            // Cierre de Sesión
            document.getElementById('btn-cerrar-sesion').addEventListener('click', (e) => {
                e.preventDefault();
                localStorage.clear();
                window.location.href = '../cnfg/logout.php';
            });

            let chartUsuarios = null;
            let chartProductos = null;

            async function cargarReporte() {
                const usuarioSelect = document.getElementById('filtro-usuario').value;
                
                try {
                    const response = await fetch(`reportes.php?accion=generar_reporte&usuario=${usuarioSelect}`);
                    const result = await response.json();

                    if(result.success) {
                        const data = result.data;
                        
                        // 1. Actualizar KPIs
                        document.getElementById('kpi-entradas').innerText = data.kpis.entradas || 0;
                        document.getElementById('kpi-salidas').innerText = data.kpis.salidas || 0;

                        // 2. Renderizar Gráfica de Usuarios
                        renderGraficaUsuarios(data.grafica_usuarios);

                        // 3. Renderizar Gráfica Top Productos
                        renderGraficaProductos(data.top_productos);

                        // 4. Renderizar Tabla
                        renderTabla(data.detalle);
                        
                    } else {
                        alert("Error al cargar reporte: " + result.message);
                    }
                } catch (error) {
                    console.error("Error de red:", error);
                }
            }

            function renderGraficaUsuarios(datosRaw) {
                const ctx = document.getElementById('graficaUsuarios').getContext('2d');
                
                // Procesar datos para Chart.js
                const usuariosMap = {};
                datosRaw.forEach(row => {
                    if(!usuariosMap[row.NOMBRE]) usuariosMap[row.NOMBRE] = { Entradas: 0, Salidas: 0 };
                    if(row.MOTIVO === 'Entrada') usuariosMap[row.NOMBRE].Entradas = row.TOTAL;
                    if(row.MOTIVO === 'Salida') usuariosMap[row.NOMBRE].Salidas = row.TOTAL;
                });

                const labels = Object.keys(usuariosMap);
                const dataEntradas = labels.map(l => usuariosMap[l].Entradas);
                const dataSalidas = labels.map(l => usuariosMap[l].Salidas);

                if(chartUsuarios) chartUsuarios.destroy(); // Limpiar anterior

                chartUsuarios = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [
                            { label: 'Entradas', data: dataEntradas, backgroundColor: 'rgba(25, 135, 84, 0.7)' },
                            { label: 'Salidas', data: dataSalidas, backgroundColor: 'rgba(220, 53, 69, 0.7)' }
                        ]
                    },
                    options: { responsive: true, scales: { y: { beginAtZero: true } } }
                });
            }

            function renderGraficaProductos(datosRaw) {
                const ctx = document.getElementById('graficaProductos').getContext('2d');
                
                const labels = datosRaw.map(r => r.NOMBRE);
                const data = datosRaw.map(r => r.TOTAL_SALIDAS);

                if(chartProductos) chartProductos.destroy();

                chartProductos = new Chart(ctx, {
                    type: 'doughnut',
                    data: {
                        labels: labels,
                        datasets: [{
                            data: data,
                            backgroundColor: ['#003366', '#3498db', '#e67e22', '#e74c3c', '#95a5a6']
                        }]
                    },
                    options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
                });
            }

            function renderTabla(datosRaw) {
                const tbody = document.getElementById('tablaReporte');
                tbody.innerHTML = '';
                
                if(datosRaw.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="4" class="text-center py-4 text-muted">No hay movimientos para este filtro.</td></tr>';
                    return;
                }

                datosRaw.forEach(row => {
                    const badge = row.MOTIVO === 'Entrada' ? 'bg-success' : 'bg-danger';
                    const signo = row.MOTIVO === 'Entrada' ? '+' : '-';
                    
                    const tr = `
                        <tr>
                            <td class="px-4"><span class="badge ${badge}">${row.MOTIVO}</span></td>
                            <td>${row.PRODUCTO}</td>
                            <td class="text-center fw-bold text-muted">${signo}${row.CANTIDAD}</td>
                            <td><i class="fas fa-user-circle text-secondary me-2"></i>${row.USUARIO}</td>
                        </tr>
                    `;
                    tbody.innerHTML += tr;
                });
            }

            // Iniciar y enlazar el botón actualizar
            document.getElementById('btn-actualizar').addEventListener('click', cargarReporte);
            cargarReporte(); // Cargar la primera vez al entrar
        });
    </script>
</body>
</html>