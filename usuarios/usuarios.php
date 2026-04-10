<?php
session_start();
require_once '../cnfg/conexionBD.php';

// 1. PROTECCIÓN DE RUTA Y ROL
if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_rol'] !== 'Administrador') {
    header("Location: ../panelAdmin/panelAdmin.php");
    exit;
}

$idUsuarioActual = $_SESSION['usuario_id'];

// =======================================================================
// 2. LÓGICA DE BACKEND (PETICIONES AJAX / POST)
// =======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $data = json_decode(file_get_contents("php://input"), true);
    $accion = $data['accion'] ?? '';

    try {
        $db = new ConexionBD();
        $conn = $db->getConnection();

        // A) CREAR NUEVO USUARIO
        if ($accion === 'crear') {
            $folio = strtoupper(trim($data['folio']));
            
            // Verificar que el folio no exista ya
            $stmtCheck = $conn->prepare("SELECT IDUSUARIO FROM USUARIOS WHERE ACCESO = :folio");
            $stmtCheck->execute([':folio' => $folio]);
            if ($stmtCheck->fetch()) {
                echo json_encode(['success' => false, 'message' => 'El folio ' . $folio . ' ya pertenece a otro usuario. Intenta con otro número.']);
                exit;
            }

            $stmtInsert = $conn->prepare("INSERT INTO USUARIOS (ACCESO, NOMBRE, PASSWRD, ROL, ESTATUS) VALUES (:folio, :nombre, :pass, :rol, 1)");
            $stmtInsert->execute([
                ':folio' => $folio,
                ':nombre' => trim($data['nombre']),
                ':pass' => $data['password'],
                ':rol' => $data['rol']
            ]);

            echo json_encode(['success' => true, 'message' => 'Usuario creado exitosamente.']);
            exit;
        }

        // B) CAMBIAR ESTATUS (Activar / Desactivar)
        if ($accion === 'cambiar_estatus') {
            $idTarget = $data['idUsuario'];
            $nuevoEstatus = $data['nuevoEstatus']; // 1 o 0

            // Protección extra: Un admin no puede desactivarse a sí mismo
            if ($idTarget == $idUsuarioActual && $nuevoEstatus == 0) {
                echo json_encode(['success' => false, 'message' => 'No puedes desactivar tu propia cuenta.']);
                exit;
            }

            $stmtUpdate = $conn->prepare("UPDATE USUARIOS SET ESTATUS = :estatus WHERE IDUSUARIO = :id");
            $stmtUpdate->execute([':estatus' => $nuevoEstatus, ':id' => $idTarget]);

            echo json_encode(['success' => true, 'message' => 'El estatus del usuario ha sido actualizado.']);
            exit;
        }

        // C) RESTABLECER CONTRASEÑA
        if ($accion === 'restablecer_pass') {
            $stmtPass = $conn->prepare("UPDATE USUARIOS SET PASSWRD = :pass WHERE IDUSUARIO = :id");
            $stmtPass->execute([
                ':pass' => $data['nuevaPassword'],
                ':id' => $data['idUsuario']
            ]);

            echo json_encode(['success' => true, 'message' => 'Contraseña actualizada correctamente.']);
            exit;
        }

        // D) ELIMINAR USUARIO POR COMPLETO (Físicamente de la BD)
        if ($accion === 'eliminar_completo') {
            $idTarget = $data['idUsuario'];
            
            // Protección: No puedes borrarte a ti mismo
            if ($idTarget == $idUsuarioActual) {
                echo json_encode(['success' => false, 'message' => 'No puedes eliminar tu propia cuenta de forma permanente.']);
                exit;
            }

            $stmtDelete = $conn->prepare("DELETE FROM USUARIOS WHERE IDUSUARIO = :id");
            $stmtDelete->execute([':id' => $idTarget]);

            echo json_encode(['success' => true, 'message' => 'El usuario ha sido eliminado permanentemente del sistema.']);
            exit;
        }

    } catch(PDOException $e) {
        // Si el error es por Integridad Referencial (Código 23000) y estábamos intentando eliminar...
        if ($e->getCode() == '23000' && $accion === 'eliminar_completo') {
            $idTarget = $data['idUsuario'];
            
            // 1. Obtenemos los datos actuales del usuario
            $stmtGet = $conn->prepare("SELECT ACCESO FROM USUARIOS WHERE IDUSUARIO = :id");
            $stmtGet->execute([':id' => $idTarget]);
            $user = $stmtGet->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                $folioViejo = $user['ACCESO']; // Ej: OPR-0015
                
                // 2. Creamos un folio "basura" único para liberar el original (Ej: DEL-5-0015)
                $folioBaja = 'DEL-' . $idTarget . '-' . substr($folioViejo, 4);
                
                // 3. Archivamos al usuario (Soft-Delete)
                // Le cambiamos el folio, lo desactivamos y le agregamos una etiqueta a su nombre
                $stmtSoftDelete = $conn->prepare("UPDATE USUARIOS SET ACCESO = :nuevoFolio, ESTATUS = 0, NOMBRE = CONCAT(NOMBRE, ' (Archivado)') WHERE IDUSUARIO = :id");
                $stmtSoftDelete->execute([
                    ':nuevoFolio' => $folioBaja,
                    ':id' => $idTarget
                ]);
                
                echo json_encode([
                    'success' => true, 
                    'message' => "El usuario tenía historial y se archivó de forma segura. El folio $folioViejo ya fue liberado y puedes volver a crearlo."
                ]);
            }
            exit;
        }
        
        // Si es cualquier otro tipo de error de la base de datos
        echo json_encode(['success' => false, 'message' => 'Error de BD: ' . $e->getMessage()]);
        exit;
    }
}

