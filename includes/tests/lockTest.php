<?php

declare( strict_types = 1 );

/**
 * This suite is the regression test for the write locking of the
 * persistence layer: two parallel child processes save objects into
 * the same class json file (lost update race) and a foreign process
 * holds the lock file until after the deadline (timeout path with the
 * RuntimeException of withFileLock). The suite runs entirely in an
 * isolated temp directory and never touches the local production data
 * files — no .bak/restore handling is needed. requires proc_open.
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.13
 * @since     2026-11-01
 *
 * @example   php includes/tests/lockTest.php
 *
 */

require_once ( __DIR__ . '/bootstrap.php' );

if( ! function_exists( 'proc_open' ) ) {
  echo 'SKIP: proc_open nicht verfügbar — Race-Test kann nicht laufen.' . PHP_EOL;

  exit( 0 );
}

/**
 * Local test object: a minimal BaseObject subclass that swaps the
 * persistence file to the temp directory after construction (the
 * constructor itself derives the never existing production path
 * includes/json/data/locktestobjects.json, so fillObject stays a
 * no-op) and inflates the serialization step to widen the
 * read-modify-write conflict window of concurrent saves.
 *
 */
class LockTestObject extends BaseObject {

  /**
   * The counter payload of the test object.
   *
   * @var       int
   *
   */
  protected int $intTicks = 0;

  /**
   * This method is the constructor of the class: a normal BaseObject
   * construction followed by the swap of the persistence file onto
   * the given temp path.
   *
   * @param     string    $strId            The entry id within the test file
   * @param     string    $strDataJsonFile  The temp class json file path
   * @return    void
   *
   */
  public function __construct( string $strId, string $strDataJsonFile ) {
    parent::__construct( $strId );

    $this->dataJsonFile = $strDataJsonFile;

    return;
  }

  /**
   * This method increments the counter payload by one.
   *
   * @return    void
   *
   * @example   $objTestObject->tick();
   *
   */
  public function tick() : void {
    $this->intTicks++;

    return;
  }

  /**
   * This method inflates the serialization step of the save cycle by
   * two milliseconds to reliably widen the read-modify-write conflict
   * window of concurrent saves. Only the test class is affected, the
   * production serialization stays realistic.
   *
   * @return    object   The serialized object for persistence
   *
   */
  public function serializeObjectForSave() : object {
    usleep( 2000 );

    return parent::serializeObjectForSave();
  }

}

/**
 * This function is the child process entry: it runs one of the worker
 * modes ('a'/'b' parallel race writers, 'h' lock holder as foreign
 * process, 't' timeout writer), signals its readiness via a barrier
 * file in the test directory and prints a short result token on
 * stdout ('WORKER-OK', 'TIMEOUT-OK', 'KEIN-TIMEOUT'). Errors are
 * reported as "WORKER-FEHLER: ..." with exit code 2, so the parent
 * never hangs. The check() helper is never used here — OK/FEHLER
 * lines belong to the parent only.
 *
 * @param     array    $arrArguments   The raw $argv of the child process
 * @return    void
 *
 */
