<?php
/**
 * Recibe el formulario de contactenos.html y lo envía por SMTP a dataenging@gmail.com.
 * Responde JSON: {"ok": true} o {"ok": false, "error": "..."}.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function responder(bool $ok, string $error = '', int $status = 200): void
{
    http_response_code($status);
    echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder(false, 'Método no permitido.', 405);
}

$configFile = __DIR__ . '/mail/config.php';
if (!is_file($configFile)) {
    responder(false, 'Servidor de correo no configurado.', 500);
}
$cfg = require $configFile;

// ---------- Datos del formulario ----------
function campo(string $nombre, int $max): string
{
    $v = trim((string)($_POST[$nombre] ?? ''));
    return mb_substr($v, 0, $max, 'UTF-8');
}

// Honeypot anti-spam: humanos no ven este campo.
if (campo('sitio_web', 200) !== '') {
    responder(true);
}

$nombre   = campo('nombre', 100);
$empresa  = campo('empresa', 150);
$correo   = campo('correo', 150);
$telefono = campo('telefono', 40);
$mensaje  = campo('mensaje', 5000);

if ($nombre === '' || $correo === '' || $mensaje === '') {
    responder(false, 'Complete nombre, correo y mensaje.', 422);
}
if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    responder(false, 'El correo no es válido.', 422);
}
// Evita inyección de cabeceras.
foreach ([$nombre, $correo, $empresa, $telefono] as $v) {
    if (preg_match('/[\r\n]/', $v)) {
        responder(false, 'Datos no válidos.', 422);
    }
}

// Límite simple: 1 envío cada 30 s por sesión.
session_start();
if (isset($_SESSION['ultimo_envio']) && time() - $_SESSION['ultimo_envio'] < 30) {
    responder(false, 'Espere unos segundos antes de enviar otro mensaje.', 429);
}

// ---------- Plantilla ----------
date_default_timezone_set('America/Caracas');
$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$valores = [
    '{{nombre}}'   => $e($nombre),
    '{{empresa}}'  => $empresa !== '' ? $e($empresa) : '—',
    '{{correo}}'   => $e($correo),
    '{{telefono}}' => $telefono !== '' ? $e($telefono) : '—',
    '{{mensaje}}'  => nl2br($e($mensaje)),
    '{{fecha}}'    => date('d/m/Y H:i'),
    '{{ip}}'       => $e($_SERVER['REMOTE_ADDR'] ?? 'desconocida'),
];
$html = strtr((string)file_get_contents(__DIR__ . '/mail/plantilla-contacto.html'), $valores);

$texto = "Nuevo mensaje desde el formulario de Contáctenos\n\n"
       . "Nombre:   $nombre\n"
       . "Empresa:  " . ($empresa ?: '-') . "\n"
       . "Correo:   $correo\n"
       . "Teléfono: " . ($telefono ?: '-') . "\n\n"
       . "Mensaje:\n$mensaje\n";

$asunto = 'Nuevo contacto web: ' . $nombre . ($empresa !== '' ? " ($empresa)" : '');

// ---------- Envío ----------
try {
    enviarSmtp($cfg, $asunto, $html, $texto, $correo, $nombre);
    $_SESSION['ultimo_envio'] = time();
    responder(true);
} catch (Throwable $ex) {
    error_log('[contacto] ' . $ex->getMessage());
    responder(false, 'No se pudo enviar el mensaje. Intente más tarde o escríbanos a ' . $cfg['to'] . '.', 500);
}

// ---------- Cliente SMTP mínimo ----------
function codificar(string $s): string
{
    return '=?UTF-8?B?' . base64_encode($s) . '?=';
}

function enviarSmtp(array $cfg, string $asunto, string $html, string $texto, string $replyTo, string $replyName): void
{
    $host = ($cfg['secure'] === 'ssl' ? 'ssl://' : '') . $cfg['host'];
    $sock = @stream_socket_client("$host:{$cfg['port']}", $errno, $errstr, 15);
    if (!$sock) {
        throw new RuntimeException("Conexión SMTP fallida: $errstr ($errno)");
    }
    stream_set_timeout($sock, 15);

    $leer = static function () use ($sock): string {
        $resp = '';
        while (($linea = fgets($sock, 515)) !== false) {
            $resp .= $linea;
            if (isset($linea[3]) && $linea[3] === ' ') {
                break;
            }
        }
        return $resp;
    };
    $cmd = static function (?string $linea, int $esperado) use ($sock, $leer): string {
        if ($linea !== null) {
            fwrite($sock, $linea . "\r\n");
        }
        $resp = $leer();
        if ((int)substr($resp, 0, 3) !== $esperado) {
            // No registrar credenciales en el log.
            $mostrar = in_array($esperado, [235, 334], true) ? 'AUTH' : (string)$linea;
            throw new RuntimeException("SMTP '$mostrar' respondió: " . trim($resp));
        }
        return $resp;
    };

    $dominio = $_SERVER['SERVER_NAME'] ?? 'localhost';
    $cmd(null, 220);
    $cmd("EHLO $dominio", 250);

    if ($cfg['secure'] === 'tls') {
        $cmd('STARTTLS', 220);
        if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT)) {
            throw new RuntimeException('No se pudo iniciar TLS.');
        }
        $cmd("EHLO $dominio", 250);
    }

    $cmd('AUTH LOGIN', 334);
    $cmd(base64_encode($cfg['user']), 334);
    $cmd(base64_encode($cfg['pass']), 235);

    $cmd("MAIL FROM:<{$cfg['from']}>", 250);
    $cc = array_values(array_filter((array)($cfg['cc'] ?? []), static fn($c) => filter_var($c, FILTER_VALIDATE_EMAIL)));
    $cmd("RCPT TO:<{$cfg['to']}>", 250);
    foreach ($cc as $c) {
        $cmd("RCPT TO:<$c>", 250);
    }
    $cmd('DATA', 354);

    $limite = 'b_' . bin2hex(random_bytes(12));
    $cabeceras = [
        'Date: ' . date('r'),
        'From: ' . codificar($cfg['from_name']) . " <{$cfg['from']}>",
        "To: <{$cfg['to']}>",
    ];
    if ($cc) {
        $cabeceras[] = 'Cc: ' . implode(', ', array_map(static fn($c) => "<$c>", $cc));
    }
    $cabeceras = array_merge($cabeceras, [
        'Reply-To: ' . codificar($replyName) . " <$replyTo>",
        'Subject: ' . codificar($asunto),
        'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $dominio . '>',
        'MIME-Version: 1.0',
        "Content-Type: multipart/alternative; boundary=\"$limite\"",
    ]);
    $cuerpo = "--$limite\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($texto))
            . "--$limite\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html))
            . "--$limite--\r\n";

    fwrite($sock, implode("\r\n", $cabeceras) . "\r\n\r\n" . $cuerpo . "\r\n.\r\n");
    $cmd(null, 250);
    // El mensaje ya fue aceptado; un error en QUIT no debe marcar el envío como fallido.
    fwrite($sock, "QUIT\r\n");
    fclose($sock);
}
