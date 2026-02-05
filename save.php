<?php
$data = [
    "empresa" => $_POST['empresa'],
    "menu" => $_POST['menu'],
    "boleto_url" => $_POST['boleto_url'],
    "atendente_numero" => $_POST['atendente_numero']
];

file_put_contents('../config.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

header("Location: index.php");
