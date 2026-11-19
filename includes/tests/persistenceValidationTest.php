<?php

declare( strict_types = 1 );

/**
 * This suite is the regression test for the persistence validation:
 * saveObject() and insertObject() validate the serialized entry against
 * the field definitions (fields/{klasseKlein}.json) before every write —
 * a failed rule throws the PersistenceValidationException and leaves the
 * class json file untouched ("write once, validate everywhere"). The
 * suite proves the create case (mandatory fields must arrive), the rule
 * cascade on the entry (minLength, select options, mail), the reference
 * existence check for object and collection references, the password
 * skip (the entry carries the hash, its rules never run) and the update
 * semantics (absent fields pass, only creates enforce mandatory). The
 * suite writes only into its own class json files derived from the test
 * class names (validationTestObject.json, validationTestTarget.json) and
 * lays the field definition files temporarily into the fields directory
 * — both are removed on every suite end by the shutdown handler, the
 * local production data files are never touched.
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.21
 * @since     2026-11-18
 *
 * @example   php includes/tests/persistenceValidationTest.php
 *
 */

require_once ( __DIR__ . '/bootstrap.php' );

$strObjectFieldFile    = BaseObject::FILEPATHFIELDS . 'validationTestObject.json';
$strTargetFieldFile    = BaseObject::FILEPATHFIELDS . 'validationTestTarget.json';
$strObjectsFile        = BaseObject::FILEPATHJSON . 'data/validationTestObject.json';
$strTargetsFile        = BaseObject::FILEPATHJSON . 'data/validationTestTarget.json';

/**
 * This shutdown handler removes the field definition files and the class
 * json files of the test classes including their lock and temp side
 * files — it runs on every suite end, including a FEHLER exit of check().
 *
 * @return    void
 *
 */
register_shutdown_function( function() use ( $strObjectFieldFile, $strTargetFieldFile, $strObjectsFile, $strTargetsFile ) : void {
  foreach( [ $strObjectFieldFile, $strTargetFieldFile, $strObjectsFile, $strTargetsFile ] as $strFile ) {
    @unlink( $strFile );
    @unlink( $strFile . '.lock' );
    @unlink( $strFile . '.tmp' );
  }

  return;
} );

file_put_contents( $strObjectFieldFile, '{
  "id":       { "nameDe": "ID",    "element": "input",  "type": "text", "mandatory": true, "minLength": 3 },
  "strName":  { "nameDe": "Name",  "element": "input",  "type": "text", "mandatory": true, "minLength": 3 },
  "strMail":  { "nameDe": "Mail",  "element": "input",  "type": "text", "mail": true },
  "strRole":  { "nameDe": "Rolle", "element": "select", "type": "text", "options": { "alpha": "Alpha", "beta": "Beta" }, "roleIds": [ "administrator" ] },
  "strHash":  { "nameDe": "Hash",  "element": "input",  "type": "password", "mandatory": true, "minLength": 200 },
  "target":   { "nameDe": "Ziel",  "class": "ValidationTestTarget" },
  "targets":  { "nameDe": "Ziele", "class": "ValidationTestTarget", "isCollection": true }
}' );

file_put_contents( $strTargetFieldFile, '{
  "id": { "nameDe": "ID", "element": "input", "type": "text", "mandatory": true, "minLength": 3 }
}' );

/**
 * Local test target: a minimal BaseObject subclass with only the id
 * contract — the reference class of the object and collection fields.
 *
 */
class ValidationTestTarget extends BaseObject {
}

/**
 * Local test object: a minimal BaseObject subclass carrying one payload
 * property per validated rule (mandatory text with minLength, mail,
 * select, password) — the field definitions live in the temporary field
 * definition file of the suite.
 *
 */
class ValidationTestObject extends BaseObject {

  /**
   * The name payload of the test object (mandatory, minLength 3).
   *
   * @var       string
   *
   */
  protected string $strName = '';

  /**
   * The mail payload of the test object (mail rule).
   *
   * @var       string
   *
   */
  protected string $strMail = '';

  /**
   * The select payload of the test object (options alpha, beta).
   *
   * @var       string
   *
   */
  protected string $strRole = '';

  /**
   * The password payload of the test object (skipped by the persistence
   * validation — the entry carries the hash).
   *
   * @var       string
   *
   */
  protected string $strHash = '';

  /**
   * This method sets the name payload of the test object.
   *
   * @param     string    $strValue   The payload value
   * @return    void
   *
   */
  public function setName( string $strValue ) : void {
    $this->strName = $strValue;

    return;
  }

