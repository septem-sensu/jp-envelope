<?php

declare( strict_types = 1 );

/**
 * This suite is the regression test for the generic json crud routes of
 * BaseObject: viewSaveObject (merge create with blueprint id and server
 * generated id, merge update of declared fields with the NOSERIALIZE,
 * NOSAVE, id-immutability, roleIds and password filters, FieldValidator
 * gate, onSave hook rejection, reference descriptor merge) and
 * viewDeleteObject (unknown id, params whitelist silence, positive
 * delete over the User class with a suite-owned test account). The
 * reference merge covers the mirrored collector descriptors (object and
 * collection), the declared-class and existence guards, the format
 * rejections and the empty-value clearing — persisted as id and id list
 * and reloaded through fillObject(). The still-closed guards
 * (missing/broken request payload, classes without field definitions)
 * answer empty per the json view convention; rejections travel as
 * AppError envelopes. The classPermissions whitelist of the views is
 * injected into the mock controller per suite helper (the production
 * view.json map declares only the production classes): it covers the
 * whitelist silence for undeclared classes, the owner pseudo role
 * (own object allowed, foreign object denied, create never granted
 * via owner, entries without ownerId stay role-only), the ownerId
 * stamping on creates (envelope and persisted entry) and the forgery
 * protection (a delivered ownerId is no field contract and stays
 * without effect). The suite writes only into its own class json
 * files (persistTestObject.json, persistTestElement.json) including
 * the matching temporary field definitions (fields/persistTestObject.json,
 * fields/persistTestReferenceObject.json) — all removed on every suite
 * end by the shutdown handler. The User positive cases use a suite-owned
 * test account (persisttest@local.test), the permission cases an
 * additional owner account (persistowner@local.test, owns itself) and a
 * foreign victim account (persistvictim@local.test, ownerId = the owner)
 * — the handler removes all; the local production accounts are never
 * touched. The load and delete permission checks run over the User
 * class: the params whitelist passes only production classes as the GET
 * class parameter, the suite classes are reachable over the save route
 * payload only.
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.20
 * @since     2026-11-03
 *
 * @example   php includes/tests/genericCrudTest.php
 *
 */

require_once ( __DIR__ . '/bootstrap.php' );

$strDataFile      = BaseObject::FILEPATHJSON . 'data/persistTestObject.json';
$strFieldsFile    = BaseObject::FILEPATHFIELDS . 'persistTestObject.json';
$strRefDataFile   = BaseObject::FILEPATHJSON . 'data/persistTestReferenceObject.json';
$strElemDataFile  = BaseObject::FILEPATHJSON . 'data/persistTestElement.json';
$strRefFieldsFile = BaseObject::FILEPATHFIELDS . 'persistTestReferenceObject.json';
$strTestUserId    = 'persisttest@local.test';
$strOwnerUserId   = 'persistowner@local.test';
$strVictimUserId  = 'persistvictim@local.test';

/**
 * This shutdown handler removes the class json file, the lock side file
 * and the temporary field definitions of the test class — and the
 * suite-owned test accounts in the local user.json if the delete route
 * did not already. It runs on every suite end, including a FEHLER exit
 * of check().
 *
 * @return    void
 *
 */
register_shutdown_function( function() use ( $strDataFile, $strFieldsFile, $strRefDataFile, $strElemDataFile, $strRefFieldsFile, $strTestUserId, $strOwnerUserId, $strVictimUserId ) : void {
  @unlink( $strDataFile );
  @unlink( $strDataFile . '.lock' );
  @unlink( $strFieldsFile );
  @unlink( $strRefDataFile );
  @unlink( $strRefDataFile . '.lock' );
  @unlink( $strElemDataFile );
  @unlink( $strElemDataFile . '.lock' );
  @unlink( $strRefFieldsFile );

  if( BaseObject::existsObject( 'User', $strTestUserId ) ) {
    ( new User( $strTestUserId ) )->deleteObject();
  }

  if( BaseObject::existsObject( 'User', $strOwnerUserId ) ) {
    ( new User( $strOwnerUserId ) )->deleteObject();
  }

  if( BaseObject::existsObject( 'User', $strVictimUserId ) ) {
    ( new User( $strVictimUserId ) )->deleteObject();
  }

  return;
} );

/**
 * Local test class: a minimal CRUD-capable BaseObject subclass with the
 * declared field mixPayload (mandatory) and strSecret (NOSERIALIZE
 * guard against forged envelope writes) plus the hook logic: a payload
 * 'BLOCK' aborts the save route, a payload 'KEEP' reports on delete.
 *
 */
class PersistTestObject extends BaseObject {

  /**
   * The serialization filter of the test class: beside the base defaults
   * the secret property never leaves the server (neither in the response
   * envelope nor into the merged state).
   *
   * @var       array
   *
   */
  const NOSERIALIZE = [ "dataJsonFile", "actionNames", "strSecret" ];

  /**
   * The declared payload field of the test class (fields/persistTestObject.json).
   *
   * @var       string
   *
   */
  protected string $mixPayload = '';

  /**
   * The declared secret field of the test class, filtered by NOSERIALIZE.
   *
   * @var       string
   *
   */
  protected string $strSecret = '';

  /**
   * This method is the onSave hook of the test class: a payload 'BLOCK'
   * aborts the generic save route with its rejection message.
   *
   * @param     Controller|null   $objController   The controller instance of the request or null
   * @return    string|null       The rejection message or null
   *
   */
  public function onSave( ?Controller $objController = null ) : ?string {
    if( $this->mixPayload === 'BLOCK' ) return 'Der onSave-Hook hat den Save blockiert.';

    return null;
  }

  /**
   * This method is the onDelete hook of the test class: a payload 'KEEP'
   * rejects the delete over the hook protocol.
   *
   * @param     Controller|null   $objController   The controller instance of the request or null
   * @return    string|null       The rejection message or null
   *
   */
  public function onDelete( ?Controller $objController = null ) : ?string {
    if( $this->mixPayload === 'KEEP' ) return 'Der onDelete-Hook hat das Löschen blockiert.';

    return null;
  }

