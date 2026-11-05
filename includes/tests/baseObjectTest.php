<?php

declare( strict_types = 1 );

/**
 * This suite is the regression test for the object management of
 * BaseObject: insertObject with the duplicate-id guard, the static
 * read routes (existsObject, getRawObject, getRawObjectsFromClass,
 * getObject, getObjectsFromClass), the persistence roundtrip of
 * saveObject() and fillObject() (builtin, class reference and
 * collection properties, NOSAVE filter, last-wins update), the
 * deleteObject cycle and the viewLoadObject envelope route. The write
 * locking itself is covered by lockTest. The suite writes only into
 * its own class json files derived from the test class names
 * (persistTestObject.json, persistTestChild.json) — they are
 * removed on every suite end by the shutdown handler, the local
 * production data files are never touched. The missing-file guard of
 * loadFileDeCrypted() is checked before the first insert creates its
 * storage file. The positive viewLoadObject check reads the first local
 * user and therefore carries a SKIP path on machines without user.json.
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.17
 * @since     2026-11-02
 *
 * @example   php includes/tests/baseObjectTest.php
 *
 */

require_once ( __DIR__ . '/bootstrap.php' );

$strObjectsFile  = BaseObject::FILEPATHJSON . 'data/persistTestObject.json';
$strChildrenFile = BaseObject::FILEPATHJSON . 'data/persistTestChild.json';

/**
 * This shutdown handler removes the two class json files of the test
 * classes including their lock side files — it runs on every suite
 * end, including a FEHLER exit of check().
 *
 * @return    void
 *
 */
register_shutdown_function( function() use ( $strObjectsFile, $strChildrenFile ) : void {
  @unlink( $strObjectsFile );
  @unlink( $strObjectsFile . '.lock' );
  @unlink( $strChildrenFile );
  @unlink( $strChildrenFile . '.lock' );

  return;
} );

/**
 * Local test child: a minimal BaseObject subclass with one payload
 * property — the allowed element type of the test collection and the
 * target class of the 'class' reference of PersistTestObject.
 *
 */
class PersistTestChild extends BaseObject {

  /**
   * The payload property of the test child.
   *
   * @var       string
   *
   */
  protected string $mixPayload = '';

  /**
   * This method sets the payload property of the test child.
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
   * This method reads the payload property of the test child.
   *
   * @return    string   The payload value
   *
   */
  public function payload() : string {
    return $this->mixPayload;
  }

}

/**
 * Local test collection: declares PersistTestChild as the allowed
 * element type.
 *
 */
class PersistTestCollection extends BaseCollection {

  /**
   * This method declares the allowed element type of the collection.
   *
   * @return    string   The class name of the allowed element type
   *
   */
  public function getAllowedType() : string {
    return PersistTestChild::class;
  }

}

/**
 * Local test parent: a BaseObject with one property of every property
 * type of the persistence contract (builtin, class reference,
 * collection) and a runtime property filtered by the own NOSAVE
 * override. The nullable property declarations deliberately use the
 * '?Class' form — getPropertyType()/getPropertyClass() read the bare
 * type name via ReflectionNamedType.
 *
 */
class PersistTestObject extends BaseObject {

  /**
   * The persistence filter of the test parent: beside the base
   * defaults the runtime property is filtered from every save.
   *
   * @var       array
   *
   */
  const NOSAVE = [ "dataJsonFile", "fields", "strRuntime" ];

  /**
   * The builtin payload property of the test parent.
   *
   * @var       string
   *
   */
  protected string $mixPayload = '';

  /**
   * The 'class' reference property of the test parent.
   *
   * @var       PersistTestChild|null
   *
   */
  protected ?PersistTestChild $objChild = null;

  /**
   * The 'collection' property of the test parent.
   *
   * @var       PersistTestCollection|null
   *
   */
  protected ?PersistTestCollection $objItems = null;

  /**
   * The runtime property, filtered from persistence by NOSAVE.
   *
   * @var       string
   *
   */
  protected string $strRuntime = '';

