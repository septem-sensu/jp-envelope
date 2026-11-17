<?php

declare( strict_types = 1 );

/**
 * This suite is the regression test for the atomic persistence write:
 * saveFileEnCrypted() lands the content in a temporary sibling file that
 * is renamed onto the target — a process death mid-write leaves the
 * previous file state intact instead of a truncated json. The suite
 * proves the roundtrip through the real save paths (insertObject,
 * saveObject), that no .tmp rest is left behind, that the rename
 * replaces an existing file (double save), that a stale temp from a
 * dead process does not disturb the next write and that a failing temp
 * write throws the fail-loud RuntimeException. The suite writes only
 * into its own class json file derived from the test class name
 * (atomicTestObject.json) — it is removed on every suite end by the
 * shutdown handler, the local production data files are never touched.
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.20
 * @since     2026-11-16
 *
 * @example   php includes/tests/atomicWriteTest.php
 *
 */

require_once ( __DIR__ . '/bootstrap.php' );

$strObjectsFile = BaseObject::FILEPATHJSON . 'data/atomicTestObject.json';

/**
 * This shutdown handler removes the class json file of the test class
 * including its lock and temp side files — it runs on every suite end,
 * including a FEHLER exit of check().
 *
 * @return    void
 *
 */
register_shutdown_function( function() use ( $strObjectsFile ) : void {
  @unlink( $strObjectsFile );
  @unlink( $strObjectsFile . '.lock' );
  @unlink( $strObjectsFile . '.tmp' );

  return;
} );

/**
 * Local test object: a minimal BaseObject subclass with one payload
 * property.
 *
 */
class AtomicTestObject extends BaseObject {

  /**
   * The payload property of the test object.
   *
   * @var       string
   *
   */
  protected string $mixPayload = '';

  /**
   * This method sets the payload property of the test object.
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
   * This method reads the payload property of the test object.
   *
   * @return    string    The payload value
   *
   */
  public function getPayload() : string {
    return $this->mixPayload;
  }

}

// --- insertObject writes atomically: file valid, no temp rest ---

$objEntry       = new stdClass();
$objEntry->id   = 'a';
$objEntry->class = 'AtomicTestObject';
$objEntry->mixPayload = 'v1';

check( BaseObject::insertObject( 'AtomicTestObject', 'a', $objEntry ), 'insertObject: Eintrag persistiert' );
check( file_exists( $strObjectsFile ), 'insertObject: Klassendatei angelegt' );
check( ! file_exists( $strObjectsFile . '.tmp' ), 'insertObject: keine .tmp-Restdatei (rename geräumt)' );

$objObjects = BaseObject::loadFileDeCrypted( $strObjectsFile );
check( isset( $objObjects->a ) && $objObjects->a->mixPayload === 'v1', 'insertObject: Dateiinhalt gültig und lesbar (kein trunkiertes JSON)' );

// --- double save: the rename replaces an existing file (last wins) ---

$objObject = new AtomicTestObject( 'a' );
$objObject->setPayload( 'v2' );
$objObject->saveObject();

$objObject = new AtomicTestObject( 'a' );
check( $objObject->getPayload() === 'v2', 'Zweit-Write: rename überschreibt bestehende Datei, neuer Stand gewinnt' );

// --- stale temp from a dead process does not disturb the next write ---

file_put_contents( $strObjectsFile . '.tmp', 'corrupt-content-from-dead-process' );

$objObject = new AtomicTestObject( 'a' );
$objObject->setPayload( 'v3' );
$objObject->saveObject();

$objObjects = BaseObject::loadFileDeCrypted( $strObjectsFile );
check( $objObjects->a->mixPayload === 'v3', 'Stale-Temp: Schreibversuch nach Prozess-Tod funktioniert' );
check( ! file_exists( $strObjectsFile . '.tmp' ), 'Stale-Temp: Überbleibsel vom nächsten Write überschrieben und geräumt' );

// --- failing temp write throws the fail-loud RuntimeException ---

$boolThrew = false;

try {
  BaseObject::saveFileEnCrypted( BaseObject::FILEPATHJSON . 'data/missing-dir/fail.json', new stdClass() );
} catch( RuntimeException ) {
  $boolThrew = true;
}

check( $boolThrew, 'Fehlschlagender Temp-Write wirft die RuntimeException (fail loud, kein stiller Erfolg)' );

echo 'Alle Tests bestanden.' . PHP_EOL;

// EOF
