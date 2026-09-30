<?php
session_start();

// 1. PROTECCIÓN DE RUTA Y ROL (Solo Administradores)
if (!isset($_SESSION['usuario_bd'])) {
    header("Location: ../login/login.html");
    exit;
}

$idUsuarioActual = $_SESSION['usuario_bd']; 
$rolUsuarioActual = (strpos($idUsuarioActual, 'ADM') === 0) ? 'Administrador' : 'Operador';

if ($rolUsuarioActual !== 'Administrador') {
    header("Location: ../panelAdmin/panelAdmin.php");
    exit;
}

// Parámetros Neon
$host = 'ep-royal-fire-b4e0qwit-pooler.c-6.us-east-2.aws.neon.tech';
$dbname = 'neondb';
$endpoint_id = 'ep-royal-fire-b4e0qwit';
$dsn = "pgsql:host=$host;port=5432;dbname=$dbname;sslmode=require;options='endpoint=$endpoint_id'";

// =======================================================================
// 2. API PARA GENERAR REPORTES (Llamadas AJAX / GET)
// =======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['accion']) && $_GET['accion'] === 'generar_reporte') {
    header('Content-Type: application/json');
    
    $filtroUsuario = $_GET['usuario'] ?? 'ALL';
    $tipoReporte = $_GET['tipo'] ?? 'movimientos'; 
    
    try {
        $conn = new PDO($dsn, $_SESSION['usuario_bd'], $_SESSION['password_bd'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        
        $datosReporte = [
            'kpis' => ['entradas' => 0, 'salidas' => 0],
            'grafica_usuarios' => [],
            'top_productos' => [],
            'detalle' => [],
            'tipo' => $tipoReporte
        ];

        $whereUsuario = $filtroUsuario !== 'ALL' ? " AND m.idusuario = :idUsuario " : "";

        // =========================================================
        // A) LÓGICA EXCLUSIVA PARA EL REPORTE DE MOVIMIENTOS
        // =========================================================
        if ($tipoReporte === 'movimientos') {
            
            // 1. Obtener KPIs Generales
            $queryKPI = '
                SELECT t.motivo AS "MOTIVO", SUM(d.cantidad) AS "TOTAL"
                FROM public.detallesmovimientos d
                INNER JOIN public.movimientos m ON d.idmovimiento = m.idmovimiento
                INNER JOIN public.tipodemovimiento t ON m.idtipodemovimiento = t.idtipodemovimiento
                WHERE 1=1 ' . $whereUsuario . '
                GROUP BY t.motivo
            ';
            $stmtKPI = $conn->prepare($queryKPI);
            if($filtroUsuario !== 'ALL') $stmtKPI->bindParam(':idUsuario', $filtroUsuario);
            $stmtKPI->execute();
            while($row = $stmtKPI->fetch()) {
                if ($row['MOTIVO'] === 'Entrada') $datosReporte['kpis']['entradas'] = $row['TOTAL'];
                if ($row['MOTIVO'] === 'Salida') $datosReporte['kpis']['salidas'] = $row['TOTAL'];
            }

            // 2. Gráfica 1: Entradas y Salidas por Usuario (Usando CTE de Productividad)
            $queryUsu = '
                WITH productividades AS (
                    SELECT m.idusuario, t.motivo, SUM(d.cantidad) as total_operaciones
                    FROM public.detallesmovimientos d
                    INNER JOIN public.movimientos m ON d.idmovimiento = m.idmovimiento
                    INNER JOIN public.tipodemovimiento t ON m.idtipodemovimiento = t.idtipodemovimiento
                    WHERE 1=1 ' . $whereUsuario . '
                    GROUP BY m.idusuario, t.motivo
                )
                SELECT u.nombre AS "NOMBRE", p.motivo AS "MOTIVO", p.total_operaciones AS "TOTAL"
                FROM productividades p
                INNER JOIN public.usuarios u ON p.idusuario = u.idusuario
                ORDER BY u.nombre
            ';
            $stmtUsu = $conn->prepare($queryUsu);
            if($filtroUsuario !== 'ALL') $stmtUsu->bindParam(':idUsuario', $filtroUsuario);
            $stmtUsu->execute();
            $datosReporte['grafica_usuarios'] = $stmtUsu->fetchAll();

            // 3. Gráfica 2: Top 5 Productos con más salidas (Usando CTE de Rotación)
            $queryTop = '
                WITH rotacion_productos AS (
                    SELECT d.idproducto, SUM(d.cantidad) AS total_salidas
                    FROM public.detallesmovimientos d
                    INNER JOIN public.movimientos m ON d.idmovimiento = m.idmovimiento
                    INNER JOIN public.tipodemovimiento t ON m.idtipodemovimiento = t.idtipodemovimiento
                    WHERE t.motivo = \'Salida\' ' . $whereUsuario . '
                    GROUP BY d.idproducto
                )
                SELECT p.nombre AS "NOMBRE", rp.total_salidas AS "TOTAL_SALIDAS"
                FROM rotacion_productos rp
                INNER JOIN public.productos p ON rp.idproducto = p.idproducto
                ORDER BY rp.total_salidas DESC
                LIMIT 5
            ';
            $stmtTop = $conn->prepare($queryTop);
            if($filtroUsuario !== 'ALL') $stmtTop->bindParam(':idUsuario', $filtroUsuario);
            $stmtTop->execute();
            $datosReporte['top_productos'] = $stmtTop->fetchAll();

            // 4. Detalle para la Tabla
            $queryDetalle = '
                SELECT 
                    t.motivo AS "MOTIVO", p.nombre AS "PRODUCTO", d.cantidad AS "CANTIDAD", u.nombre AS "USUARIO",
                    TO_CHAR(m.fechahora, \'DD/MM/YYYY HH24:MI\') AS "FECHA_MOV"
                FROM public.movimientos m
                INNER JOIN public.detallesmovimientos d ON m.idmovimiento = d.idmovimiento
                INNER JOIN public.productos p ON d.idproducto = p.idproducto
                INNER JOIN public.tipodemovimiento t ON m.idtipodemovimiento = t.idtipodemovimiento
                INNER JOIN public.usuarios u ON m.idusuario = u.idusuario
                WHERE 1=1 ' . $whereUsuario . '
                ORDER BY m.idmovimiento DESC
                LIMIT 50
            ';
            $stmtDetalle = $conn->prepare($queryDetalle);
            if($filtroUsuario !== 'ALL') $stmtDetalle->bindParam(':idUsuario', $filtroUsuario);
            $stmtDetalle->execute();
            $datosReporte['detalle'] = $stmtDetalle->fetchAll();
        } 
        
        // =========================================================
        // B) LÓGICA EXCLUSIVA PARA EL REPORTE DE EXISTENCIAS
        // =========================================================
        else if ($tipoReporte === 'existencias') {
            $queryDetalle = 'SELECT codigodebarras AS "CODIGODEBARRAS", nombre AS "NOMBRE", categoria AS "CATEGORIA", stockactual AS "STOCKACTUAL", stockminimo AS "STOCKMINIMO", stockmaximo AS "STOCKMAXIMO" FROM public.productos WHERE estatus = true ORDER BY nombre ASC';
            $stmtDetalle = $conn->query($queryDetalle);
            $datosReporte['detalle'] = $stmtDetalle->fetchAll();
        }
        
        // =========================================================
        // C) LÓGICA EXCLUSIVA PARA EL REPORTE DE INACTIVOS
        // =========================================================
        else if ($tipoReporte === 'inactivos') {
            
            // 1. Detalle para la Tabla
            $queryDetalle = 'SELECT codigodebarras AS "CODIGODEBARRAS", nombre AS "NOMBRE", categoria AS "CATEGORIA", stockactual AS "STOCKACTUAL", TO_CHAR(fechamodificacion, \'DD/MM/YYYY\') AS "FECHA_BAJA" FROM public.productos WHERE estatus = false ORDER BY fechamodificacion DESC';
            $stmtDetalle = $conn->query($queryDetalle);
            $datosReporte['detalle'] = $stmtDetalle->fetchAll();

            // 2. Gráfica 1: Histórico de Movimientos (SOLO INACTIVOS)
            $queryUsu = '
                SELECT u.nombre AS "NOMBRE", t.motivo AS "MOTIVO", SUM(d.cantidad) AS "TOTAL"
                FROM public.detallesmovimientos d
                INNER JOIN public.movimientos m ON d.idmovimiento = m.idmovimiento
                INNER JOIN public.tipodemovimiento t ON m.idtipodemovimiento = t.idtipodemovimiento
                INNER JOIN public.usuarios u ON m.idusuario = u.idusuario
                INNER JOIN public.productos p ON d.idproducto = p.idproducto
                WHERE p.estatus = false
                GROUP BY u.nombre, t.motivo
                ORDER BY u.nombre
            ';
            $stmtUsu = $conn->query($queryUsu);
            $datosReporte['grafica_usuarios'] = $stmtUsu->fetchAll();

            // 3. Gráfica 2: Top 5 Productos Inactivos con más salidas
            $queryTop = '
                SELECT p.nombre AS "NOMBRE", SUM(d.cantidad) AS "TOTAL_SALIDAS"
                FROM public.detallesmovimientos d
                INNER JOIN public.movimientos m ON d.idmovimiento = m.idmovimiento
                INNER JOIN public.productos p ON d.idproducto = p.idproducto
                INNER JOIN public.tipodemovimiento t ON m.idtipodemovimiento = t.idtipodemovimiento
                WHERE t.motivo = \'Salida\' AND p.estatus = false
                GROUP BY p.nombre
                ORDER BY "TOTAL_SALIDAS" DESC
                LIMIT 5
            ';
            $stmtTop = $conn->query($queryTop);
            $datosReporte['top_productos'] = $stmtTop->fetchAll();
        }

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
    $connGET = new PDO($dsn, $_SESSION['usuario_bd'], $_SESSION['password_bd'], [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $stmt = $connGET->query('SELECT idusuario AS "IDUSUARIO", nombre AS "NOMBRE" FROM public.usuarios WHERE acceso NOT LIKE \'DEL-%\' ORDER BY nombre ASC');
    $listaUsuariosFiltro = $stmt->fetchAll();
} catch(PDOException $e) {}
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
    
    <style>
        .tabla-scroll-interno {
            max-height: 400px;
            overflow-y: auto;
        }
        .tabla-scroll-interno thead th {
            position: sticky;
            top: 0;
            background-color: #f8f9fa;
            z-index: 1;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
        }
    </style>
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
            <h2 class="h3 mb-0 text-gray-800">Reportes del Sistema</h2>
            
            <div class="d-flex gap-2">
                <select id="filtro-tipo" class="form-select border-primary text-primary shadow-sm fw-bold">
                    <option value="movimientos">1. Reporte de Movimientos</option>
                    <option value="existencias">2. Reporte de Existencias</option>
                    <option value="inactivos">3. Productos Inactivos</option>
                </select>

                <select id="filtro-usuario" class="form-select border-secondary text-secondary shadow-sm">
                    <option value="ALL">Todos los Usuarios</option>
                    <?php foreach($listaUsuariosFiltro as$usr): ?>
                        <option value="<?= $usr['IDUSUARIO'] ?>"><?= htmlspecialchars($usr['NOMBRE']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-primary" id="btn-actualizar">
                    <i class="fas fa-sync-alt"></i> Actualizar
                </button>
            </div>
        </div>

        <div class="row mb-4" id="seccion-kpis">
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

        <div class="row mb-4" id="seccion-graficas">
            <div class="col-md-8">
                <div class="card shadow-sm border-0 rounded-3 h-100">
                    <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
                        <h6 class="m-0 font-weight-bold text-primary" id="titulo-grafica-1">
                            <i class="fas fa-chart-bar me-2"></i> Movimientos por Usuario
                        </h6>
                    </div>
                    <div class="card-body" id="contenedor-grafica-usu">
                        <canvas id="graficaUsuarios" height="100"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card shadow-sm border-0 rounded-3 h-100">
                    <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
                        <h6 class="m-0 font-weight-bold text-primary" id="titulo-grafica-2">
                            <i class="fas fa-fire me-2"></i> Top 5 Productos (Salidas)
                        </h6>
                    </div>
                    <div class="card-body" id="contenedor-grafica-prod">
                        <canvas id="graficaProductos"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm border-0 rounded-3 mb-4">
            <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
                <h6 class="m-0 font-weight-bold text-primary" id="titulo-tabla-reporte">
                    <i class="fas fa-list me-2"></i> Cargando reporte...
                </h6>
            </div>
            <div class="card-body p-0 mt-3">
                <div class="table-responsive tabla-scroll-interno">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light" id="headReporte">
                        </thead>
                        <tbody id="tablaReporte">
                            <tr><td colspan="5" class="text-center py-4 text-muted">Cargando reporte...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            
            document.getElementById('btn-cerrar-sesion').addEventListener('click', (e) => {
                e.preventDefault();
                localStorage.clear();
                window.location.href = '../cnfg/logout.php';
            });

            let chartUsuarios = null;
            let chartProductos = null;

            document.getElementById('filtro-tipo').addEventListener('change', function() {
                const tipo = this.value;
                const fUsu = document.getElementById('filtro-usuario');
                const secKPIs = document.getElementById('seccion-kpis');
                const secGraficas = document.getElementById('seccion-graficas');
                const tituloG1 = document.getElementById('titulo-grafica-1');
                const tituloG2 = document.getElementById('titulo-grafica-2');

                if (tipo === 'movimientos') {
                    fUsu.disabled = false;
                    secKPIs.classList.remove('d-none');
                    secGraficas.classList.remove('d-none');
                    tituloG1.innerHTML = '<i class="fas fa-chart-bar me-2"></i> Movimientos por Usuario';
                    tituloG2.innerHTML = '<i class="fas fa-fire me-2"></i> Top 5 Productos (Salidas)';
                } else if (tipo === 'existencias') {
                    fUsu.value = 'ALL';
                    fUsu.disabled = true;
                    secKPIs.classList.add('d-none');
                    secGraficas.classList.add('d-none');
                } else if (tipo === 'inactivos') {
                    fUsu.value = 'ALL';
                    fUsu.disabled = true;
                    secKPIs.classList.add('d-none');
                    secGraficas.classList.remove('d-none');
                    tituloG1.innerHTML = '<i class="fas fa-chart-bar me-2"></i> Movimientos (De Prod. Inactivos)';
                    tituloG2.innerHTML = '<i class="fas fa-fire me-2"></i> Top 5 Inactivos (Con más salidas)';
                }
                
                cargarReporte(); 
            });

            async function cargarReporte() {
                const usuarioSelect = document.getElementById('filtro-usuario').value;
                const tipoSelect = document.getElementById('filtro-tipo').value;
                
                try {
                    const response = await fetch(`reportes.php?accion=generar_reporte&usuario=${usuarioSelect}&tipo=${tipoSelect}`);
                    const result = await response.json();

                    if(result.success) {
                        const data = result.data;
                        
                        if (data.tipo === 'movimientos') {
                            document.getElementById('kpi-entradas').innerText = data.kpis.entradas || 0;
                            document.getElementById('kpi-salidas').innerText = data.kpis.salidas || 0;
                            renderGraficaUsuarios(data.grafica_usuarios || [], 'General');
                            renderGraficaProductos(data.top_productos || []);
                        } 
                        else if (data.tipo === 'inactivos') {
                            renderGraficaUsuarios(data.grafica_usuarios || [], 'Inactivos');
                            renderGraficaProductos(data.top_productos || []);
                        }

                        renderTabla(data.detalle, data.tipo);
                        
                    } else {
                        alert("Error al cargar reporte: " + result.message);
                    }
                } catch (error) {
                    console.error("Error de red:", error);
                }
            }

            function renderGraficaUsuarios(datosRaw, contexto) {
                const contenedor = document.getElementById('contenedor-grafica-usu');
                
                if(chartUsuarios) chartUsuarios.destroy(); 
                
                if(!datosRaw || datosRaw.length === 0) {
                    contenedor.innerHTML = '<div class="text-center text-muted py-5"><i class="fas fa-chart-bar fa-3x mb-3 opacity-50"></i><p class="mb-0">No hay datos suficientes para graficar.</p></div>';
                    return;
                }

                contenedor.innerHTML = '<canvas id="graficaUsuarios" height="100"></canvas>';
                const ctx = document.getElementById('graficaUsuarios').getContext('2d');
                
                const usuariosMap = {};
                datosRaw.forEach(row => {
                    if(!usuariosMap[row.NOMBRE]) usuariosMap[row.NOMBRE] = { Entradas: 0, Salidas: 0 };
                    if(row.MOTIVO === 'Entrada') usuariosMap[row.NOMBRE].Entradas = row.TOTAL;
                    if(row.MOTIVO === 'Salida') usuariosMap[row.NOMBRE].Salidas = row.TOTAL;
                });

                const labels = Object.keys(usuariosMap);
                const dataEntradas = labels.map(l => usuariosMap[l].Entradas);
                const dataSalidas = labels.map(l => usuariosMap[l].Salidas);

                const labelE = contexto === 'Inactivos' ? 'Entradas (Prod. Inactivos)' : 'Entradas';
                const labelS = contexto === 'Inactivos' ? 'Salidas (Prod. Inactivos)' : 'Salidas';

                chartUsuarios = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [
                            { label: labelE, data: dataEntradas, backgroundColor: 'rgba(25, 135, 84, 0.7)' },
                            { label: labelS, data: dataSalidas, backgroundColor: 'rgba(220, 53, 69, 0.7)' }
                        ]
                    },
                    options: { responsive: true, scales: { y: { beginAtZero: true } } }
                });
            }

            function renderGraficaProductos(datosRaw) {
                const contenedor = document.getElementById('contenedor-grafica-prod');
                
                if(chartProductos) chartProductos.destroy();
                
                if(!datosRaw || datosRaw.length === 0) {
                    contenedor.innerHTML = '<div class="text-center text-muted py-5"><i class="fas fa-chart-pie fa-3x mb-3 opacity-50"></i><p class="mb-0">No hay datos suficientes para graficar.</p></div>';
                    return;
                }

                contenedor.innerHTML = '<canvas id="graficaProductos"></canvas>';
                const ctx = document.getElementById('graficaProductos').getContext('2d');
                
                const labels = datosRaw.map(r => r.NOMBRE);
                const data = datosRaw.map(r => r.TOTAL_SALIDAS);

                chartProductos = new Chart(ctx, {
                    type: 'doughnut',
                    data: {
                        labels: labels,
                        datasets: [{
                            data: data,
                            backgroundColor: ['#e74c3c', '#3498db', '#f39c12', '#34495e', '#2ecc71']
                        }]
                    },
                    options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
                });
            }

            function renderTabla(datosRaw, tipo) {
                const thead = document.getElementById('headReporte');
                const tbody = document.getElementById('tablaReporte');
                const titulo = document.getElementById('titulo-tabla-reporte');
                
                thead.innerHTML = ''; 
                tbody.innerHTML = '';
                
                if(!datosRaw || datosRaw.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-muted">No hay datos para este reporte.</td></tr>';
                    return;
                }

                if (tipo === 'movimientos') {
                    titulo.innerHTML = '<i class="fas fa-exchange-alt me-2"></i> Histórico de Movimientos';
                    thead.innerHTML = `<tr><th class="px-4">Tipo</th><th>Producto</th><th class="text-center">Cantidad</th><th>Usuario Responsable</th><th class="text-end pe-4">Fecha/Hora</th></tr>`;
                    datosRaw.forEach(row => {
                        const badge = row.MOTIVO === 'Entrada' ? 'bg-success' : 'bg-danger';
                        const signo = row.MOTIVO === 'Entrada' ? '+' : '-';
                        tbody.innerHTML += `<tr><td class="px-4"><span class="badge ${badge}">${row.MOTIVO}</span></td><td>${row.PRODUCTO}</td><td class="text-center fw-bold text-muted">${signo}${row.CANTIDAD}</td><td><i class="fas fa-user-circle text-secondary me-2"></i>${row.USUARIO}</td><td class="text-end pe-4 text-muted"><small><i class="far fa-clock me-1"></i>${row.FECHA_MOV || 'N/D'}</small></td></tr>`;
                    });
                } 
                else if (tipo === 'existencias') {
                    titulo.innerHTML = '<i class="fas fa-boxes me-2"></i> Reporte de Existencias de Catálogo';
                    thead.innerHTML = `<tr><th class="px-4">Código</th><th>Producto</th><th class="text-center">Clasificación ABC</th><th class="text-center">Stock Físico</th><th class="text-end pe-4">Límites (Mín / Máx)</th></tr>`;
                    datosRaw.forEach(row => {
                        const cat = row.CATEGORIA.trim();
                        const badge = cat === 'A' ? 'bg-danger' : (cat === 'B' ? 'bg-warning text-dark' : 'bg-success');
                        tbody.innerHTML += `<tr><td class="px-4 fw-bold text-muted">${row.CODIGODEBARRAS}</td><td>${row.NOMBRE}</td><td class="text-center"><span class="badge ${badge}">Clase ${cat}</span></td><td class="text-center fw-bold fs-5 text-dark">${row.STOCKACTUAL}</td><td class="text-end pe-4 text-muted"><small>Min: <span class="fw-bold">${row.STOCKMINIMO}</span> / Max: <span class="fw-bold">${row.STOCKMAXIMO}</span></small></td></tr>`;
                    });
                }
                else if (tipo === 'inactivos') {
                    titulo.innerHTML = '<i class="fas fa-ban me-2"></i> Reporte de Productos Inactivos (Baja)';
                    thead.innerHTML = `<tr><th class="px-4">Código</th><th>Producto</th><th class="text-center">Clasificación ABC</th><th class="text-center">Stock Residual</th><th class="text-end pe-4">Fecha de Baja</th></tr>`;
                    datosRaw.forEach(row => {
                        const cat = row.CATEGORIA.trim();
                        const badge = cat === 'A' ? 'bg-danger' : (cat === 'B' ? 'bg-warning text-dark' : 'bg-success');
                        tbody.innerHTML += `<tr><td class="px-4 fw-bold text-muted">${row.CODIGODEBARRAS}</td><td>${row.NOMBRE}</td><td class="text-center"><span class="badge ${badge}">Clase ${cat}</span></td><td class="text-center fw-bold text-danger">${row.STOCKACTUAL}</td><td class="text-end pe-4 text-muted"><small><i class="fas fa-calendar-times me-1"></i>${row.FECHA_BAJA || 'N/D'}</small></td></tr>`;
                    });
                }
            }

            document.getElementById('btn-actualizar').addEventListener('click', cargarReporte);
            cargarReporte();
        });
    </script>
</body>
</html>