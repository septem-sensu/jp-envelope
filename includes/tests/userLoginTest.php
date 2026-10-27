<?php

declare( strict_types = 1 );

/**
 * This suite is the regression test for the login fix after the view
 * envelope decoupling: execute() throws the app context first and then
 * the current user (the guest on the login page) centrally into the
 * collector — otherwise the login page would have no JS user instance
 * and therefore no fieldValidator. SKIP paths: without a local users.json
 * the login chain stays unchecked.
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.10
 * @since     2026-10-18
 *
 * @example   php includes/tests/userLoginTest.php
 *
 */

require_once ( __DIR__ . '/bootstrap.php' );

$strUserFile = BaseObject::FILEPATHJSON . 'data/users.json';

if( ! file_exists( $strUserFile ) ) {
  echo 'SKIP: keine lokale users.json — Login-Kette nicht prüfbar.' . PHP_EOL;
  exit( 0 );
}

if( count( BaseObject::getObjectsFromClass( 'User' ) ) === 0 ) {
  echo 'SKIP: users.json enthält keine User.' . PHP_EOL;
  exit( 0 );
}

$objController = mockController( 'viewLogin' );

$strHtml        = $objController->execute();
$arrRawObjects  = $objController->rawObjects();

check( $strHtml !== '', 'execute(): Login-Seite rendert die Template-Kette' );
check( count( $arrRawObjects ) === 2, 'execute(): App-Kontext und aktueller User (Guest) in den Kollektor geworfen' );
check( $arrRawObjects[ 0 ]->class === 'App', 'Envelope: class App an Position 1 (Kontext-Griff für onAppReady)' );
check( $arrRawObjects[ 1 ]->class === 'User', 'Envelope: class User (löst loadClass von user.js aus)' );
check( $arrRawObjects[ 1 ]->id === $objController->user()->id(), 'Envelope: id = aktueller User (defaultUserId aus configs.json)' );
check( ! isset( $arrRawObjects[ 1 ]->data->password ), 'Envelope: kein Passwort-Hash (NOSERIALIZE)' );

$arrRawObjectsJson = json_decode( getTemplateVar( $objController->presentation(), 'default::rawObjects' ) );
check( count( $arrRawObjectsJson ) === 2, 'Template-Var: default::rawObjects trägt App- und Guest-Envelope als JSON' );
check( is_int( strpos( $strHtml, 'name="id"' ) ) && is_int( strpos( $strHtml, 'name="password"' ) ), 'Render: Login-Formular-Felder im HTML' );

// --- legacy decoupling (stage 3): window[appAlias].objects is completely gone ---

$objReflection   = new ReflectionProperty( 'Presentation', 'templateVars' );
$objTemplateVars = $objReflection->getValue( $objController->presentation() );

check( ! isset( $objTemplateVars->{'default::object'} ), 'Legacy: Template-Var default::object nicht mehr gesetzt' );
check( strpos( $strHtml, 'objects.object' ) === false, 'Header-Render: keine objects.object-Zuweisung mehr im JS-Bootstrap' );

echo 'Alle Tests bestanden.' . PHP_EOL;

// EOF
