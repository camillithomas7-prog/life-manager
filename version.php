<?php
/**
 * Endpoint versione: ritorna il timestamp di modifica dell'index.html.
 * Il client lo confronta con quello salvato in localStorage e ricarica
 * l'app se è cambiato (= nuovo deploy).
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$index = __DIR__ . '/index.html';
$v = file_exists($index) ? filemtime($index) : time();

echo json_encode(['version' => (string)$v]);
