<?php

declare( strict_types = 1 );

/**
 * This suite is the regression test for the blueprint route —
 * BaseObject::createBlueprint builds empty, unsaved instances
 * past the constructor (subclass constructors like the User guest
 * fallback must not trigger), viewNewObject delivers the envelope
 * in collector format. The request layer filters invalid newClass
 * values fail-closed to null — the empty stdClass response is
 * shown by the frontend via the JS error channel. Runs without
 * local production data.
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.10
 * @since     2026-10-22
 *
 * @example   php includes/tests/blueprintTest.php
 *
 */

require_once ( __DIR__ . '/bootstrap.php' );

// --- createBlueprint: empty User instance without constructor fallbacks ---

$objBlueprint = BaseObject::createBlueprint( 'User' );

check( $objBlueprint instanceof User, 'createBlueprint: liefert eine Instanz der Zielklasse' );
check( $objBlueprint->id() === 'new', 'createBlueprint: Id ist die Registry-Kennung new' );
check( $objBlueprint->roleId() === null, 'createBlueprint: kein Guest-Fallback, roleId bleibt ungesetzt' );
check( $objBlueprint->password() === null, 'createBlueprint: Passwort bleibt ungesetzt' );
check( count( ( array ) $objBlueprint->fields() ) > 0, 'createBlueprint: Fields der Klasse reisen mit' );

// --- Blueprint envelope: shape identical to the bootstrap collector entry ---

$arrRawObject = $objBlueprint->rawObject();
$objRawObject = $arrRawObject[ 0 ];

check( $objRawObject->class === 'User', 'Blaupausen-Envelope: class ist der PHP-Klassenname' );
check( $objRawObject->id === 'new', 'Blaupausen-Envelope: id ist new' );
check( $objRawObject->data->id === 'new', 'Blaupausen-Envelope: data.id ist new (JS fillObject setzt die Registry-Id)' );
check( isset( $objRawObject->data->fields ), 'Blaupausen-Envelope: fields liegen im data-Payload' );
check( ! isset( $objRawObject->data->dataJsonFile ), 'Blaupausen-Envelope: NOSERIALIZE filtert dataJsonFile' );
check( ! isset( $objRawObject->data->password ), 'Blaupausen-Envelope: NOSERIALIZE filtert Passwort' );
check( json_encode( $objRawObject ) !== false, 'Blaupausen-Envelope: ist JSON-serialisierbar' );

// --- viewNewObject: valid class through the request layer (whitelist pass-through) ---

$_GET  = [ 'newClass' => 'User' ];
$_POST = [];

Request::reset();
$objController = mockController( 'viewNewObject' );
$objCarrier    = new BaseObject();

$objViewResult = $objCarrier->viewNewObject( $objController )[ 0 ];

check( $objViewResult->class === 'User', 'viewNewObject: gültige newClass liefert das Klassen-Envelope' );
check( $objViewResult->id === 'new', 'viewNewObject: Envelope-Id ist new' );
check( isset( $objViewResult->data->fields ), 'viewNewObject: Fields liegen im data-Payload' );

// --- viewNewObject: class outside the newClass whitelist filters fail-closed ---

$_GET  = [ 'newClass' => 'Role' ];
$_POST = [];

Request::reset();
$objController = mockController( 'viewNewObject' );
$objCarrier    = new BaseObject();

$objViewResult = $objCarrier->viewNewObject( $objController )[ 0 ];

check( $objViewResult instanceof stdClass, 'viewNewObject: gefilterte Klasse antwortet mit stdClass' );
check( get_object_vars( $objViewResult ) === [], 'viewNewObject: Klasse außerhalb der Whitelist liefert eine leere Antwort' );

// --- viewNewObject: without parameter likewise empty ---

$_GET  = [];
$_POST = [];

Request::reset();
$objController = mockController( 'viewNewObject' );
$objCarrier    = new BaseObject();

$objViewResult = $objCarrier->viewNewObject( $objController )[ 0 ];

check( get_object_vars( $objViewResult ) === [], 'viewNewObject: ohne newClass leere Antwort' );

// --- createBlueprint: non-BaseObject class fails fail-loud (hard channel in the live path) ---

$boolThrown = false;

try {
  BaseObject::createBlueprint( 'stdClass' );
} catch( Error $objError ) {
  $boolThrown = true;
}

check( $boolThrown, 'createBlueprint: Nicht-BaseObject-Klasse wirft fail-loud' );

Request::reset();

echo 'Alle Tests bestanden.' . PHP_EOL;

// EOF