  /**
   * This method sets the builtin payload property of the test parent.
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
   * This method reads the builtin payload property of the test parent.
   *
   * @return    string   The payload value
   *
   */
  public function payload() : string {
    return $this->mixPayload;
  }

  /**
   * This method sets the 'class' reference property of the test parent.
   *
   * @param     PersistTestChild   $objChild   The referenced child instance
   * @return    void
   *
   */
  public function setChild( PersistTestChild $objChild ) : void {
    $this->objChild = $objChild;

    return;
  }

  /**
   * This method reads the 'class' reference property of the test parent.
   *
   * @return    PersistTestChild|null   The referenced child instance or null
   *
   */
  public function child() : ?PersistTestChild {
    return $this->objChild;
  }

  /**
   * This method sets the 'collection' property of the test parent.
   *
   * @param     PersistTestCollection   $objItems   The collection instance
   * @return    void
   *
   */
  public function setItems( PersistTestCollection $objItems ) : void {
    $this->objItems = $objItems;

    return;
  }

  /**
   * This method reads the 'collection' property of the test parent.
   *
   * @return    PersistTestCollection|null   The collection instance or null
   *
   */
  public function items() : ?PersistTestCollection {
    return $this->objItems;
  }

  /**
   * This method sets the runtime property of the test parent.
   *
   * @param     string    $strValue   The runtime value
   * @return    void
   *
   */
  public function setRuntime( string $strValue ) : void {
    $this->strRuntime = $strValue;

    return;
  }

}

// --- setup: remove leftovers of earlier crashed runs, missing-file guard ---

@unlink( $strObjectsFile );
@unlink( $strObjectsFile . '.lock' );
@unlink( $strChildrenFile );
@unlink( $strChildrenFile . '.lock' );

check( BaseObject::loadFileDeCrypted( $strObjectsFile ) === null, 'loadFileDeCrypted: nicht existierende Datei liefert null (Guard) statt Read-Crash' );

// --- insertObject: create entries with the duplicate-id guard ---

$objEntry = new stdClass();
$objEntry->mixPayload = 'Kind Eins';

check( BaseObject::insertObject( 'PersistTestChild', '', $objEntry ) === false, 'insertObject: leere Id legt keinen Eintrag an' );
check( BaseObject::insertObject( 'PersistTestChild', 'kindEins', $objEntry ) === true, 'insertObject: gültiger Eintrag angelegt' );
check( file_exists( $strChildrenFile ), 'insertObject: Klassendatei persistTestChild.json angelegt' );
check( ! BaseObject::isJson( file_get_contents( $strChildrenFile ) ), 'insertObject: Inhalt AES-verschlüsselt gespeichert' );
check( BaseObject::insertObject( 'PersistTestChild', 'kindEins', $objEntry ) === false, 'insertObject: belegte Id wird nicht überschrieben' );

// --- existsObject ---

check( BaseObject::existsObject( 'PersistTestChild', 'kindEins' ) === true, 'existsObject: vorhandene Id erkannt' );
check( BaseObject::existsObject( 'PersistTestChild', 'kindZwei' ) === false, 'existsObject: unbekannte Id verneint' );
check( BaseObject::existsObject( 'PersistTestChild', '' ) === false, 'existsObject: leere Id verneint' );
check( BaseObject::existsObject( '', 'kindEins' ) === false, 'existsObject: leere Klasse verneint' );

// --- getRawObject and getRawObjectsFromClass ---

$objRawChild = BaseObject::getRawObject( 'PersistTestChild', 'kindEins' );

check( $objRawChild !== null && $objRawChild->mixPayload === 'Kind Eins', 'getRawObject: Entry-Felder liegen vor' );
check( BaseObject::getRawObject( 'PersistTestChild', 'niemand' ) === null, 'getRawObject: unbekannte Id liefert null' );
check( BaseObject::getRawObject( 'PersistTestChild', '' ) === null, 'getRawObject: leere Id liefert null' );

$objRawChildren = BaseObject::getRawObjectsFromClass( 'PersistTestChild' );