  /**
   * This method reads the payload property of the test class.
   *
   * @return    string   The payload value
   *
   */
  public function payload() : string {
    return $this->mixPayload;
  }

}

/**
 * Local test class: the referenced element of the reference suite cases,
 * a bare BaseObject subclass without own fields (persisted as the
 * suite-owned id/class entry in persistTestElement.json).
 *
 */
class PersistTestElement extends BaseObject {

}

/**
 * Local test collection: the typed container of the reference suite
 * cases, allowed element type PersistTestElement.
 *
 */
class PersistTestElementCollection extends BaseCollection {

  /**
   * This method declares the allowed element type of the test
   * collection.
   *
   * @return    string   The class name of the allowed element type
   *
   */
  public function getAllowedType() : string {
    return 'PersistTestElement';
  }

}

/**
 * Local test class: a CRUD-capable BaseObject subclass with the
 * declared reference mix (one object reference and one collection
 * reference, both onto PersistTestElement) beside the mandatory
 * mixPayload field — the subject of the reference descriptor merge.
 *
 */
class PersistTestReferenceObject extends BaseObject {

  /**
   * The declared payload field of the test class (mandatory).
   *
   * @var       string
   *
   */
  protected string $mixPayload = '';

  /**
   * The declared object reference field of the test class.
   *
   * @var       PersistTestElement
   *
   */
  protected PersistTestElement $objElement;

  /**
   * The declared collection reference field of the test class.
   *
   * @var       PersistTestElementCollection
   *
   */
  protected PersistTestElementCollection $objElements;

}

/**
 * This function injects a classPermissions map into the view object of
 * the mock controller (protected property, test-only reflection access)
 * — the route gates read the map from the request view.
 *
 * @param     Controller   $objController   The mock controller
 * @param     array        $arrMap          The classPermissions map (class => role ids)
 * @return    void
 *
 */
function setMockClassPermissions( Controller $objController, array $arrMap ) : void {
  ( new ReflectionProperty( 'View', 'classPermissions' ) )->setValue( $objController->viewObject(), (object) $arrMap );

  return;
}

/**
 * This function builds the mock controller for the generic route calls
 * and injects the suite's classPermissions map into the request view:
 * the production view.json map declares only the production classes, so
 * the suite classes would be denied as undeclared — the guest mock role
 * is the listed permission of the map, keeping the historical route
 * checks untouched.
 *
 * @param     string       $strViewId   The view id for the scope
 * @return    Controller   The mocked controller instance
 *
 */
function crudMockController( string $strViewId ) : Controller {
  $objController = mockController( $strViewId );

  setMockClassPermissions( $objController, [
    'PersistTestObject'          => [ 'guest' ],
    'PersistTestReferenceObject' => [ 'guest' ],
    'User'                       => [ 'guest' ]
  ] );

  return $objController;
}

// --- setup: remove leftovers of earlier crashed runs ---

@unlink( $strDataFile );
@unlink( $strDataFile . '.lock' );
@unlink( $strFieldsFile );
@unlink( $strRefDataFile );
@unlink( $strRefDataFile . '.lock' );
@unlink( $strElemDataFile );
@unlink( $strElemDataFile . '.lock' );
@unlink( $strRefFieldsFile );

// --- viewSaveObject: the still-closed guards answer empty ---

Request::reset();
Request::setScope( 'viewSaveObject' );

$arrEnvelope = ( new PersistTestObject() )->viewSaveObject( crudMockController( 'viewSaveObject' ) );

check( count( $arrEnvelope ) === 0, 'viewSaveObject: fehlender object-Parameter antwortet still-leer' );

Request::reset();
Request::setScope( 'viewSaveObject' );

$_POST[ 'object' ] = '{unterm Bogen';

$arrEnvelope = ( new PersistTestObject() )->viewSaveObject( crudMockController( 'viewSaveObject' ) );

check( count( $arrEnvelope ) === 1 && $arrEnvelope[ 0 ]->class === 'AppError' && $arrEnvelope[ 0 ]->data->message === 'Ungültiges Objekt übermittelt.' && $arrEnvelope[ 0 ]->data->source === 'viewSaveObject', 'viewSaveObject: kaputtes JSON antwortet mit AppError-Envelope (Quelle viewSaveObject)' );

$_POST[ 'object' ] = json_encode( [ 'class' => 'Party', 'id' => 'x', 'data' => new stdClass() ] );

Request::reset();
Request::setScope( 'viewSaveObject' );

$arrEnvelope = ( new PersistTestObject() )->viewSaveObject( crudMockController( 'viewSaveObject' ) );

check( count( $arrEnvelope ) === 0, 'viewSaveObject: unbekannte Klasse wird still gefiltert' );

$_POST[ 'object' ] = json_encode( [ 'class' => 'Controller', 'id' => 'x', 'data' => new stdClass() ] );

Request::reset();
Request::setScope( 'viewSaveObject' );

$arrEnvelope = ( new PersistTestObject() )->viewSaveObject( crudMockController( 'viewSaveObject' ) );

check( count( $arrEnvelope ) === 0, 'viewSaveObject: Klasse ohne Felddefinition (Core) wird still gefiltert (CRUD-Gate)' );

// --- setup: temporary field definitions of the test class ---

file_put_contents( $strFieldsFile, json_encode( [
  'mixPayload' => [ 'nameDe' => 'Testpayload', 'element' => 'input', 'type' => 'text', 'mandatory' => true, 'minLength' => 3, 'position' => 100 ],
  'strSecret'  => [ 'nameDe' => 'Geheim', 'element' => 'input', 'type' => 'text', 'mandatory' => false, 'position' => 200 ]
] ) );

