<?php
$host = 'ep-royal-fire-b4e0qwit-pooler.c-6.us-east-2.aws.neon.tech';
$dbname = 'neondb'; 
$user = 'neondb_owner';
$password = 'npg_n3CyIdspoq4T';


$endpoint_id = 'ep-royal-fire-b4e0qwit';

try {

    $dsn = "pgsql:host=$host;port=5432;dbname=$dbname;sslmode=require;options='endpoint=$endpoint_id'";
    
    $conexion = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    
} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}
?>