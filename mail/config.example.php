<?php
// Copie este archivo como config.php y complete los datos.
// config.php NO debe subirse al repositorio (ver .gitignore).
return [
    'host'      => 'mail.dataenging.com',
    'port'      => 465,               // 465 = SSL implícito, 587 = STARTTLS
    'secure'    => 'ssl',             // 'ssl' o 'tls'
    'user'      => 'USUARIO_SMTP',
    'pass'      => 'CONTRASEÑA_SMTP',
    'from'      => 'vrojas@dataenging.com',
    'from_name' => 'Sitio web Dataenging',
    'to'        => 'dataenging@gmail.com',
    'cc'        => [],                // copias opcionales, ej. ['otro@dominio.com']
];