// --- viewSaveObject: create path with validation and hook ---

$_POST[ 'object' ] = json_encode( [ 'class' => 'PersistTestObject', 'id' => 'new', 'data' => new stdClass() ] );

Request::reset();
Request::setScope( 'viewSaveObject' );

$arrEnvelope = ( new PersistTestObject() )->viewSaveObject( crudMockController( 'viewSaveObject' ) );

check( count( $arrEnvelope ) === 1 && $arrEnvelope[ 0 ]->class === 'AppError' && $arrEnvelope[ 0 ]->data->message === 'Bitte Testpayload angeben.', 'viewSaveObject: Create ohne Pflichtfeld antwortet mit mandatory-Fehler' );

$_POST[ 'object' ] = json_encode( [ 'class' => 'PersistTestObject', 'id' => 'new', 'data' => [ 'mixPayload' => 'ab' ] ] );

Request::reset();
Request::setScope( 'viewSaveObject' );

$arrEnvelope = ( new PersistTestObject() )->viewSaveObject( crudMockController( 'viewSaveObject' ) );

check( count( $arrEnvelope ) === 1 && $arrEnvelope[ 0 ]->class === 'AppError' && str_contains( $arrEnvelope[ 0 ]->data->message, 'mindestens 3 Zeichen' ), 'viewSaveObject: Create unter minLength antwortet mit Regel-Fehler' );

$_POST[ 'object' ] = json_encode( [ 'class' => 'PersistTestObject', 'id' => 'new', 'data' => [ 'mixPayload' => 'Wert Eins', 'strSecret' => 'vertraulich' ] ] );

Request::reset();
Request::setScope( 'viewSaveObject' );

$arrEnvelope = ( new PersistTestObject() )->viewSaveObject( crudMockController( 'viewSaveObject' ) );

check( count( $arrEnvelope ) === 1 && $arrEnvelope[ 0 ]->class === 'PersistTestObject' && $arrEnvelope[ 0 ]->id !== 'new' && str_starts_with( $arrEnvelope[ 0 ]->id, 'PersistTestObject_' ), 'viewSaveObject: Create generiert die Server-Id (Blueprint-Id ersetzt)' );
check( $arrEnvelope[ 0 ]->data->mixPayload === 'Wert Eins', 'viewSaveObject: Create-Antwort trägt den gemergten Payload' );
check( ! isset( $arrEnvelope[ 0 ]->data->strSecret ), 'viewSaveObject: NOSERIALIZE-Property erscheint nicht im Antwort-Envelope' );

$strCreatedId = $arrEnvelope[ 0 ]->id;

check( BaseObject::existsObject( 'PersistTestObject', $strCreatedId ), 'viewSaveObject: Create persistiert unter der generierten Id' );

$objRawEntry = BaseObject::getRawObject( 'PersistTestObject', $strCreatedId );

check( $objRawEntry->mixPayload === 'Wert Eins', 'viewSaveObject: gemergter Payload steht in der Klassendatei' );
check( $objRawEntry->strSecret === '', 'viewSaveObject: NOSERIALIZE-Property wurde nicht aus dem Envelope gemergt (Forgery-Schutz)' );

// --- viewSaveObject: merge update with id immutability ---

$_POST[ 'object' ] = json_encode( [ 'class' => 'PersistTestObject', 'id' => $strCreatedId, 'data' => [ 'mixPayload' => 'Wert Zwei', 'id' => 'fälschung' ] ] );

Request::reset();
Request::setScope( 'viewSaveObject' );

$arrEnvelope = ( new PersistTestObject() )->viewSaveObject( crudMockController( 'viewSaveObject' ) );

check( count( $arrEnvelope ) === 1 && $arrEnvelope[ 0 ]->id === $strCreatedId, 'viewSaveObject: Update antwortet mit dem bestehenden Envelope' );

$objRawEntry = BaseObject::getRawObject( 'PersistTestObject', $strCreatedId );

check( $objRawEntry->mixPayload === 'Wert Zwei', 'viewSaveObject: Update übernimmt den gelieferten Payload (Merge)' );
$objRawObjects = BaseObject::loadFileDeCrypted( $strDataFile );

check( ! isset( $objRawObjects->fälschung ) && isset( $objRawObjects->{ $strCreatedId } ), 'viewSaveObject: id-Property im Update ist unveränderlich (kein Ghost-Eintrag)' );

// --- viewSaveObject: unknown id and onSave hook rejection ---

$_POST[ 'object' ] = json_encode( [ 'class' => 'PersistTestObject', 'id' => 'niemandExistiert', 'data' => [ 'mixPayload' => 'Wert Drei' ] ] );

Request::reset();
Request::setScope( 'viewSaveObject' );

$arrEnvelope = ( new PersistTestObject() )->viewSaveObject( crudMockController( 'viewSaveObject' ) );

check( count( $arrEnvelope ) === 1 && $arrEnvelope[ 0 ]->class === 'AppError' && $arrEnvelope[ 0 ]->data->message === 'Das zu speichernde Objekt existiert nicht.', 'viewSaveObject: Update einer unbekannten Id antwortet mit AppError' );

$_POST[ 'object' ] = json_encode( [ 'class' => 'PersistTestObject', 'id' => 'new', 'data' => [ 'mixPayload' => 'BLOCK' ] ] );

Request::reset();
Request::setScope( 'viewSaveObject' );

$arrEnvelope = ( new PersistTestObject() )->viewSaveObject( crudMockController( 'viewSaveObject' ) );

check( count( $arrEnvelope ) === 1 && $arrEnvelope[ 0 ]->class === 'AppError' && $arrEnvelope[ 0 ]->data->message === 'Der onSave-Hook hat den Save blockiert.' && $arrEnvelope[ 0 ]->data->source === 'onSave', 'viewSaveObject: ein string-Rückgabewert des onSave-Hooks blockiert mit AppError (Quelle onSave)' );
check( count( (array) BaseObject::loadFileDeCrypted( $strDataFile ) ) === 1, 'viewSaveObject: blockierter Save persistiert nichts' );

