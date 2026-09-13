<?php
/**
* This file is created by Really Simple SSL to test the CSP header length
* It will not load during regular wordpress execution
*/


if ( !headers_sent() ) {
header("X-XSS-Protection: 0");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("X-Frame-Options: SAMEORIGIN");
header("Content-Security-Policy: upgrade-insecure-requests; ");

header("X-REALLY-SIMPLE-SSL-TEST: %172%C5%09K%EA.%F5%B6%A4%B4%3D%28%9D%D2%DBhI%9E%F4%09%2AO%EEc%EF%7F%5D%AE%9F%0D%95%CFQ%FA%AF%C9%C9c%5Bmz%D2%EAP%5C%3B%DD%A6%18%E91%28%92%9Ae%2Ao%95%BB3u%9Fz%17V%12%BB%3D%83%9D%A1%09n%DE%BC%9F%0C%03P%EF%86%2B%94TBmH%E9%FE%81%21%0B%F8%06%160%21%BCkQ%D7%C1%8D%BD%08%2Cf%19%B9%0A%86%D6%D7%82%155i%87%D6%EC%82%B9%CC%F1%00lX%F4%829%3A.%FA%DDvc%F1%F5%90%1A%C6j%FD%3D.%D9%7E%F7%95%12D-%9F+%BA%15%A6%A0%EF%DB%03%EFL%B0%B4%B4P%E4%3F%3E%0EG%CB%95%8E%DA%7D%F1%C6%EB%5En%CB%99%FB%C9%2A%B1%28%D9%86Y%A4%8Ab%23%DC%9");
}

 echo '<html><head><meta charset="UTF-8"><META NAME="ROBOTS" CONTENT="NOINDEX, NOFOLLOW"></head><body>Really Simple SSL headers test page</body></html>';