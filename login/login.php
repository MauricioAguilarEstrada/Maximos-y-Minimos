<?php
session_start();
header('Content-Type: application/json'); 

$host = 'ep-royal-fire-b4e0qwit-pooler.c-6.us-east-2.aws.neon.tech';
$dbname = 'neondb';
$endpoint_id = 'ep-royal-fire-b4e0qwit';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    
    // JS envía 'folio' y 'password'
    $usuario_form = trim($data['folio'] ?? ''); 
    $password_form = trim($data['password'] ?? ''); 
    
    try {
        $dsn = "pgsql:host=$host;port=5432;dbname=$dbname;sslmode=require;options='endpoint=$endpoint_id'";
        $conexion = new PDO($dsn, $usuario_form, $password_form, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        
        $stmt = $conexion->prepare("SELECT nombre, estatus FROM public.usuarios WHERE acceso = :acceso");
        $stmt->execute([':acceso' => $usuario_form]);
        $datosUsuario = $stmt->fetch();
        
        if ($datosUsuario && $datosUsuario['estatus'] == true) {
            $_SESSION['usuario_bd'] = $usuario_form;
            $_SESSION['password_bd'] = $password_form;
            $_SESSION['nombre_usuario'] = $datosUsuario['nombre'];
            
            // Deducir el rol usando el prefijo de la nomenclatura
            $rol_detectado = (strpos($usuario_form, 'ADM') === 0) ? 'Administrador' : 'Operador';
            
            // Devolver las variables exactas que JavaScript
            echo json_encode([
                "success" => true, 
                "message" => "Autenticado",
                "rol" => $rol_detectado,
                "folio" => $usuario_form
            ]);
            exit;
        } else {
            echo json_encode([
                "success" => false, 
                "message" => "Acceso denegado: El usuario está inactivo."
            ]);
            exit;
        }
        
    } catch (PDOException $e) {
        echo json_encode([
            "success" => false, 
            "message" => "Usuario o contraseña incorrectos."
        ]);
        exit;
    }
} else {
    echo json_encode(["success" => false, "message" => "Método no permitido."]);
    exit;
}
?>