$_POST[ 'object' ] = json_encode( [ 'class' => 'PersistTestObject', 'id' => 'new', 'data' => [ 'mixPayload' => 'KEEP' ] ] );

Request::reset();
Request::setScope( 'viewSaveObject' );

$arrEnvelope = ( new PersistTestObject() )->viewSaveObject( crudMockController( 'viewSaveObject' ) );

$strKeepId = $arrEnvelope[ 0 ]->id;

check( count( $arrEnvelope ) === 1 && $arrEnvelope[ 0 ]->class === 'PersistTestObject', 'viewSaveObject: onSave ohne Blockierung persisted (KEEP-Objekt erzeugt)' );

// --- setup: reference element objects and field definitions ---

( new PersistTestElement( 'element-1' ) )->saveObject();
( new PersistTestElement( 'element-2' ) )->saveObject();

file_put_contents( $strRefFieldsFile, json_encode( [
  'mixPayload'  => [ 'nameDe' => 'Testpayload', 'element' => 'input', 'type' => 'text', 'mandatory' => true, 'minLength' => 3, 'position' => 100 ],
  'objElement'  => [ 'nameDe' => 'Element', 'class' => 'PersistTestElement', 'position' => 200 ],
  'objElements' => [ 'nameDe' => 'Elemente', 'class' => 'PersistTestElement', 'isCollection' => true, 'position' => 300 ]
] ) );

// --- viewSaveObject: reference descriptor merge (create) ---

$_POST[ 'object' ] = json_encode( [ 'class' => 'PersistTestReferenceObject', 'id' => 'new', 'data' => [
  'mixPayload'  => 'Referenz Eins',
  'objElement'  => [ 'propertyType' => 'object', 'property' => 'objElement', 'class' => 'PersistTestElement', 'value' => 'element-1' ],
  'objElements' => [ 'propertyType' => 'collection', 'property' => 'objElements', 'class' => 'PersistTestElement', 'value' => [ 'element-1', 'element-2' ] ]
] ] );

Request::reset();
Request::setScope( 'viewSaveObject' );

$arrEnvelope = ( new PersistTestReferenceObject() )->viewSaveObject( crudMockController( 'viewSaveObject' ) );

$strRefId = $arrEnvelope[ 3 ]->id ?? null;

check( count( $arrEnvelope ) === 4 && $arrEnvelope[ 3 ]->class === 'PersistTestReferenceObject' && str_starts_with( (string) $strRefId, 'PersistTestReferenceObject_' ), 'viewSaveObject: Create mit gültigen Referenz-Deskriptoren persistiert unter der generierten Id' );
check( $arrEnvelope[ 0 ]->class === 'PersistTestElement' && $arrEnvelope[ 0 ]->id === 'element-1' && $arrEnvelope[ 2 ]->id === 'element-2', 'viewSaveObject: die Antwort lädt die Referenz-Objekte als Sub-Envelopes (Kollektor-Konvention)' );
check( $arrEnvelope[ 3 ]->data->objElement->propertyType === 'object' && $arrEnvelope[ 3 ]->data->objElement->value === 'element-1', 'viewSaveObject: die Antwort trägt den Objekt-Referenz-Deskriptor (Kollektor-Format)' );
check( $arrEnvelope[ 3 ]->data->objElements->propertyType === 'collection' && $arrEnvelope[ 3 ]->data->objElements->value === [ 'element-1', 'element-2' ], 'viewSaveObject: die Antwort trägt den Collection-Referenz-Deskriptor (Kollektor-Format)' );

$objRawEntry = BaseObject::getRawObject( 'PersistTestReferenceObject', $strRefId );

check( $objRawEntry->objElement === 'element-1', 'viewSaveObject: die Objekt-Referenz persistiert als id' );
check( $objRawEntry->objElements === [ 'element-1', 'element-2' ], 'viewSaveObject: die Collection-Referenz persistiert als id-Liste' );

$objReloaded = new PersistTestReferenceObject( $strRefId );

check( $objReloaded->get( 'objElement' )->id() === 'element-1', 'fillObject: die persistierte Objekt-Referenz lädt als Instanz' );
check( $objReloaded->get( 'objElements' )->idArray() === [ 'element-1', 'element-2' ], 'fillObject: die persistierte Collection-Referenz lädt als Element-Instanzen' );

// --- viewSaveObject: blueprint id in the data contract is ignored at a create ---

$_POST[ 'object' ] = json_encode( [ 'class' => 'PersistTestReferenceObject', 'id' => 'new', 'data' => [ 'mixPayload' => 'Blueprint Id', 'id' => 'new' ] ] );

Request::reset();
Request::setScope( 'viewSaveObject' );

$arrEnvelope = ( new PersistTestReferenceObject() )->viewSaveObject( crudMockController( 'viewSaveObject' ) );

check( count( $arrEnvelope ) === 1 && $arrEnvelope[ 0 ]->id !== 'new' && str_starts_with( $arrEnvelope[ 0 ]->id, 'PersistTestReferenceObject_' ), 'viewSaveObject: die Blueprint-Id im data-Vertrag wird ignoriert (Server-Id bleibt generiert)' );

// --- viewSaveObject: reference descriptor rejections ---

$_POST[ 'object' ] = json_encode( [ 'class' => 'PersistTestReferenceObject', 'id' => $strRefId, 'data' => [ 'objElement' => 'element-1' ] ] );

Request::reset();
Request::setScope( 'viewSaveObject' );

$arrEnvelope = ( new PersistTestReferenceObject() )->viewSaveObject( crudMockController( 'viewSaveObject' ) );

