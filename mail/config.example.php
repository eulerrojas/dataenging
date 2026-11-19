<?php
// Copie este archivo como config.php y complete los datos.
// config.php NO debe subirse al repositorio (ver .gitignore).
//
// Gmail: active la verificación en 2 pasos y genere una "Contraseña de aplicación"
// en https://myaccount.google.com/apppasswords. Use esa clave en 'pass'.
return [
    'host'      => 'smtp.gmail.com',
    'port'      => 465,               // 465 = SSL implícito, 587 = STARTTLS
    'secure'    => 'ssl',             // 'ssl' o 'tls'
    'user'      => 'dataenging@gmail.com',
    'pass'      => 'xxxx xxxx xxxx xxxx',
    'from'      => 'dataenging@gmail.com',
    'from_name' => 'Sitio web Dataenging',
    'to'        => 'dataenging@gmail.com',
    'cc'        => ['eulerrojas@gmail.com'],   // copia; dejar [] para no enviar copia
];