  /**
   * This method unsets the name payload of the test object — the absent
   * state serializeObjectForSave() drops from the entry.
   *
   * @return    void
   *
   */
  public function clearName() : void {
    unset( $this->strName );

    return;
  }

  /**
   * This method reads the name payload of the test object.
   *
   * @return    string    The payload value
   *
   */
  public function getName() : string {
    return $this->strName;
  }

  /**
   * This method sets the mail payload of the test object.
   *
   * @param     string    $strValue   The payload value
   * @return    void
   *
   */
  public function setMail( string $strValue ) : void {
    $this->strMail = $strValue;

    return;
  }

  /**
   * This method reads the mail payload of the test object.
   *
   * @return    string    The payload value
   *
   */
  public function getMail() : string {
    return $this->strMail;
  }

  /**
   * This method sets the select payload of the test object.
   *
   * @param     string    $strValue   The payload value
   * @return    void
   *
   */
  public function setRole( string $strValue ) : void {
    $this->strRole = $strValue;

    return;
  }

  /**
   * This method reads the select payload of the test object.
   *
   * @return    string    The payload value
   *
   */
  public function getRole() : string {
    return $this->strRole;
  }

  /**
   * This method sets the password payload of the test object.
   *
   * @param     string    $strValue   The payload value
   * @return    void
   *
   */
  public function setHash( string $strValue ) : void {
    $this->strHash = $strValue;

    return;
  }

  /**
   * This method reads the password payload of the test object.
   *
   * @return    string    The payload value
   *
   */
  public function getHash() : string {
    return $this->strHash;
  }

}

// --- insertObject validates the entry: valid reference target inserts ---

$objEntry       = new stdClass();
$objEntry->id   = 'tgt1';
$objEntry->class = 'ValidationTestTarget';

check( BaseObject::insertObject( 'ValidationTestTarget', 'tgt1', $objEntry ), 'insertObject: gültiger Entry persistiert' );

// --- insertObject create case: a missing mandatory field throws ---

$objEntry       = new stdClass();
$objEntry->id   = 'obj1';
$objEntry->class = 'ValidationTestObject';

$boolThrew = false;
$arrCaught = [];

try {
  BaseObject::insertObject( 'ValidationTestObject', 'obj1', $objEntry );
} catch( PersistenceValidationException $objException ) {
  $boolThrew = true;
  $arrCaught = $objException->fieldErrors();
}

check( $boolThrew, 'insertObject: fehlendes Pflichtfeld wirft die PersistenceValidationException (create case)' );
check( isset( $arrCaught[ 'strName' ] ), 'insertObject: Feldfehler des Pflichtfelds ist im fieldErrors-Array' );
check( ! BaseObject::existsObject( 'ValidationTestObject', 'obj1' ), 'insertObject: abgelehnter Entry ist nicht persistiert' );

// --- insertObject: the rule cascade runs on the entry (minLength, mail, select) ---

$objEntry = new stdClass();
$objEntry->id = 'obj1';
$objEntry->class = 'ValidationTestObject';
$objEntry->strName = 'ab';

$boolThrew = false;

try {
  BaseObject::insertObject( 'ValidationTestObject', 'obj1', $objEntry );
} catch( PersistenceValidationException ) {
  $boolThrew = true;
}

check( $boolThrew, 'insertObject: minLength-Verstoß wirft die PersistenceValidationException' );

$objEntry = new stdClass();
$objEntry->id = 'obj1';
$objEntry->class = 'ValidationTestObject';
$objEntry->strName = 'abc';
$objEntry->strMail = 'not-a-mail';

$boolThrew = false;

try {
  BaseObject::insertObject( 'ValidationTestObject', 'obj1', $objEntry );
} catch( PersistenceValidationException ) {
  $boolThrew = true;
}

check( $boolThrew, 'insertObject: mail-Verstoß wirft die PersistenceValidationException' );

$objEntry = new stdClass();
$objEntry->id = 'obj1';
$objEntry->class = 'ValidationTestObject';
$objEntry->strName = 'abc';
$objEntry->strRole = 'gamma';

$boolThrew = false;

try {
  BaseObject::insertObject( 'ValidationTestObject', 'obj1', $objEntry );
} catch( PersistenceValidationException ) {
  $boolThrew = true;
}

check( $boolThrew, 'insertObject: select-Verstoß wirft die PersistenceValidationException' );

// --- insertObject: fields behind roleIds are exempt from the mandatory duty ---