check( count( $arrEnvelope ) === 1 && $arrEnvelope[ 0 ]->class === 'AppError' && $arrEnvelope[ 0 ]->data->message === 'Das Referenz-Format des Feldes objElement ist ungültig.', 'viewSaveObject: ein flacher Wert statt Deskriptor antwortet mit Format-Fehler' );

$_POST[ 'object' ] = json_encode( [ 'class' => 'PersistTestReferenceObject', 'id' => $strRefId, 'data' => [ 'objElement' => [ 'propertyType' => 'object', 'property' => 'objElement', 'class' => 'PersistTestOtherClass', 'value' => 'element-1' ] ] ] );

Request::reset();
Request::setScope( 'viewSaveObject' );

$arrEnvelope = ( new PersistTestReferenceObject() )->viewSaveObject( crudMockController( 'viewSaveObject' ) );

check( count( $arrEnvelope ) === 1 && $arrEnvelope[ 0 ]->class === 'AppError' && $arrEnvelope[ 0 ]->data->message === 'Das Feld objElement referenziert eine nicht deklarierte Klasse.', 'viewSaveObject: ein Objekt-Deskriptor mit fremder Klasse antwortet mit Klassen-Fehler' );

$_POST[ 'object' ] = json_encode( [ 'class' => 'PersistTestReferenceObject', 'id' => $strRefId, 'data' => [ 'objElement' => [ 'propertyType' => 'collection', 'property' => 'objElement', 'class' => 'PersistTestElement', 'value' => [ 'element-1' ] ] ] ] );

Request::reset();
Request::setScope( 'viewSaveObject' );

$arrEnvelope = ( new PersistTestReferenceObject() )->viewSaveObject( crudMockController( 'viewSaveObject' ) );

check( count( $arrEnvelope ) === 1 && $arrEnvelope[ 0 ]->class === 'AppError' && $arrEnvelope[ 0 ]->data->message === 'Das Feld objElement erwartet eine Objekt-Referenz.', 'viewSaveObject: ein Collection-Deskriptor auf einer Objekt-Referenz antwortet mit Typ-Fehler' );

$_POST[ 'object' ] = json_encode( [ 'class' => 'PersistTestReferenceObject', 'id' => $strRefId, 'data' => [ 'objElement' => [ 'propertyType' => 'object', 'property' => 'objElement', 'class' => 'PersistTestElement', 'value' => 'element-9' ] ] ] );

Request::reset();
Request::setScope( 'viewSaveObject' );

$arrEnvelope = ( new PersistTestReferenceObject() )->viewSaveObject( crudMockController( 'viewSaveObject' ) );

check( count( $arrEnvelope ) === 1 && $arrEnvelope[ 0 ]->class === 'AppError' && $arrEnvelope[ 0 ]->data->message === 'Das referenzierte Objekt des Feldes objElement existiert nicht.', 'viewSaveObject: eine Objekt-Referenz auf eine unbekannte Id antwortet mit Existenz-Fehler' );

$_POST[ 'object' ] = json_encode( [ 'class' => 'PersistTestReferenceObject', 'id' => $strRefId, 'data' => [ 'objElements' => [ 'propertyType' => 'collection', 'property' => 'objElements', 'class' => 'PersistTestElement', 'value' => 'element-1' ] ] ] );

Request::reset();
Request::setScope( 'viewSaveObject' );

$arrEnvelope = ( new PersistTestReferenceObject() )->viewSaveObject( crudMockController( 'viewSaveObject' ) );

check( count( $arrEnvelope ) === 1 && $arrEnvelope[ 0 ]->class === 'AppError' && $arrEnvelope[ 0 ]->data->message === 'Das Referenz-Format des Feldes objElements ist ungültig.', 'viewSaveObject: eine Collection-Referenz ohne id-Liste antwortet mit Format-Fehler' );

$_POST[ 'object' ] = json_encode( [ 'class' => 'PersistTestReferenceObject', 'id' => $strRefId, 'data' => [ 'objElements' => [ 'propertyType' => 'object', 'property' => 'objElements', 'class' => 'PersistTestElement', 'value' => [ 'element-1' ] ] ] ] );

Request::reset();
Request::setScope( 'viewSaveObject' );

$arrEnvelope = ( new PersistTestReferenceObject() )->viewSaveObject( crudMockController( 'viewSaveObject' ) );

check( count( $arrEnvelope ) === 1 && $arrEnvelope[ 0 ]->class === 'AppError' && $arrEnvelope[ 0 ]->data->message === 'Das Feld objElements erwartet eine Sammlungs-Referenz.', 'viewSaveObject: ein Objekt-Deskriptor auf einer Collection-Referenz antwortet mit Typ-Fehler' );

$_POST[ 'object' ] = json_encode( [ 'class' => 'PersistTestReferenceObject', 'id' => $strRefId, 'data' => [ 'objElements' => [ 'propertyType' => 'collection', 'property' => 'objElements', 'class' => 'PersistTestElement', 'value' => [ 'element-1', 'element-9' ] ] ] ] );

Request::reset();
Request::setScope( 'viewSaveObject' );

$arrEnvelope = ( new PersistTestReferenceObject() )->viewSaveObject( crudMockController( 'viewSaveObject' ) );

check( count( $arrEnvelope ) === 1 && $arrEnvelope[ 0 ]->class === 'AppError' && $arrEnvelope[ 0 ]->data->message === 'Ein referenziertes Objekt des Feldes objElements existiert nicht.', 'viewSaveObject: eine Collection-Referenz mit unbekanntem Element antwortet mit Existenz-Fehler' );

check( BaseObject::getRawObject( 'PersistTestReferenceObject', $strRefId )->objElement === 'element-1', 'viewSaveObject: abgelehnte Referenz-Updates persistieren nichts (Bestand bleibt)' );

// --- viewSaveObject: empty reference values clear the property ---

$_POST[ 'object' ] = json_encode( [ 'class' => 'PersistTestReferenceObject', 'id' => $strRefId, 'data' => [ 'objElement' => [ 'propertyType' => 'object', 'property' => 'objElement', 'class' => 'PersistTestElement', 'value' => null ] ] ] );

