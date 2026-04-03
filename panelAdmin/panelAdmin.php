<?php
// =======================================================================
// 1. LÓGICA DE BACKEND (PHP + PDO) - API DE CONSULTA DE STOCK
// =======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['accion']) && $_GET['accion'] === 'obtener_stock') {
    header('Content-Type: application/json');
    
     // CONEXIÓN REAL A SQL SERVER (Descomentar en producción)
    try {
        $conn = new PDO("sqlsrv:server=localhost\\SQLEXPRESS;Database=MAX_MIN", "usuario", "password");
        $stmt = $conn->query("SELECT CODIGO, NOMBRE, CATEGORIA, STOCK_ACTUAL, CMINIMA, CMAXIMA FROM PRODUCTO WHERE ESTATUS = 'Activo'");
        $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $productos]);
        exit;
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        exit;
    }
    

    // DATOS SIMULADOS PARA PRUEBAS (Mock Data)
    $productos = [
        ['codigo' => '7501020304050', 'nombre' => 'Cable de Cobre Calibre 12', 'categoria' => 'A', 'stock_actual' => 150, 'cminima' => 20, 'cmaxima' => 200],
        ['codigo' => '7509876543210', 'nombre' => 'Cinta de Aislar Negra', 'categoria' => 'C', 'stock_actual' => 15, 'cminima' => 15, 'cmaxima' => 100],
        ['codigo' => '7501122334455', 'nombre' => 'Interruptor Termomagnético 30A', 'categoria' => 'A', 'stock_actual' => 2, 'cminima' => 10, 'cmaxima' => 50],
        ['codigo' => '7509988776655', 'nombre' => 'Caja de Herramientas Básica', 'categoria' => 'B', 'stock_actual' => 45, 'cminima' => 5, 'cmaxima' => 30] 
    ];

    echo json_encode(['success' => true, 'data' => $productos]);
    exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Inventario - Inicio</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../Assets/style.css">
    
    <script>
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
            <small id="user-info-display" class="text-white-50"></small>
        </div>
        
        <ul class="nav flex-column mb-auto">
            <li class="nav-item">
                <a href="../panelAdmin/panelAdmin.html" class="nav-link active"><i class="fas fa-home me-3"></i> Inicio</a>
            </li>
            <li class="nav-item admin-only">
                <a href="../catalogo/catalogo.php" class="nav-link"><i class="fas fa-book me-3"></i> Catálogo</a>
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
            <a href="#" id="btn-cerrar-sesion" class="btn btn-outline-light w-100 text-start">
                <i class="fas fa-sign-out-alt me-2"></i> Cerrar sesión
            </a>
        </div>
    </nav>

    <main id="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="h3 mb-0 text-gray-800">Dashboard de Inventario</h2>
            <span class="text-muted"><i class="fas fa-calendar-alt me-2"></i> <span id="fecha-actual"></span></span>
        </div>

        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card shadow-sm border-0 rounded-3 h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="flex-shrink-0 me-3">
                            <div class="bg-primary bg-opacity-10 p-3 rounded-circle text-primary">
                                <i class="fas fa-boxes fa-2x"></i>
                            </div>
                        </div>
                        <div>
                            <h6 class="text-muted mb-1">Total de Productos</h6>
                            <h3 class="mb-0 fw-bold" id="kpi-total">0</h3>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card shadow-sm border-0 rounded-3 h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="flex-shrink-0 me-3">
                            <div class="bg-danger bg-opacity-10 p-3 rounded-circle text-danger">
                                <i class="fas fa-arrow-down fa-2x"></i>
                            </div>
                        </div>
                        <div>
                            <h6 class="text-muted mb-1">Stock Crítico (Bajo Min)</h6>
                            <h3 class="mb-0 fw-bold text-danger" id="kpi-bajo">0</h3>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card shadow-sm border-0 rounded-3 h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="flex-shrink-0 me-3">
                            <div class="bg-warning bg-opacity-10 p-3 rounded-circle text-warning">
                                <i class="fas fa-arrow-up fa-2x text-dark"></i>
                            </div>
                        </div>
                        <div>
                            <h6 class="text-muted mb-1">Excedentes (Sobre Max)</h6>
                            <h3 class="mb-0 fw-bold text-warning" id="kpi-sobre">0</h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm border-0 rounded-3">
            <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
                <h6 class="m-0 font-weight-bold text-primary" style="color: var(--azul-rey) !important;">
                    <i class="fas fa-search me-2"></i> Consulta de Stock y Alertas
                </h6>
                
                <div class="d-flex gap-2">
                    <select id="filtro-abc" class="form-select form-select-sm border-secondary text-secondary">
                        <option value="ALL">Todas las Categorías</option>
                        <option value="A">Clase A</option>
                        <option value="B">Clase B</option>
                        <option value="C">Clase C</option>
                    </select>
                    
                    <select id="filtro-alerta" class="form-select form-select-sm border-secondary text-secondary">
                        <option value="ALL">Todos los Estados</option>
                        <option value="CRITICO">Crítico (Bajo Mínimo)</option>
                        <option value="ADVERTENCIA">Advertencia (Sobre Máximo)</option>
                        <option value="OPTIMO">Óptimo (Rango normal)</option>
                    </select>
                </div>
            </div>
            
            <div class="card-body px-4 pt-3 pb-0">
                <input type="text" class="form-control bg-light" id="inputBuscador" 
                       placeholder="Buscar por código o nombre del producto..." autocomplete="off">
            </div>

            <div class="card-body p-0 mt-3">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="px-4">Código</th>
                                <th>Nombre</th>
                                <th>Clasificación</th>
                                <th class="text-center">Stock Actual</th>
                                <th class="text-center">Mínimo</th>
                                <th class="text-center">Máximo</th>
                            </tr>
                        </thead>
                        <tbody id="tablaProductos">
                            <tr><td colspan="6" class="text-center py-4 text-muted">Cargando inventario...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // --- 1. LÓGICA DE ROLES Y SESIÓN ---
            const rolUsuario = localStorage.getItem('usuario_rol') || 'Administrador';
            const folioUsuario = localStorage.getItem('usuario_folio') || 'ADM-0011';
            
            document.getElementById('user-info-display').innerText = `${rolUsuario} (${folioUsuario})`;

            if (rolUsuario === 'Operador') {
                const elementosAdmin = document.querySelectorAll('.admin-only');
                elementosAdmin.forEach(el => el.style.display = 'none');
            }

            document.getElementById('btn-cerrar-sesion').addEventListener('click', (e) => {
                e.preventDefault();
                localStorage.clear();
                window.location.href = 'login.html';
            });

            // Poner fecha actual
            const opcionesFecha = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            document.getElementById('fecha-actual').innerText = new Date().toLocaleDateString('es-MX', opcionesFecha);

            // --- 2. LÓGICA DEL DASHBOARD (RF-04) ---
            let inventarioGlobal = [];

            const tabla = document.getElementById('tablaProductos');
            const inputBuscador = document.getElementById('inputBuscador');
            const filtroAbc = document.getElementById('filtro-abc');
            const filtroAlerta = document.getElementById('filtro-alerta');
            
            async function cargarInventario() {
                try {
                    const response = await fetch('panelAdmin.php?accion=obtener_stock');
                    const result = await response.json();
                    
                    if(result.success) {
                        inventarioGlobal = result.data;
                        renderizarTabla(inventarioGlobal);
                        actualizarKPIs(inventarioGlobal);
                    }
                } catch (error) {
                    console.error("Error cargando inventario:", error);
                    tabla.innerHTML = '<tr><td colspan="6" class="text-center text-danger py-4">Error al conectar con el servidor.</td></tr>';
                }
            }

            // Aquí utilizamos TUS clases originales del CSS
            function evaluarEstado(stock, min, max) {
                stock = parseInt(stock); min = parseInt(min); max = parseInt(max);
                if (stock < min) return { rowClass: 'fila-critica', textClass: 'text-danger', valor: 'CRITICO' };
                if (stock > max) return { rowClass: 'fila-advertencia', textClass: 'text-warning', valor: 'ADVERTENCIA' };
                return { rowClass: 'fila-optima', textClass: 'text-success', valor: 'OPTIMO' };
            }

            function renderizarTabla(datos) {
                tabla.innerHTML = '';
                
                if(datos.length === 0) {
                    tabla.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted">No se encontraron productos.</td></tr>';
                    return;
                }

                datos.forEach(prod => {
                    const estado = evaluarEstado(prod.stock_actual, prod.cminima, prod.cmaxima);
                    
                    const row = `
                        <tr class="${estado.rowClass}">
                            <td class="px-4 fw-bold text-muted">${prod.codigo}</td>
                            <td>${prod.nombre}</td>
                            <td>Clase ${prod.categoria}</td>
                            <td class="text-center fw-bold ${estado.textClass}">${prod.stock_actual}</td>
                            <td class="text-center text-muted">${prod.cminima}</td>
                            <td class="text-center text-muted">${prod.cmaxima}</td>
                        </tr>
                    `;
                    tabla.innerHTML += row;
                });
            }

            function actualizarKPIs(datos) {
                let criticos = 0;
                let excedentes = 0;

                datos.forEach(prod => {
                    const estado = evaluarEstado(prod.stock_actual, prod.cminima, prod.cmaxima);
                    if(estado.valor === 'CRITICO') criticos++;
                    if(estado.valor === 'ADVERTENCIA') excedentes++;
                });

                document.getElementById('kpi-total').innerText = datos.length;
                document.getElementById('kpi-bajo').innerText = criticos;
                document.getElementById('kpi-sobre').innerText = excedentes;
            }

            function aplicarFiltros() {
                const texto = inputBuscador.value.toLowerCase();
                const abc = filtroAbc.value;
                const alerta = filtroAlerta.value;

                const datosFiltrados = inventarioGlobal.filter(prod => {
                    const estado = evaluarEstado(prod.stock_actual, prod.cminima, prod.cmaxima);
                    
                    const cumpleTexto = prod.codigo.toLowerCase().includes(texto) || prod.nombre.toLowerCase().includes(texto);
                    const cumpleAbc = (abc === 'ALL') || (prod.categoria === abc);
                    const cumpleAlerta = (alerta === 'ALL') || (estado.valor === alerta);

                    return cumpleTexto && cumpleAbc && cumpleAlerta;
                });

                renderizarTabla(datosFiltrados);
            }

            // Listeners para búsqueda en tiempo real
            inputBuscador.addEventListener('input', aplicarFiltros);
            filtroAbc.addEventListener('change', aplicarFiltros);
            filtroAlerta.addEventListener('change', aplicarFiltros);

            cargarInventario();
        });
    </script>
</body>
</html>