check( isset( $objRawChildren->kindEins ), 'getRawObjectsFromClass: Einträge der Klassendatei liegen vor' );
check( BaseObject::getRawObjectsFromClass( '' ) === null, 'getRawObjectsFromClass: leere Klasse liefert null' );

// --- getObject and getObjectsFromClass ---

$objChild = BaseObject::getObject( 'PersistTestChild', 'kindEins' );

check( $objChild instanceof PersistTestChild && $objChild->id() === 'kindEins' && $objChild->payload() === 'Kind Eins', 'getObject: Instanz der Zielklasse mit geladenem Payload' );
check( BaseObject::getObject( 'PersistTestChild', 'niemand' ) === null, 'getObject: unbekannte Id liefert null' );

$arrChildren = BaseObject::getObjectsFromClass( 'PersistTestChild' );

check( count( $arrChildren ) === 1 && $arrChildren[ 0 ] instanceof PersistTestChild, 'getObjectsFromClass: Instanz jedes Eintrags zurückgegeben' );
check( BaseObject::getObjectsFromClass( '' ) === [], 'getObjectsFromClass: leere Klasse liefert ein leeres Array' );
check( BaseObject::getObjectsFromClass( 'KlasseOhneKlassendatei' ) === [], 'getObjectsFromClass: Klasse ohne Klassendatei liefert ein leeres Array ohne Warning' );

// --- persistence roundtrip: saveObject -> raw file -> fillObject ---

$objChildZwei = new PersistTestChild();
$objChildZwei->setPayload( 'Kind Zwei' );
$objChildZwei->saveObject();

check( BaseObject::existsObject( 'PersistTestChild', $objChildZwei->id() ), 'saveObject: Child-Instanz unter neuer Id persistiert' );

$objParent = new PersistTestObject();
$objParent->setPayload( 'ElternPayload' );
$objParent->setChild( $objChild );
$objParent->setItems( new PersistTestCollection() );
$objParent->items()->push( [ $objChild, $objChildZwei ] );
$objParent->setRuntime( 'Laufzeitwert' );
$objParent->saveObject();

check( BaseObject::existsObject( 'PersistTestObject', $objParent->id() ), 'saveObject: Parent persistiert' );

$objRawParent = BaseObject::loadFileDeCrypted( $strObjectsFile )->{ $objParent->id() };

check( $objRawParent->mixPayload === 'ElternPayload', 'roundtrip: builtin Property persistiert' );
check( $objRawParent->objChild === $objChild->id(), 'roundtrip: class-Referenz als Id-String persistiert' );
check( $objRawParent->objItems === [ $objChild->id(), $objChildZwei->id() ], 'roundtrip: collection als Id-Liste in Reihenfolge persistiert' );
check( ! isset( $objRawParent->strRuntime ), 'NOSAVE: Laufzeit-Property strRuntime wird nicht persistiert' );
check( ! isset( $objRawParent->fields ) && ! isset( $objRawParent->dataJsonFile ), 'NOSAVE: technische Basis-Properties werden nicht persistiert' );

$objReloaded = new PersistTestObject( $objParent->id() );

check( $objReloaded->payload() === 'ElternPayload', 'roundtrip: builtin Property zurückgeladen' );
check( $objReloaded->child() instanceof PersistTestChild && $objReloaded->child()->payload() === 'Kind Eins', 'roundtrip: class-Referenz als Instanz zurückgeladen' );
check( $objReloaded->items() instanceof PersistTestCollection && $objReloaded->items()->idArray() === [ $objChild->id(), $objChildZwei->id() ], 'roundtrip: collection als Instanz mit Id-Liste zurückgeladen' );

$objReloaded->setPayload( 'UpdatePayload' );
$objReloaded->saveObject();

check( BaseObject::loadFileDeCrypted( $strObjectsFile )->{ $objParent->id() }->mixPayload === 'UpdatePayload', 'saveObject: bestehender Eintrag aktualisiert (last-wins)' );

// --- fillObject reload semantics: builtins restored from file, instances rebuilt from ids ---

$objGuard = new PersistTestObject( $objParent->id() );
$objErsatz = new PersistTestChild();