Request::reset();
Request::setScope( 'viewSaveObject' );

$arrEnvelope = ( new PersistTestReferenceObject() )->viewSaveObject( crudMockController( 'viewSaveObject' ) );

check( count( $arrEnvelope ) === 3 && $arrEnvelope[ 2 ]->class === 'PersistTestReferenceObject', 'viewSaveObject: ein Objekt-Deskriptor mit leerem value wird akzeptiert' );

$objRawEntry = BaseObject::getRawObject( 'PersistTestReferenceObject', $strRefId );

check( ! isset( $objRawEntry->objElement ), 'viewSaveObject: ein leerer Objekt-Deskriptor entfernt die Referenz aus der Klassendatei' );
check( $objRawEntry->objElements === [ 'element-1', 'element-2' ], 'viewSaveObject: nicht gelieferte Referenzen bleiben unangetastet (Merge-Semantik)' );

$_POST[ 'object' ] = json_encode( [ 'class' => 'PersistTestReferenceObject', 'id' => $strRefId, 'data' => [ 'objElements' => [ 'propertyType' => 'collection', 'property' => 'objElements', 'class' => 'PersistTestElement', 'value' => [] ] ] ] );

Request::reset();
Request::setScope( 'viewSaveObject' );

$arrEnvelope = ( new PersistTestReferenceObject() )->viewSaveObject( crudMockController( 'viewSaveObject' ) );

check( count( $arrEnvelope ) === 1 && $arrEnvelope[ 0 ]->class === 'PersistTestReferenceObject', 'viewSaveObject: ein Collection-Deskriptor mit leerer id-Liste wird akzeptiert' );

$objRawEntry = BaseObject::getRawObject( 'PersistTestReferenceObject', $strRefId );

check( $objRawEntry->objElements === [], 'viewSaveObject: eine leere Collection-Referenz persistiert als leere id-Liste' );

// --- viewDeleteObject: whitelist silence, unknown id, code-level hook ---

Request::reset();
Request::setScope( 'viewDeleteObject' );

$_GET[ 'class' ] = 'PersistTestObject';
$_GET[ 'id' ]    = $strKeepId;

$arrEnvelope = ( new PersistTestObject() )->viewDeleteObject( crudMockController( 'viewDeleteObject' ) );

check( count( $arrEnvelope ) === 0, 'viewDeleteObject: Klasse außerhalb der Param-Whitelist antwortet still-leer (params.json-Gate)' );

Request::reset();
Request::setScope( 'viewDeleteObject' );

$_GET[ 'class' ] = 'User';
$_GET[ 'id' ]    = 'niemand@local.test';

$arrEnvelope = ( new PersistTestObject() )->viewDeleteObject( crudMockController( 'viewDeleteObject' ) );

check( count( $arrEnvelope ) === 1 && $arrEnvelope[ 0 ]->class === 'AppError' && $arrEnvelope[ 0 ]->data->message === 'Das zu löschende Objekt existiert nicht.', 'viewDeleteObject: unbekannte Id antwortet mit AppError' );

$objKeep = new PersistTestObject( $strKeepId );

check( $objKeep->onDelete( crudMockController( 'viewDeleteObject' ) ) === 'Der onDelete-Hook hat das Löschen blockiert.', 'onDelete-Hook: string-Rückgabe steht als Blockier-Meldung (Routing-Spiegel des onSave-Pfads)' );

$objKeep->deleteObject();

check( BaseObject::existsObject( 'PersistTestObject', $strKeepId ) === false, 'deleteObject: KEEP-Objekt im Suite-Fluss direkt entfernt (Hooks sind ein Routen-Niveau, deleteObject bleibt rein)' );

// --- User positive cases: declared id field, password filter, delete route ---

$_POST[ 'object' ] = json_encode( [ 'class' => 'User', 'id' => 'new', 'data' => [ 'id' => $strTestUserId, 'password' => 'forge123' ] ] );

Request::reset();
Request::setScope( 'viewSaveObject' );

$arrEnvelope = ( new PersistTestObject() )->viewSaveObject( crudMockController( 'viewSaveObject' ) );

check( count( $arrEnvelope ) === 1 && $arrEnvelope[ 0 ]->class === 'User' && $arrEnvelope[ 0 ]->id === $strTestUserId, 'viewSaveObject: User-Create über den deklarierten id-Feldvertrag' );

$objRawUser = BaseObject::getRawObject( 'User', $strTestUserId );

check( ! isset( $objRawUser->password ), 'viewSaveObject: Passwort-Felder werden im generischen Pfad übersprungen (Hashing bleibt den spezialisierten Actions)' );

Request::reset();
Request::setScope( 'viewDeleteObject' );

$_GET[ 'class' ] = 'User';
$_GET[ 'id' ]    = $strTestUserId;

$arrEnvelope = ( new PersistTestObject() )->viewDeleteObject( crudMockController( 'viewDeleteObject' ) );

check( count( $arrEnvelope ) === 1 && $arrEnvelope[ 0 ]->class === 'User' && $arrEnvelope[ 0 ]->id === $strTestUserId, 'viewDeleteObject: Erfolg liefert den Envelope des gelöschten Objekts' );
check( BaseObject::existsObject( 'User', $strTestUserId ) === false, 'viewDeleteObject: der über die Route gelöschte Eintrag ist weg' );

// --- classPermissions: owner account, whitelist silence, owner pseudo role, forgery ---

// the owner entry owns itself, the victim entry belongs to the owner user —
// both shapes feed the owner route checks below

$objOwnerEntry           = new stdClass();
$objOwnerEntry->id       = $strOwnerUserId;
$objOwnerEntry->class    = 'User';
$objOwnerEntry->password = BaseObject::enCrypteOnly( 'forge123' );
$objOwnerEntry->roleId   = 'administrator';
$objOwnerEntry->ownerId  = $strOwnerUserId;

