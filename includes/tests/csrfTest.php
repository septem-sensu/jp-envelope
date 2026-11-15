<?php

declare( strict_types = 1 );

/**
 * This suite is the regression test for the CSRF guard of the
 * controller (0.2.19): POST requests with an auth cookie must deliver
 * a csrfToken parameter that matches the stateless HMAC over the raw
 * cookie token (createCsrfToken) — otherwise the request is rejected
 * before any action runs (execute() answers json views softly with a
 * single AppError envelope, html views with a 303 redirect — the
 * redirect branch is not assertable in CLI, documented waiver like
 * the index.php safety net in hardErrorTest.php). The guard order:
 * only POST is guarded (GET stays read-only), a guest without cookie
 * stays unguarded (login POST), a missing or wrong token rejects.
 * No SKIP path: the token derivation needs only the committed
 * cryptKeys.php placeholders plus the config.
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.19
 * @since     2026-11-15
 *
 * @example   php includes/tests/csrfTest.php
 *
 */

require_once ( __DIR__ . '/bootstrap.php' );

$strCookieName  = BaseObject::config()->cookieName();
$objReflection  = new ReflectionMethod( 'Controller', 'isCsrfViolation' );

// --- S1: createCsrfToken is deterministic and input-sensitive ---

$strTokenSameOne  = BaseObject::createCsrfToken( 'rohes-cookie-token-a' );
$strTokenSameTwo  = BaseObject::createCsrfToken( 'rohes-cookie-token-a' );
$strTokenOther    = BaseObject::createCsrfToken( 'rohes-cookie-token-b' );

check( $strTokenSameOne === $strTokenSameTwo, 'S1: createCsrfToken ist deterministisch (gleicher Input, gleicher Token)' );
check( $strTokenSameOne !== $strTokenOther, 'S1: unterschiedlicher Cookie-Token liefert unterschiedlichen CSRF-Token' );
check( strlen( $strTokenSameOne ) === 64, 'S1: Token ist ein SHA-256-HMAC (64 Hex-Zeichen)' );

// --- S2: GET requests stay unguarded (even with cookie and without token) ---

unset( $_SERVER[ 'REQUEST_METHOD' ] );

$_GET    = [];
$_POST   = [];
$_COOKIE = [];

Request::reset();

$objController = mockController( 'viewSaveObject' );

$_COOKIE = [ $strCookieName => json_encode( [ 'token' => 'rohes-cookie-token-a' ] ) ];

check( $objReflection->invoke( $objController ) === false, 'S2: GET-Request bleibt ungeschützt (auch mit Cookie und fehlendem Token)' );

// --- S3: POST without cookie (guest) stays unguarded ---

$_SERVER[ 'REQUEST_METHOD' ] = 'POST';

$_GET    = [];
$_POST   = [];
$_COOKIE = [];

Request::reset();

$objController = mockController( 'viewSaveObject' );

check( $objReflection->invoke( $objController ) === false, 'S3: POST ohne Cookie (Gast/Login) bleibt ungeschützt' );

// --- S4: POST with cookie and the correct token passes ---

$_GET    = [];
$_POST   = [];
$_COOKIE = [];

Request::reset();

$objController = mockController( 'viewSaveObject' );

$_POST   = [ 'csrfToken' => BaseObject::createCsrfToken( 'rohes-cookie-token-a' ) ];
$_COOKIE = [ $strCookieName => json_encode( [ 'token' => 'rohes-cookie-token-a' ] ) ];

check( $objReflection->invoke( $objController ) === false, 'S4: POST mit Cookie und korrektem Token läuft durch' );

// --- S5: POST with cookie and a wrong token rejects ---

$_GET    = [];
$_POST   = [];
$_COOKIE = [];

Request::reset();

$objController = mockController( 'viewSaveObject' );

$_POST   = [ 'csrfToken' => str_repeat( 'f', 64 ) ];
$_COOKIE = [ $strCookieName => json_encode( [ 'token' => 'rohes-cookie-token-a' ] ) ];

check( $objReflection->invoke( $objController ) === true, 'S5: POST mit Cookie und falschem Token wird abgelehnt' );

// --- S6: POST with cookie and a missing token parameter rejects ---

$_GET    = [];
$_POST   = [];
$_COOKIE = [];

Request::reset();

$objController = mockController( 'viewSaveObject' );

$_COOKIE = [ $strCookieName => json_encode( [ 'token' => 'rohes-cookie-token-a' ] ) ];

check( $objReflection->invoke( $objController ) === true, 'S6: POST mit Cookie ohne Token-Parameter wird abgelehnt' );

// --- S7: execute() answers the JSON rejection softly (single AppError envelope, source 'csrf') ---

$_GET    = [];
$_POST   = [];
$_COOKIE = [];

Request::reset();

$objController = mockController( 'viewSaveObject' );

$_POST   = [ 'csrfToken' => str_repeat( 'f', 64 ) ];
$_COOKIE = [ $strCookieName => json_encode( [ 'token' => 'rohes-cookie-token-a' ] ) ];

$strJson    = $objController->execute();
$arrPayload = json_decode( $strJson );

check( is_array( $arrPayload ) && count( $arrPayload ) === 1, 'S7: Ablehnung ist ein Ein-Envelope-Payload' );
check( $arrPayload[ 0 ]->class === 'AppError', 'S7: Envelope-Klasse ist AppError' );
check( str_starts_with( $arrPayload[ 0 ]->id, 'error' ), 'S7: Envelope-Id folgt dem error{N}-Muster' );
check( $arrPayload[ 0 ]->data->message === 'Sitzung ungültig. Bitte laden Sie die Seite neu.', 'S7: Meldung ist der generische Erneuerungs-Hinweis' );
check( $arrPayload[ 0 ]->data->source === 'csrf', 'S7: Source ist csrf' );
check( $arrPayload[ 0 ]->data->severity === 'error', 'S7: Severity ist error' );

// --- Cleanup: leave the superglobals and the request state as found ---

unset( $_SERVER[ 'REQUEST_METHOD' ] );

$_GET    = [];
$_POST   = [];
$_COOKIE = [];

Request::reset();

echo 'Alle Tests bestanden.' . PHP_EOL;

// EOF
