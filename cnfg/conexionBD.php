<?php

class ConexionBD {
    private $host = 'MAURICIO\\SQLEXPRESS'; 
    private $db_name = 'MAX_MIN';
    private $username = 'sa';
    private $password = '12345';

    public function getConnection() {
        $this->conn = null;
        try {
            $this->conn = new PDO("sqlsrv:server=" . $this->host . ";Database=" . $this->db_name, $this->username, $this->password);
            
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            
        } catch(PDOException $exception) {
            header('Content-Type: application/json');
            echo json_encode([
                "success" => false, 
                "message" => "Error de conexión a la base de datos: " . $exception->getMessage()
            ]);
            exit; 
        }
        return $this->conn;
    }
}
?>