function runWorker( array $arrArguments ) : void {
  $strMode    = $arrArguments[ 2 ] ?? '';
  $strTestDir = $arrArguments[ 3 ] ?? '';

  try {
    if( $strMode == 'a' || $strMode == 'b' ) {
      $strEntryId = $strMode == 'a' ? 'childA' : 'childB';
      $objObject  = new LockTestObject( $strEntryId, $strTestDir . '/locktestobjects.json' );

      file_put_contents( $strTestDir . '/ready-' . $strMode, '1' );

      if( ! waitGoFile( $strTestDir . '/go' ) ) throw new RuntimeException( 'go-Signal blieb aus' );

      for( $intCount = 0; $intCount < 50; $intCount++ ) {
        $objObject->tick();
        $objObject->saveObject();
      }

      echo 'WORKER-OK';

      exit( 0 );
    }

    if( $strMode == 'h' ) {
      $objLockHandle = fopen( $strTestDir . '/locktestobjects.json.lock', 'c' );

      if( $objLockHandle === false ) throw new RuntimeException( 'Lock-Datei nicht beschaffbar' );

      if( ! flock( $objLockHandle, LOCK_EX ) ) throw new RuntimeException( 'Lock nicht übernommen' );

      file_put_contents( $strTestDir . '/ready-h', '1' );

      sleep( 8 );

      flock( $objLockHandle, LOCK_UN );
      fclose( $objLockHandle );

      echo 'WORKER-OK';

      exit( 0 );
    }

    if( $strMode == 't' ) {
      try {
        $objObject = new LockTestObject( 'timeout', $strTestDir . '/locktestobjects.json' );
        $objObject->saveObject();
      } catch( RuntimeException ) {
        echo 'TIMEOUT-OK';

        exit( 0 );
      }

      echo 'KEIN-TIMEOUT';

      exit( 1 );
    }

    throw new RuntimeException( 'unbekannter Worker-Modus: ' . $strMode );
  } catch( Throwable $objException ) {
    echo 'WORKER-FEHLER: ' . $objException->getMessage();

    exit( 2 );
  }
}

/**
 * This helper waits until a file exists or the timeout is reached.
 * Used by the parent for the ready barriers and by the workers for
 * the go signal.
 *
 * @param     string    $strFile            The file path to wait for
 * @param     int       $intTimeoutSeconds  The maximum wait time in seconds
 * @return    boolean   True when the file exists, false on timeout
 *
 */
function waitForFile( string $strFile, int $intTimeoutSeconds ) : bool {
  $floatDeadline = microtime( true ) + $intTimeoutSeconds;

  while( microtime( true ) < $floatDeadline ) {
    if( file_exists( $strFile ) ) return true;

    usleep( 10000 );
  }

  return false;
}

/**
 * This helper waits until the go signal file appears (worker-side
 * call of waitForFile with a generous timeout).
 *
 * @param     string    $strFile   The go signal file path
 * @return    boolean   True when the signal appeared, false on timeout
 *
 */
function waitGoFile( string $strFile ) : bool {
  return waitForFile( $strFile, 20 );
}

// --- worker mode: this full file doubles as child process script ---

if( ( $argv[ 1 ] ?? '' ) === 'worker' ) {
  runWorker( $argv );
}

// --- setup: isolated temp test directory, leftovers from earlier runs removed ---

$strTestDir  = sys_get_temp_dir() . '/jpEnvelopeLockTest';
$strDataFile = $strTestDir . '/locktestobjects.json';

if( ! is_dir( $strTestDir ) ) mkdir( $strTestDir, 0777, true );

/**
 * This shutdown handler removes the complete temp test directory
 * content — it runs on every suite end, including a FEHLER exit of
 * check(). Leftover files on a hostile file system are tolerated.
 *
 * @return    void
 *
 */
register_shutdown_function( function() use ( $strTestDir ) : void {
  @unlink( $strTestDir . '/ready-a' );
  @unlink( $strTestDir . '/ready-b' );
  @unlink( $strTestDir . '/ready-h' );
  @unlink( $strTestDir . '/go' );
  @unlink( $strTestDir . '/locktestobjects.json' );
  @unlink( $strTestDir . '/locktestobjects.json.lock' );
  @rmdir( $strTestDir );

  return;
} );

@unlink( $strDataFile );
@unlink( $strDataFile . '.lock' );
@unlink( $strTestDir . '/ready-a' );
@unlink( $strTestDir . '/ready-b' );
@unlink( $strTestDir . '/ready-h' );
@unlink( $strTestDir . '/go' );

file_put_contents( $strDataFile, '{}' );

// --- part 1: lost update race, two parallel writers on one class file ---

