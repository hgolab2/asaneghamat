<?php
/**
* This file is created by Really Simple SSL
*/

if ( isset($_GET["rsssl_header_test"]) && (int) $_GET["rsssl_header_test"] ===  972336136 ) return;

if (!defined("RSSSL_HEADERS_ACTIVE")) define("RSSSL_HEADERS_ACTIVE", true);
//RULES START

if ( !headers_sent() ) {
header("X-XSS-Protection: 0");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("X-Frame-Options: SAMEORIGIN");
header("Content-Security-Policy: upgrade-insecure-requests; ");

}
