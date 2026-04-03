<?php
session_start();
//Vacia la sesion del cliente
$_SESSION = []
//Elimina la sesion del servidor
session_destroy();
//Redirige al login xD
header("Location: login.html");
exit();
?>