BaseObject::insertObject( 'User', $strOwnerUserId, $objOwnerEntry );

$objVictimEntry           = new stdClass();
$objVictimEntry->id       = $strVictimUserId;
$objVictimEntry->class    = 'User';
$objVictimEntry->password = BaseObject::enCrypteOnly( 'forge123' );
$objVictimEntry->roleId   = 'user';
$objVictimEntry->ownerId  = $strOwnerUserId;

BaseObject::insertObject( 'User', $strVictimUserId, $objVictimEntry );

check( BaseObject::existsObject( 'User', $strOwnerUserId ), 'classPermissions: der Owner-Test-Account ist angelegt' );
check( BaseObject::existsObject( 'User', $strVictimUserId ), 'classPermissions: der Victim-Test-Account ist angelegt' );

$objController = mockController( 'viewSaveObject', $strOwnerUserId );

setMockClassPermissions( $objController, [ 'User' => [ 'administrator' ] ] );

$_POST[ 'object' ] = json_encode( [ 'class' => 'PersistTestObject', 'id' => 'new', 'data' => [ 'mixPayload' => 'Perm Eins' ] ] );

Request::reset();
Request::setScope( 'viewSaveObject' );

$arrEnvelope = ( new PersistTestObject() )->viewSaveObject( $objController );

check( count( $arrEnvelope ) === 0, 'classPermissions: eine undeklarierte Klasse antwortet beim Speichern still-leer (Whitelist)' );

$objController = mockController( 'viewSaveObject', $strOwnerUserId );

setMockClassPermissions( $objController, [ 'PersistTestObject' => [ 'administrator', 'owner' ] ] );

$_POST[ 'object' ] = json_encode( [ 'class' => 'PersistTestObject', 'id' => 'new', 'data' => [ 'mixPayload' => 'Perm Eigen' ] ] );

Request::reset();
Request::setScope( 'viewSaveObject' );

$arrEnvelope = ( new PersistTestObject() )->viewSaveObject( $objController );

$strOwnedId = $arrEnvelope[ 0 ]->id ?? null;

check( count( $arrEnvelope ) === 1 && $arrEnvelope[ 0 ]->class === 'PersistTestObject', 'classPermissions: Create mit deklarierter Rolle persistiert' );
check( $arrEnvelope[ 0 ]->data->ownerId === $strOwnerUserId, 'classPermissions: der Create-Envelope trägt die Owner-Id des angemeldeten Users' );

$objRawEntry = BaseObject::getRawObject( 'PersistTestObject', $strOwnedId );

check( $objRawEntry->ownerId === $strOwnerUserId, 'classPermissions: die Owner-Id steht im Persistenzeintrag' );

$objController = mockController( 'viewSaveObject', $strOwnerUserId );

setMockClassPermissions( $objController, [ 'PersistTestObject' => [ 'owner' ] ] );

$_POST[ 'object' ] = json_encode( [ 'class' => 'PersistTestObject', 'id' => $strOwnedId, 'data' => [ 'mixPayload' => 'Perm Eigen Zwei' ] ] );

Request::reset();
Request::setScope( 'viewSaveObject' );

$arrEnvelope = ( new PersistTestObject() )->viewSaveObject( $objController );

check( count( $arrEnvelope ) === 1 && $arrEnvelope[ 0 ]->id === $strOwnedId, 'classPermissions: der Owner darf sein Objekt über die Pseudo-Rolle owner ändern' );

$objController = mockController( 'viewSaveObject' );

setMockClassPermissions( $objController, [ 'PersistTestObject' => [ 'owner' ] ] );

$_POST[ 'object' ] = json_encode( [ 'class' => 'PersistTestObject', 'id' => $strOwnedId, 'data' => [ 'mixPayload' => 'Perm Hack' ] ] );

Request::reset();
Request::setScope( 'viewSaveObject' );

$arrEnvelope = ( new PersistTestObject() )->viewSaveObject( $objController );

check( count( $arrEnvelope ) === 1 && $arrEnvelope[ 0 ]->class === 'AppError' && $arrEnvelope[ 0 ]->data->message === 'Keine Berechtigung für dieses Objekt.' && $arrEnvelope[ 0 ]->data->source === 'viewSaveObject', 'classPermissions: ein fremder User erhält die Berechtigungs-Ablehnung (Update)' );
check( BaseObject::getRawObject( 'PersistTestObject', $strOwnedId )->mixPayload === 'Perm Eigen Zwei', 'classPermissions: abgelehntes Update persistiert nichts (Bestand bleibt)' );

$objController = mockController( 'viewSaveObject', $strOwnerUserId );

setMockClassPermissions( $objController, [ 'PersistTestObject' => [ 'owner' ] ] );

$_POST[ 'object' ] = json_encode( [ 'class' => 'PersistTestObject', 'id' => 'new', 'data' => [ 'mixPayload' => 'Perm Neu' ] ] );

Request::reset();
Request::setScope( 'viewSaveObject' );

$arrEnvelope = ( new PersistTestObject() )->viewSaveObject( $objController );

check( count( $arrEnvelope ) === 1 && $arrEnvelope[ 0 ]->class === 'AppError' && $arrEnvelope[ 0 ]->data->message === 'Keine Berechtigung für dieses Objekt.', 'classPermissions: ein Create wird nie über die Pseudo-Rolle owner erlaubt (kein Owner ohne Objekt)' );

$objOrphanEntry             = new stdClass();
$objOrphanEntry->class      = 'PersistTestObject';
$objOrphanEntry->mixPayload = 'Perm Ohne';

BaseObject::insertObject( 'PersistTestObject', 'permOrphan-1', $objOrphanEntry );

$objController = mockController( 'viewSaveObject', $strOwnerUserId );