// =======================================================================
// 3. CARGAR LISTA DE USUARIOS (GET)
// =======================================================================
$listaUsuarios = [];
try {
    $db = new ConexionBD();
    $conn = $db->getConnection();
    $stmt = $conn->query("SELECT IDUSUARIO, ACCESO, NOMBRE, ROL, ESTATUS FROM USUARIOS ORDER BY ESTATUS DESC, ROL ASC, NOMBRE ASC");
    $listaUsuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $error_bd = "No se pudieron cargar los usuarios: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Inventario - Usuarios</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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
            <h2 class="h3 mb-0 text-gray-800">Gestión de Usuarios</h2>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAgregarUsuario">
                <i class="fas fa-user-plus me-2"></i> Nuevo Usuario
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
                                <th class="px-4">Folio (Acceso)</th>
                                <th>Nombre Completo</th>
                                <th>Rol</th>
                                <th class="text-center">Estatus</th>
                                <th class="text-end pe-4">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="listaUsuarios">
                            <?php if(!empty($listaUsuarios)): ?>
                                <?php foreach($listaUsuarios as $user): 
                                    $estatusClase = $user['ESTATUS'] == 1 ? 'bg-success' : 'bg-danger';
                                    $estatusTexto = $user['ESTATUS'] == 1 ? 'Activo' : 'Inactivo';
                                    $esElMismo = ($user['IDUSUARIO'] == $idUsuarioActual);
                                ?>
                                <tr>
                                    <td class="px-4 fw-bold text-muted"><?= htmlspecialchars($user['ACCESO']) ?></td>
                                    <td><?= htmlspecialchars($user['NOMBRE']) ?> <?= $esElMismo ? '<span class="badge bg-primary ms-1">Tú</span>' : '' ?></td>
                                    <td>
                                        <i class="fas <?= $user['ROL'] == 'Administrador' ? 'fa-user-shield text-primary' : 'fa-user text-secondary' ?> me-2"></i>
                                        <?= htmlspecialchars($user['ROL']) ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge <?= $estatusClase ?>"><?= $estatusTexto ?></span>
                                    </td>
                                    <td class="text-end pe-4">
                                        <button class="btn btn-sm btn-outline-dark btn-restablecer" 
                                                data-id="<?= $user['IDUSUARIO'] ?>" 
                                                data-nombre="<?= htmlspecialchars($user['NOMBRE']) ?>" 
                                                title="Restablecer Contraseña">
                                            <i class="fas fa-key"></i>
                                        </button>
                                        
                                        <?php if(!$esElMismo): ?>
                                            <?php if($user['ESTATUS'] == 1): ?>
                                                <button class="btn btn-sm btn-outline-warning btn-estatus" 
                                                        data-id="<?= $user['IDUSUARIO'] ?>" data-accion="0" title="Desactivar Usuario">
                                                    <i class="fas fa-user-slash"></i>
                                                </button>
                                            <?php else: ?>
                                                <button class="btn btn-sm btn-outline-success btn-estatus" 
                                                        data-id="<?= $user['IDUSUARIO'] ?>" data-accion="1" title="Activar Usuario">
                                                    <i class="fas fa-user-check"></i>
                                                </button>
                                            <?php endif; ?>
                                            
                                            <button class="btn btn-sm btn-outline-danger btn-eliminar-fisico ms-1" 
                                                    data-id="<?= $user['IDUSUARIO'] ?>" title="Eliminar Permanentemente">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="5" class="text-center py-4 text-muted">No hay usuarios registrados.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <div class="modal fade" id="modalAgregarUsuario" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header border-0">
                    <h5 class="modal-title fw-bold">Registrar Nuevo Usuario</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="formNuevoUsuario">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Nombre Completo</label>
                            <input type="text" class="form-control" id="add-nombre" required autocomplete="off">
                        </div>
                        <div class="row mb-3">
                            <div class="col-6">
                                <label class="form-label fw-bold">Rol</label>
                                <select class="form-select" id="add-rol" required>
                                    <option value="Operador" selected>Operador</option>
                                    <option value="Administrador">Administrador</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-bold">Número de Folio</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light fw-bold text-secondary" id="prefijo-rol">OPR-</span>
                                    <input type="text" class="form-control" id="add-folio-num" placeholder="Ej: 1020" maxlength="4" required autocomplete="off">
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Contraseña Temporal</label>
                            <input type="password" class="form-control" id="add-pass" required>
                        </div>
                    </div>
                    <div class="modal-footer border-0">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary" id="btnGuardarUser">Crear Usuario</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalRestablecer" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header border-0">
                    <h5 class="modal-title fw-bold">Restablecer Contraseña</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="formRestablecer">
                    <div class="modal-body">
                        <p class="text-muted">Asignando nueva contraseña para: <strong id="lbl-nombre-pass" class="text-dark"></strong></p>
                        <input type="hidden" id="reset-id">
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Nueva Contraseña</label>
                            <input type="text" class="form-control" id="reset-pass" required autocomplete="off">
                        </div>
                    </div>
                    <div class="modal-footer border-0">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-dark">Actualizar Contraseña</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            
            // Cierre de Sesión
            document.getElementById('btn-cerrar-sesion').addEventListener('click', (e) => {
                e.preventDefault();
                localStorage.clear();
                window.location.href = '../cnfg/logout.php';
            });

            const modalAgregar = new bootstrap.Modal(document.getElementById('modalAgregarUsuario'));
            const modalRestablecer = new bootstrap.Modal(document.getElementById('modalRestablecer'));

            // --- LÓGICA DE INTERFAZ: Cambiar prefijo al seleccionar rol ---
            document.getElementById('add-rol').addEventListener('change', function() {
                const prefijo = this.value === 'Administrador' ? 'ADM-' : 'OPR-';
                document.getElementById('prefijo-rol').innerText = prefijo;
            });

            // --- 1. CREAR NUEVO USUARIO ---
            document.getElementById('formNuevoUsuario').addEventListener('submit', async function(e) {
                e.preventDefault();
                const btn = document.getElementById('btnGuardarUser');
                
                const rol = document.getElementById('add-rol').value;
                const prefijo = rol === 'Administrador' ? 'ADM-' : 'OPR-';
                const numeroFolio = document.getElementById('add-folio-num').value.trim();

                // Validamos que el número sea de exactamente 4 dígitos
                if (!/^\d{4}$/.test(numeroFolio)) {
                    alert('El número de folio debe contener exactamente 4 dígitos numéricos (Ejemplo: 0015).');
                    return;
                }

                const folioCompleto = prefijo + numeroFolio;
                btn.disabled = true;

                const payload = {
                    accion: 'crear',
                    nombre: document.getElementById('add-nombre').value,
                    folio: folioCompleto,
                    rol: rol,
                    password: document.getElementById('add-pass').value
                };

                try {
                    const response = await fetch('usuarios.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload)
                    });
                    const result = await response.json();

                    if(result.success) {
                        alert(result.message);
                        window.location.reload();
                    } else {
                        // Aquí se muestra la alerta si el folio ya existe
                        alert(result.message);
                        btn.disabled = false;
                    }
                } catch (error) {
                    alert("Error de conexión al guardar el usuario.");
                    btn.disabled = false;
                }
            });

            // --- DELEGACIÓN DE EVENTOS (Tabla) ---
            document.getElementById('listaUsuarios').addEventListener('click', async function(e) {
                
                // --- 2. ACTIVAR / DESACTIVAR USUARIO ---
                const btnEstatus = e.target.closest('.btn-estatus');
                if (btnEstatus) {
                    const idUsuario = btnEstatus.dataset.id;
                    const nuevoEstatus = btnEstatus.dataset.accion; 
                    
                    const accionTexto = nuevoEstatus == '1' ? 'ACTIVAR' : 'DESACTIVAR';
                    if(!confirm(`¿Estás seguro de que deseas ${accionTexto} a este usuario?`)) return;

                    try {
                        const response = await fetch('usuarios.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ accion: 'cambiar_estatus', idUsuario: idUsuario, nuevoEstatus: nuevoEstatus })
                        });
                        const result = await response.json();

                        if(result.success) {
                            window.location.reload();
                        } else {
                            alert("Error: " + result.message);
                        }
                    } catch (error) {
                        alert("Error al intentar cambiar el estatus.");
                    }
                }

                // --- 3. ELIMINAR USUARIO POR COMPLETO ---
                const btnEliminarFisico = e.target.closest('.btn-eliminar-fisico');
                if (btnEliminarFisico) {
                    const idUsuario = btnEliminarFisico.dataset.id;
                    
                    if(!confirm(`¡ADVERTENCIA!\n\n¿Estás completamente seguro de que deseas ELIMINAR PERMANENTEMENTE a este usuario de la base de datos?\nEsta acción no se puede deshacer.`)) return;

                    try {
                        const response = await fetch('usuarios.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ accion: 'eliminar_completo', idUsuario: idUsuario })
                        });
                        const result = await response.json();

                        if(result.success) {
                            alert(result.message);
                            window.location.reload();
                        } else {
                            // Mostrará error si el usuario ya tiene historial de movimientos
                            alert(result.message);
                        }
                    } catch (error) {
                        alert("Error de conexión al intentar eliminar.");
                    }
                }

                // --- 4. ABRIR MODAL RESTABLECER CONTRASEÑA ---
                const btnRestablecer = e.target.closest('.btn-restablecer');
                if (btnRestablecer) {
                    document.getElementById('reset-id').value = btnRestablecer.dataset.id;
                    document.getElementById('lbl-nombre-pass').innerText = btnRestablecer.dataset.nombre;
                    document.getElementById('reset-pass').value = '';
                    modalRestablecer.show();
                }
            });

            // --- 5. GUARDAR NUEVA CONTRASEÑA ---
            document.getElementById('formRestablecer').addEventListener('submit', async function(e) {
                e.preventDefault();
                
                const idUsuario = document.getElementById('reset-id').value;
                const nuevaPass = document.getElementById('reset-pass').value;

                try {
                    const response = await fetch('usuarios.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ accion: 'restablecer_pass', idUsuario: idUsuario, nuevaPassword: nuevaPass })
                    });
                    const result = await response.json();

                    if(result.success) {
                        alert(result.message);
                        modalRestablecer.hide();
                    } else {
                        alert("Error: " + result.message);
                    }
                } catch (error) {
                    alert("Error al intentar restablecer la contraseña.");
                }
            });
        });
    </script>
</body>
</html>