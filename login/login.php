<?php
session_start();
require_once '../cnfg/conexionBD.php';

header('Content-Type: application/json');
//Entrada de datos (se reciben del JS)
$data = json_decode(file_get_contents("php://input"), true);
$folio = $data['folio'] ?? '';
$password = $data['password'] ?? '';

if (empty($folio) || empty($password)) {
    echo json_encode(['success' => false, 'message' => 'Folio y contraseña son obligatorios.']);
    exit;
}

try {
    $db = new ConexionBD();
    $conn = $db->getConnection();

    $query = "SELECT IDUSUARIO, ACCESO, NOMBRE, PASSWRD, ROL, ESTATUS FROM USUARIOS WHERE ACCESO = :folio";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':folio', $folio);
    $stmt->execute();

    // LA SOLUCIÓN: Intentamos extraer los datos directamente en lugar de contarlos
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($usuario) { // Si $usuario tiene datos, significa que sí lo encontró
        
        if ($usuario['ESTATUS'] == 0) {
            echo json_encode(['success' => false, 'message' => 'Este usuario está inactivo.']);
            exit;
        }

        // Verificamos contraseña exacta
        if ($password === $usuario['PASSWRD']) { 
            $_SESSION['usuario_id'] = $usuario['IDUSUARIO'];
            $_SESSION['usuario_folio'] = $usuario['ACCESO'];
            $_SESSION['usuario_rol'] = $usuario['ROL'];
            $_SESSION['usuario_nombre'] = $usuario['NOMBRE'];

            echo json_encode([
                'success' => true, 
                'message' => 'Acceso concedido',
                'rol' => $usuario['ROL'],
                'folio' => $usuario['ACCESO']
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Contraseña incorrecta.']);
        }
    } else {
        // Si $usuario está vacío (false), entonces sí es verdad que no existe
        echo json_encode(['success' => false, 'message' => 'El folio ingresado no existe.']);
    }

} catch(Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error en el servidor: ' . $e->getMessage()]);
}
?>