setMockClassPermissions( $objController, [ 'PersistTestObject' => [ 'owner' ] ] );

$_POST[ 'object' ] = json_encode( [ 'class' => 'PersistTestObject', 'id' => 'permOrphan-1', 'data' => [ 'mixPayload' => 'Perm Forgery', 'ownerId' => $strOwnerUserId ] ] );

Request::reset();
Request::setScope( 'viewSaveObject' );

$arrEnvelope = ( new PersistTestObject() )->viewSaveObject( $objController );

check( count( $arrEnvelope ) === 1 && $arrEnvelope[ 0 ]->class === 'AppError' && $arrEnvelope[ 0 ]->data->message === 'Keine Berechtigung für dieses Objekt.', 'classPermissions: ein Bestandsobjekt ohne ownerId wird abgewiesen und ein gelieferter ownerId bleibt wirkungslos' );

$objRawEntry = BaseObject::getRawObject( 'PersistTestObject', 'permOrphan-1' );

check( ! isset( $objRawEntry->ownerId ) && $objRawEntry->mixPayload === 'Perm Ohne', 'classPermissions: der Forgery-Versuch schreibt weder Owner-Id noch Payload (kein Feldvertrag)' );

$objController = mockController( 'viewSaveObject' );

setMockClassPermissions( $objController, [ 'PersistTestObject' => [ 'guest' ] ] );

$_POST[ 'object' ] = json_encode( [ 'class' => 'PersistTestObject', 'id' => 'new', 'data' => [ 'mixPayload' => 'Perm Fremd' ] ] );

Request::reset();
Request::setScope( 'viewSaveObject' );

$arrEnvelope = ( new PersistTestObject() )->viewSaveObject( $objController );

$strForeignId = $arrEnvelope[ 0 ]->id ?? null;

check( count( $arrEnvelope ) === 1 && $arrEnvelope[ 0 ]->class === 'PersistTestObject', 'classPermissions: der Gast-Mock erstellt sein Objekt (Owner = Gast-Id)' );

// --- classPermissions: the load route (owner allowed, foreign and undeclared hidden) ---

$objController = mockController( 'viewLoadObject', $strOwnerUserId );

setMockClassPermissions( $objController, [ 'User' => [ 'owner' ] ] );

Request::reset();
Request::setScope( 'viewLoadObject' );

$_GET[ 'class' ] = 'User';
$_GET[ 'id' ]    = $strVictimUserId;

$arrEnvelope = ( new PersistTestObject() )->viewLoadObject( $objController );

check( count( $arrEnvelope ) === 1 && $arrEnvelope[ 0 ]->id === $strVictimUserId, 'classPermissions: der Owner darf sein Objekt laden' );

$objController = mockController( 'viewLoadObject' );

setMockClassPermissions( $objController, [ 'User' => [ 'owner' ] ] );

Request::reset();
Request::setScope( 'viewLoadObject' );

$_GET[ 'class' ] = 'User';
$_GET[ 'id' ]    = $strVictimUserId;

$arrEnvelope = ( new PersistTestObject() )->viewLoadObject( $objController );

check( count( $arrEnvelope ) === 0, 'classPermissions: das fremde Objekt lädt still-leer (Tarnung als nicht vorhanden)' );

$objController = mockController( 'viewLoadObject', $strOwnerUserId );

setMockClassPermissions( $objController, [ 'PersistTestObject' => [ 'owner' ] ] );

Request::reset();
Request::setScope( 'viewLoadObject' );

$_GET[ 'class' ] = 'User';
$_GET[ 'id' ]    = $strVictimUserId;

$arrEnvelope = ( new PersistTestObject() )->viewLoadObject( $objController );

check( count( $arrEnvelope ) === 0, 'classPermissions: eine undeklarierte Klasse antwortet beim Laden still-leer' );

// --- classPermissions: the delete route (owner allowed, foreign denied) ---

$objController = mockController( 'viewDeleteObject', $strOwnerUserId );

setMockClassPermissions( $objController, [ 'User' => [ 'owner' ] ] );

Request::reset();
Request::setScope( 'viewDeleteObject' );

$_GET[ 'class' ] = 'User';
$_GET[ 'id' ]    = $strVictimUserId;

$arrEnvelope = ( new PersistTestObject() )->viewDeleteObject( $objController );

check( count( $arrEnvelope ) === 1 && $arrEnvelope[ 0 ]->id === $strVictimUserId, 'classPermissions: der Owner darf sein Objekt löschen' );
check( BaseObject::existsObject( 'User', $strVictimUserId ) === false, 'classPermissions: das gelöschte Owner-Objekt ist weg' );

$objController = mockController( 'viewDeleteObject' );

setMockClassPermissions( $objController, [ 'User' => [ 'owner' ] ] );

Request::reset();
Request::setScope( 'viewDeleteObject' );

$_GET[ 'class' ] = 'User';
$_GET[ 'id' ]    = $strOwnerUserId;

$arrEnvelope = ( new PersistTestObject() )->viewDeleteObject( $objController );

check( count( $arrEnvelope ) === 1 && $arrEnvelope[ 0 ]->class === 'AppError' && $arrEnvelope[ 0 ]->data->message === 'Keine Berechtigung für dieses Objekt.' && $arrEnvelope[ 0 ]->data->source === 'viewDeleteObject', 'classPermissions: ein fremder User erhält die Berechtigungs-Ablehnung (Delete)' );
check( BaseObject::existsObject( 'User', $strOwnerUserId ), 'classPermissions: das abgelehnt gelöschte Objekt besteht weiter' );

// --- cleanup: remove the remaining permission test objects ---

( new PersistTestObject( $strOwnedId ) )->deleteObject();
( new PersistTestObject( $strForeignId ) )->deleteObject();
( new PersistTestObject( 'permOrphan-1' ) )->deleteObject();

Request::reset();

echo 'Alle Tests bestanden.' . PHP_EOL;

// EOF
