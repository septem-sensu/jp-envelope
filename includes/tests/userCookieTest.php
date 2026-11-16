<?php

declare( strict_types = 1 );

/**
 * This suite is the regression test for the corrupt auth cookie fix:
 * getUserFromCookie() falls back silently to the guest path on every
 * corrupt cookie shape (invalid json, non-object payload, missing or
 * non-string token, undecryptable token, malformed token payload,
 * unknown user id) instead of crashing the boot with a hard error
 * (the former deCrypte(false) TypeError). The positive path resolves
 * a real user from a valid token. SKIP path: without a local user.json
 * the positive chain stays unchecked.
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.20
 * @since     2026-11-16
 *
 * @example   php includes/tests/userCookieTest.php
 *
 */

require_once ( __DIR__ . '/bootstrap.php' );

/**
 * This helper invokes the private getUserFromCookie() on a fresh user
 * instance via reflection: the guest fallback of the constructor is
 * reset (isUserSet = false) so that the cookie resolution result is
 * observable in isolation.
 *
 * @param     string    $strCookieValue   The raw cookie value ($_COOKIE format)
 * @return    boolean   True when no user was resolved (silent guest fallback) without a crash
 *
 * @example   $boolFallback = callCookieResolver( '{"corrupt' );
 *
 */
function callCookieResolver( string $strCookieValue ) : bool {
  $_COOKIE[ BaseObject::config()->cookieName() ] = $strCookieValue;

  $objUser       = new User();
  $objReflection = new ReflectionProperty( 'User', 'isUserSet' );

  $objReflection->setValue( $objUser, false );

  try {
    ( new ReflectionMethod( 'User', 'getUserFromCookie' ) )->invoke( $objUser );
  } catch( Throwable ) {
    return false;
  }

  return $objReflection->getValue( $objUser ) === false;
}

// --- corrupt cookie shapes: silent guest fallback, never a crash ---

check( callCookieResolver( '{corrupt json' ), 'Korruptes Cookie-JSON fällt still zum Gast zurück (kein Crash)' );
check( callCookieResolver( '"plain string"' ), 'Cookie-JSON als String fällt still zum Gast zurück' );
check( callCookieResolver( '[1,2]' ), 'Cookie-JSON als Array fällt still zum Gast zurück' );
check( callCookieResolver( '{"id":1}' ), 'Cookie-Objekt ohne Token fällt still zum Gast zurück' );
check( callCookieResolver( '{"token":123}' ), 'Cookie-Token als Zahl fällt still zum Gast zurück' );
check( callCookieResolver( '{"token":"garbage-not-a-cipher"}' ), 'Undecryptbarer Token fällt still zum Gast zurück (deCrypte RuntimeException)' );
check( callCookieResolver( '{"token":"' . BaseObject::enCrypte( 'plaintext-without-separator' ) . '"}' ), 'Token ohne |||-Separator fällt still zum Gast zurück (Malformed-Payload)' );

// --- positive path: valid token resolves the real user ---

if( ! file_exists( BaseObject::FILEPATHJSON . 'data/user.json' ) ) {
  echo 'SKIP: keine lokale user.json — positive Cookie-Kette nicht prüfbar.' . PHP_EOL;
  exit( 0 );
}

$strId = BaseObject::config()->defaultUserId();

if( ! BaseObject::existsObject( 'User', $strId ) ) {
  echo 'SKIP: defaultUserId existiert nicht in user.json — positive Cookie-Kette nicht prüfbar.' . PHP_EOL;
  exit( 0 );
}

$objUser   = new User( $strId );
$strToken = BaseObject::enCrypte( $strId . '|||' . $objUser->password() );

$_COOKIE[ BaseObject::config()->cookieName() ] = json_encode( [ 'token' => $strToken ] );

$objReflection = new ReflectionProperty( 'User', 'isUserSet' );
$objCookieUser = new User();

check( $objReflection->getValue( $objCookieUser ) === true, 'Gültiger Cookie-Token löst den User auf (Positive-Chain)' );
check( $objCookieUser->id() === $strId, 'Positive-Chain: id = User-Id aus dem Token' );

echo 'Alle Tests bestanden.' . PHP_EOL;

// EOF