$objEntry = new stdClass();
$objEntry->id = 'obj2';
$objEntry->class = 'ValidationTestObject';
$objEntry->strName = 'abc';

check( BaseObject::insertObject( 'ValidationTestObject', 'obj2', $objEntry ), 'insertObject: roleIds-Feld ohne Wert ist von der Mandatory-Pflicht ausgenommen (create case)' );

// --- insertObject: reference ids are existence-checked (object + collection) ---

$objEntry = new stdClass();
$objEntry->id = 'obj1';
$objEntry->class = 'ValidationTestObject';
$objEntry->strName = 'abc';
$objEntry->target = 'doesNotExist';

$boolThrew = false;
$arrCaught = [];

try {
  BaseObject::insertObject( 'ValidationTestObject', 'obj1', $objEntry );
} catch( PersistenceValidationException $objException ) {
  $boolThrew = true;
  $arrCaught = $objException->fieldErrors();
}

check( $boolThrew, 'insertObject: Objekt-Referenz auf fehlende ID wirft die PersistenceValidationException' );
check( isset( $arrCaught[ 'target' ] ) && strpos( $arrCaught[ 'target' ], 'doesNotExist' ) !== false, 'insertObject: Referenzfehler nennt das Feld und die fehlende ID' );

$objEntry = new stdClass();
$objEntry->id = 'obj1';
$objEntry->class = 'ValidationTestObject';
$objEntry->strName = 'abc';
$objEntry->targets = [ 'tgt1', 'nope' ];

$boolThrew = false;

try {
  BaseObject::insertObject( 'ValidationTestObject', 'obj1', $objEntry );
} catch( PersistenceValidationException ) {
  $boolThrew = true;
}

check( $boolThrew, 'insertObject: Collection-Referenz mit fehlender ID wirft die PersistenceValidationException' );

// --- insertObject: a valid entry with references persists ---

$objEntry = new stdClass();
$objEntry->id = 'obj1';
$objEntry->class = 'ValidationTestObject';
$objEntry->strName = 'alpha name';
$objEntry->strMail = 'alpha@test.example';
$objEntry->strRole = 'alpha';
$objEntry->target = 'tgt1';
$objEntry->targets = [ 'tgt1' ];

check( BaseObject::insertObject( 'ValidationTestObject', 'obj1', $objEntry ), 'insertObject: gültiger Entry mit Referenzen persistiert' );
check( BaseObject::existsObject( 'ValidationTestObject', 'obj1' ), 'insertObject: persistierter Entry existiert' );

// --- saveObject skips password fields: the 64-char hash passes minLength 200 ---

$objObject = new ValidationTestObject( 'obj1' );
$objObject->setHash( str_repeat( 'h', 64 ) );
$objObject->saveObject();

$objObject = new ValidationTestObject( 'obj1' );
check( $objObject->getHash() === str_repeat( 'h', 64 ), 'saveObject: password-Feld (Hash) überspringt die Feldregeln und persistiert' );

// --- saveObject: a failed rule throws and leaves the file untouched ---

$objObject = new ValidationTestObject( 'obj1' );
$objObject->setName( 'ab' );

$boolThrew = false;
$strMessage = '';

try {
  $objObject->saveObject();
} catch( PersistenceValidationException $objException ) {
  $boolThrew = true;
  $strMessage = $objException->getMessage();
}

check( $boolThrew, 'saveObject: minLength-Verstoß wirft die PersistenceValidationException' );
check( strpos( $strMessage, 'Persistenz-Validierung fehlgeschlagen' ) !== false && strpos( $strMessage, 'ValidationTestObject::obj1' ) !== false, 'saveObject: Exception-Nachricht nennt Klasse und Id' );

$objRawObject = BaseObject::getRawObject( 'ValidationTestObject', 'obj1' );
check( $objRawObject->strName === 'alpha name', 'saveObject: abgelehntes Save ändert die Klassendatei nicht (alter Stand gewinnt)' );

// --- saveObject update: absent mandatory fields pass (create-only duty) ---

$objObject = new ValidationTestObject( 'obj1' );
$objObject->clearName();
$objObject->saveObject();

$objRawObject = BaseObject::getRawObject( 'ValidationTestObject', 'obj1' );
check( ! isset( $objRawObject->strName ), 'saveObject: Update ohne Pflichtfeld ist erlaubt (Mandatory nur im create case)' );

echo 'Alle Tests bestanden.' . PHP_EOL;

// EOF
