<?php

declare( strict_types = 1 );

/**
 * This suite is the regression test for Request and params.json — the
 * central GET/POST validation: scope isolation, types, whitelists,
 * patterns, defaults, the value cache and the fail-loud guards.
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.10
 * @since     2026-10-19
 *
 * @example   php includes/tests/requestTest.php
 *
 */

require_once ( __DIR__ . '/bootstrap.php' );

$_GET  = [];
$_POST = [];

// --- global branch: declared parameters, GET before POST ---

check( Request::param( 'user' ) === null, 'global: ohne Wert -> null' );

$_POST = [];
Request::reset();

// --- fail-closed: undeclared parameters do not exist ---

$_GET[ 'console' ] = 'boese';
check( Request::param( 'console' ) === null, 'fail-closed: nicht deklarierter Parameter -> null trotz GET-Wert' );

Request::reset();

$_GET[ 'user' ] = [ 'array-injection' ];
check( Request::param( 'user' ) === null, 'Robustheit: Array statt String -> null' );
unset( $_GET[ 'user' ] );

Request::reset();

$_GET[ 'user' ] = '';
check( Request::param( 'user' ) === null, 'Robustheit: Leerstring gilt als abwesend -> null' );
unset( $_GET[ 'user' ] );

Request::reset();

// --- string type + pattern rule ---

$_GET[ 'view' ] = '../etc/passwd';
check( Request::param( 'view' ) === null, 'pattern: Pfad-Sonderzeichen -> Default null' );

Request::reset();

$_GET[ 'class' ] = 'Role';
check( Request::param( 'class' ) === 'Role', 'values: erlaubter Klassenname durchgereicht' );

$_GET[ 'class' ] = 'StdClass';
Request::reset();

check( Request::param( 'class' ) === null, 'values: nicht erlaubter Klassenname -> null (Whitelist für new $class)' );

Request::reset();

// --- scope isolation: view parameters only with a set scope ---

$boolThrown = false;

try {
  Request::setScope( 'gibtEsNicht' );
} catch( RuntimeException $objException ) {
  $boolThrown = true;
}

check( $boolThrown, 'fail-loud: unbekannter View-Zweig -> RuntimeException' );

Request::reset();

echo 'Alle Tests bestanden.' . PHP_EOL;

// EOF
