<?php

declare( strict_types = 1 );

/**
 * This suite is the regression test for the error channel —
 * Controller::addError collects AppError instances (ascending ids,
 * severity whitelist fail-loud) and AppError is rawObjects-capable as an
 * envelope. The execute() throw itself is only a foreach over errors()
 * and is not tested isolated with the full app dependencies — the
 * action rejections including their message texts are covered by
 * userSaveDeleteTest.php.
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.10
 * @since     2026-10-20
 *
 * @example   php includes/tests/errorTest.php
 *
 */

require_once ( __DIR__ . '/bootstrap.php' );

$strUserFile = BaseObject::FILEPATHJSON . 'data/users.json';

if( ! file_exists( $strUserFile ) ) {
  echo 'SKIP: keine lokale users.json — Fehlerkanal ohne Auth-Kontext nicht prüfbar.' . PHP_EOL;
  exit( 0 );
}

$_GET  = [];
$_POST = [];

Request::reset();
$objController = mockController( 'viewUserList' );

// --- addError: default values ---

$objController->addError( 'Erste Testmeldung' );

$arrErrors = $objController->errors();

check( count( $arrErrors ) === 1, 'addError: ersten Fehler gesammelt' );
check( $arrErrors[ 0 ]->message() === 'Erste Testmeldung', 'addError: Meldungstext durchgereicht' );
check( $arrErrors[ 0 ]->severity() === 'error', 'addError: Severity-Default ist error' );
check( $arrErrors[ 0 ]->source() === null, 'addError: Source-Default ist null' );
check( $arrErrors[ 0 ]->id() === 'error1', 'addError: erste Instanz-Id ist error1' );

// --- addError: second message with custom severity and source ---

$objController->addError( 'Zweite Testmeldung', 'changeUser', 'warning' );

$arrErrors = $objController->errors();

check( count( $arrErrors ) === 2, 'addError: Fehler akkumulieren' );
check( $arrErrors[ 1 ]->id() === 'error2', 'addError: Instanz-Ids laufen fortlaufend' );
check( $arrErrors[ 1 ]->severity() === 'warning', 'addError: Severity warning durchgereicht' );
check( $arrErrors[ 1 ]->source() === 'changeUser', 'addError: Source (Action-Trigger) durchgereicht' );

// --- addError: unknown severity throws fail-loud ---

$boolThrown = false;

try {
  $objController->addError( 'Dritte Testmeldung', null, 'fatal' );
} catch( RuntimeException $objException ) {
  $boolThrown = true;
}

check( $boolThrown, 'addError: unbekannte Severity wirft RuntimeException' );
check( count( $objController->errors() ) === 2, 'addError: der Exception-Wurf sammelt keinen Fehler' );

// --- AppError envelope: rawObject shape for the rawObjects frontend ---

$objError = new AppError( 'errorEnvelopeTest' );
$objError->setMessage( 'Envelope-Testmeldung', 'deleteUser', 'warning' );

$objRawObject = $objError->rawObject()[ 0 ];

check( $objRawObject->class === 'AppError', 'AppError-Envelope: class ist der PHP-Klassenname' );
check( $objRawObject->id === 'errorEnvelopeTest', 'AppError-Envelope: id ist die Registry-Id' );
check( $objRawObject->data->message === 'Envelope-Testmeldung', 'AppError-Envelope: message liegt im data-Payload' );
check( $objRawObject->data->severity === 'warning', 'AppError-Envelope: severity liegt im data-Payload' );
check( $objRawObject->data->source === 'deleteUser', 'AppError-Envelope: source liegt im data-Payload' );
check( ! isset( $objRawObject->data->dataJsonFile ), 'AppError-Envelope: NOSERIALIZE filtert die technischen Properties' );
check( json_encode( $objRawObject ) !== false, 'AppError-Envelope: ist JSON-serialisierbar' );

// --- AppError: collected by the collector (same route as user) ---

$objError = new AppError( 'errorRawTest' );
$objError->setMessage( 'RawTest-Meldung' );
$objController->addRawObject( $objError );

$arrRawObjects = $objController->rawObjects();

check( count( $arrRawObjects ) === 1 && $arrRawObjects[ 0 ]->class === 'AppError' && $arrRawObjects[ 0 ]->id === 'errorRawTest', 'addRawObject: nimmt AppError als Envelope auf' );

Request::reset();

echo 'Alle Tests bestanden.' . PHP_EOL;

// EOF
