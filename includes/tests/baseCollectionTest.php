<?php

declare( strict_types = 1 );

/**
 * This suite is the regression test for the BaseCollection container:
 * push/unshift with single elements and arrays including the type
 * validation, pop/shift, clear, set, the iteration (IteratorAggregate,
 * Countable) and the idArray() read route of the persistence contract.
 * The suite runs entirely in memory — no files are written, so no
 * backup or cleanup handling is needed.
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.17
 * @since     2026-11-02
 *
 * @example   php includes/tests/baseCollectionTest.php
 *
 */

require_once ( __DIR__ . '/bootstrap.php' );

/**
 * Local test element: a minimal BaseObject subclass with one payload
 * property and accessor. The base class provides id(), so the element
 * is fully usable for the idArray() checks.
 *
 */
class CollectionTestItem extends BaseObject {

  /**
   * The payload property of the test element.
   *
   * @var       string
   *
   */
  protected string $mixPayload = '';

  /**
   * This method sets the payload property of the test element.
   *
   * @param     string    $strValue   The payload value
   * @return    void
   *
   */
  public function setPayload( string $strValue ) : void {
    $this->mixPayload = $strValue;

    return;
  }

  /**
   * This method reads the payload property of the test element.
   *
   * @return    string   The payload value
   *
   */
  public function payload() : string {
    return $this->mixPayload;
  }

}

/**
 * Local test collection: declares CollectionTestItem as the allowed
 * element type.
 *
 */
class CollectionTestCollection extends BaseCollection {

  /**
   * This method declares the allowed element type of the collection.
   *
   * @return    string   The class name of the allowed element type
   *
   */
  public function getAllowedType() : string {
    return CollectionTestItem::class;
  }

}

$objItemA = new CollectionTestItem();
$objItemA->setPayload( 'A' );

$objItemB = new CollectionTestItem();
$objItemB->setPayload( 'B' );

$objItemC = new CollectionTestItem();
$objItemC->setPayload( 'C' );

$objItemD = new CollectionTestItem();
$objItemD->setPayload( 'D' );

check( $objItemA->id() !== $objItemB->id(), 'Setup: frische Instanzen tragen getrennte Ids' );

// --- empty collection ---

$objCollection = new CollectionTestCollection();

check( $objCollection->getAllowedType() === CollectionTestItem::class, 'deklaration: allowed type ist CollectionTestItem' );
check( count( $objCollection ) === 0, 'leer: count ist 0' );
check( $objCollection->getAll() === [], 'leer: getAll liefert ein leeres Array' );
check( $objCollection->idArray() === [], 'leer: idArray liefert ein leeres Array' );
check( $objCollection->pop() === null, 'leer: pop liefert null' );
check( $objCollection->shift() === null, 'leer: shift liefert null' );

// --- push: single and array, type validation ---

$objCollection->push( $objItemA );

check( count( $objCollection ) === 1, 'push single: Collection hält ein Element' );
check( $objCollection->getAll()[ 0 ] === $objItemA, 'push single: Element an Position 1' );

$objCollection->push( [ $objItemB, $objItemC ] );

check( count( $objCollection ) === 3, 'push array: Collection hält drei Elemente' );
check( $objCollection->getAll()[ 1 ] === $objItemB && $objCollection->getAll()[ 2 ] === $objItemC, 'push array: Reihenfolge bewahrt' );

$boolCaught = false;

try {
  $objCollection->push( new stdClass() );
} catch( InvalidArgumentException ) {
  $boolCaught = true;
}

check( $boolCaught, 'push invalid: fremder Typ wirft InvalidArgumentException' );
check( count( $objCollection ) === 3, 'push invalid: Collection bleibt unverändert' );

$objCollection = new CollectionTestCollection();
$boolCaught    = false;

try {
  $objCollection->push( [ $objItemA, new stdClass() ] );
} catch( InvalidArgumentException ) {
  $boolCaught = true;
}

check( count( $objCollection ) === 1 && $objCollection->getAll()[ 0 ] === $objItemA, 'push invalid: im Array vor dem Mismatch angehängte Elemente bleiben' );

// --- unshift: single and array as whole block ---

$objCollection = new CollectionTestCollection();
$objCollection->push( [ $objItemB, $objItemC ] );
$objCollection->unshift( $objItemA );

check( $objCollection->getAll() === [ $objItemA, $objItemB, $objItemC ], 'unshift single: Element vorne eingefügt' );

$objCollection = new CollectionTestCollection();
$objCollection->push( $objItemC );
$objCollection->unshift( [ $objItemA, $objItemB ] );

check( $objCollection->getAll() === [ $objItemA, $objItemB, $objItemC ], 'unshift array: als ganzer Block in eigener Reihenfolge vorangestellt' );

$boolCaught = false;

try {
  $objCollection->unshift( 'fremder Typ' );
} catch( InvalidArgumentException ) {
  $boolCaught = true;
}

check( $boolCaught, 'unshift invalid: fremder Typ wirft InvalidArgumentException' );
check( count( $objCollection ) === 3, 'unshift invalid: Collection bleibt unverändert' );

// --- pop and shift: remove from both ends ---

$objCollection = new CollectionTestCollection();
$objCollection->push( [ $objItemA, $objItemB, $objItemC ] );

check( $objCollection->pop() === $objItemC, 'pop: letztes Element zurückgegeben' );
check( count( $objCollection ) === 2, 'pop: count gesunken' );
check( $objCollection->shift() === $objItemA, 'shift: erstes Element zurückgegeben' );
check( $objCollection->getAll() === [ $objItemB ], 'shift: verbleibendes Element gerückt' );

// --- clear and set ---

$objCollection->clear();

check( count( $objCollection ) === 0, 'clear: Collection geleert' );

$objCollection->set( [ $objItemA, $objItemB ] );

check( $objCollection->getAll() === [ $objItemA, $objItemB ], 'set array: Inhalt ersetzt' );

$objCollection->set( $objItemC );

check( $objCollection->getAll() === [ $objItemC ], 'set single: Inhalt durch ein Element ersetzt' );

// --- iteration via IteratorAggregate ---

$arrIterated = [];

$objCollection->set( [ $objItemA, $objItemB, $objItemC ] );

foreach( $objCollection as $objItem ) {
  $arrIterated[] = $objItem;
}

check( $arrIterated === [ $objItemA, $objItemB, $objItemC ], 'iteration: foreach liefert alle Elemente in Reihenfolge' );

// --- idArray: the read route of the persistence contract ---

$arrIds = $objCollection->idArray();

check( $arrIds === [ $objItemA->id(), $objItemB->id(), $objItemC->id() ], 'idArray: Ids in Einfügereihenfolge' );

$objCollection->pop();
$objCollection->shift();

$arrIds = $objCollection->idArray();

check( $arrIds === [ $objItemB->id() ], 'idArray: nach pop/shift korrekt verbleibende Id' );
check( is_array( json_decode( json_encode( $arrIds ) ) ), 'idArray: Normalisierung liefert eine als JSON-Array serialisierbare Liste' );

echo 'Alle Tests bestanden.' . PHP_EOL;

// EOF