$objProcessA = proc_open( [ PHP_BINARY, __FILE__, 'worker', 'a', $strTestDir ], [ 1 => [ 'pipe', 'w' ] ], $arrPipesA );
$objProcessB = proc_open( [ PHP_BINARY, __FILE__, 'worker', 'b', $strTestDir ], [ 1 => [ 'pipe', 'w' ] ], $arrPipesB );

check( is_resource( $objProcessA ) && is_resource( $objProcessB ), 'beide Race-Worker gestartet' );
check( waitForFile( $strTestDir . '/ready-a', 15 ) && waitForFile( $strTestDir . '/ready-b', 15 ), 'beide Race-Worker sind an der Barrier angemeldet' );

file_put_contents( $strTestDir . '/go', '1' );

$strStdoutA = stream_get_contents( $arrPipesA[ 1 ] );
$strStdoutB = stream_get_contents( $arrPipesB[ 1 ] );
$intExitA   = proc_close( $objProcessA );
$intExitB   = proc_close( $objProcessB );

check( $intExitA === 0 && $intExitB === 0, 'beide Race-Worker beendet ohne Fehler (exit ' . $intExitA . ' / ' . $intExitB . ')' );
check( trim( $strStdoutA ) === 'WORKER-OK' && trim( $strStdoutB ) === 'WORKER-OK', 'beide Race-Worker melden WORKER-OK' );

$objData = BaseObject::loadFileDeCrypted( $strDataFile );

check( isset( $objData->childA ) && isset( $objData->childB ), 'beide Einträge überleben den parallelen Zugriff' );
check( ( $objData->childA->intTicks ?? 0 ) === 50 && ( $objData->childB->intTicks ?? 0 ) === 50, 'kein Lost Update über 100 konkurrierende Saves' );
check( file_exists( $strDataFile . '.lock' ), 'Lock-Nebendatei neben der Klassendatei angelegt' );

// --- part 2: timeout while a foreign process holds the lock file (deterministic) ---

file_put_contents( $strDataFile, '{}' );

$strSeedMd5 = md5_file( $strDataFile );

$objProcessH = proc_open( [ PHP_BINARY, __FILE__, 'worker', 'h', $strTestDir ], [ 1 => [ 'pipe', 'w' ] ], $arrPipesH );

check( is_resource( $objProcessH ), 'Lock-Holder-Worker gestartet' );
check( waitForFile( $strTestDir . '/ready-h', 15 ), 'Lock-Holder hält die Lock-Datei' );

$objProcessT = proc_open( [ PHP_BINARY, __FILE__, 'worker', 't', $strTestDir ], [ 1 => [ 'pipe', 'w' ] ], $arrPipesT );

check( is_resource( $objProcessT ), 'Timeout-Writer-Worker gestartet' );

$strStdoutT = stream_get_contents( $arrPipesT[ 1 ] );
$intExitT   = proc_close( $objProcessT );

check( $intExitT === 0 && trim( $strStdoutT ) === 'TIMEOUT-OK', 'saveObject wirft bei fremdgehaltener Lock-Datei nach 5 Sekunden eine RuntimeException' );
check( md5_file( $strDataFile ) === $strSeedMd5, 'kein Schrott-Zustand — die Timeout-Exception fliegt vor jedem Write' );

$strStdoutH = stream_get_contents( $arrPipesH[ 1 ] );
$intExitH   = proc_close( $objProcessH );

check( $intExitH === 0 && trim( $strStdoutH ) === 'WORKER-OK', 'Lock-Holder beendet sauber nach eigenem Release' );

( new LockTestObject( 'erholung', $strDataFile ) )->saveObject();

$objDataAfter = BaseObject::loadFileDeCrypted( $strDataFile );

check( isset( $objDataAfter->erholung ), 'nach dem Lock-Release wird wieder normal in die Datei geschrieben' );

echo 'Alle Tests bestanden.' . PHP_EOL;

// EOF