check( $objGuard->payload() === 'UpdatePayload', 'guard: frisch geladene Instanz trägt den gespeicherten Stand' );

$objGuard->setPayload( 'Vorgeladen' );
$objGuard->setChild( $objErsatz );
$objGuard->setItems( new PersistTestCollection() );
$objGuard->fillObject();

check( $objGuard->child() !== $objErsatz && $objGuard->child() instanceof PersistTestChild && $objGuard->child()->payload() === 'Kind Eins', 'fillObject: class-Referenz wird aus dem gespeicherten Stand neu aufgebaut' );
check( $objGuard->items() instanceof PersistTestCollection && $objGuard->items()->count() === 2, 'fillObject: Collection wird aus dem gespeicherten Stand neu aufgebaut' );
check( $objGuard->payload() === 'UpdatePayload', 'fillObject: builtin Property vom gespeicherten Stand überschrieben' );

// --- fillObject no-op paths ---

$objGhost = new PersistTestObject( 'niemandDa' );

check( $objGhost->payload() === '' && $objGhost->child() === null && $objGhost->items() === null, 'fillObject: nicht existierende Id bleibt eine leere Instanz (Blaupausen-Pfad)' );

// --- deleteObject cycle + the generic route hook defaults ---

check( $objParent->deleteObject() === true, 'deleteObject: Eintrag entfernt' );
check( BaseObject::existsObject( 'PersistTestObject', $objParent->id() ) === false, 'deleteObject: Id danach unbekannt' );
check( $objParent->deleteObject() === false, 'deleteObject: zweites Löschen liefert false' );

check( $objParent->onSave( null ) === null, 'hook: onSave-Basis-Default erlaubt den Save (null)' );
check( $objParent->onDelete( null ) === null, 'hook: onDelete-Basis-Default erlaubt das Löschen (null)' );

// --- viewLoadObject: the envelope route of the loader ---

Request::reset();
Request::setScope( 'viewLoadObject' );

$arrEnvelope = ( new PersistTestObject() )->viewLoadObject( mockController( 'viewLoadObject' ) );

check( count( $arrEnvelope ) === 0, 'viewLoadObject: fehlende Parameter antworten leer' );

Request::reset();
Request::setScope( 'viewLoadObject' );

$_GET[ 'class' ] = 'PersistTestObject';

$arrEnvelope = ( new PersistTestObject() )->viewLoadObject( mockController( 'viewLoadObject' ) );

check( count( $arrEnvelope ) === 0, 'viewLoadObject: Klasse außerhalb der Param-Whitelist antwortet leer' );

Request::reset();
Request::setScope( 'viewLoadObject' );

$_GET[ 'class' ] = 'User';
$_GET[ 'id' ]    = 'niemandExistiert';

$arrEnvelope = ( new PersistTestObject() )->viewLoadObject( mockController( 'viewLoadObject' ) );

check( count( $arrEnvelope ) === 0, 'viewLoadObject: nicht existierende Id antwortet leer' );

if( count( BaseObject::getObjectsFromClass( 'User' ) ) > 0 ) {
  $objUser = BaseObject::getObjectsFromClass( 'User' )[ 0 ];

  Request::reset();
  Request::setScope( 'viewLoadObject' );

  $_GET[ 'class' ] = 'User';
  $_GET[ 'id' ]    = $objUser->id();

  $arrEnvelope = ( new PersistTestObject() )->viewLoadObject( mockController( 'viewLoadObject' ) );

  check( count( $arrEnvelope ) === 1 && $arrEnvelope[ 0 ]->class === 'User' && $arrEnvelope[ 0 ]->id === $objUser->id(), 'viewLoadObject: Envelope der gewhitlisteten Klasse' );
  check( isset( $arrEnvelope[ 0 ]->data->id ), 'viewLoadObject: data-Payload liegt vor' );
} else {
  echo 'SKIP: keine lokale user.json — viewLoadObject-Positive-Fall nicht prüfbar.' . PHP_EOL;
}

Request::reset();

echo 'Alle Tests bestanden.' . PHP_EOL;

// EOF
