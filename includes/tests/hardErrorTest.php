<?php

declare( strict_types = 1 );

/**
 * This suite is the regression test for the hard error channel —
 * unhandled exceptions no longer end as a blank page but are caught
 * at the edges: action crash → generic AppError into the soft
 * collector (overlay route), view crash → HTTP 500 + static
 * emergency page (HTML) resp. empty body (JSON). Exception
 * details travel exclusively through logHardError into the log —
 * the frontend stays generic (information leak shielding). The
 * index.php safety net (boot and constructor crashes) needs the
 * full app context and is not testable isolated — documented
 * waiver analogous to errorTest.php.
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.10
 * @since     2026-10-21
 *
 * @example   php includes/tests/hardErrorTest.php
 *
 */

require_once ( __DIR__ . '/bootstrap.php' );

$strUserFile = BaseObject::FILEPATHJSON . 'data/users.json';

if( ! file_exists( $strUserFile ) ) {
  echo 'SKIP: keine lokale users.json — Härtekanal ohne Auth-Kontext nicht prüfbar.' . PHP_EOL;
  exit( 0 );
}

// Log isolation: logHardError writes real lines into messages.log — the shutdown handler restores
// the original state, even after a FEHLER exit of the check() function (pattern from userSaveDeleteTest.php).
$strLogFile     = __DIR__ . '/../logs/messages.log';
$boolLogExisted = file_exists( $strLogFile );

if( $boolLogExisted ) {
  copy( $strLogFile, $strLogFile . '.bak' );

  /**
   * This shutdown handler restores the messages.log backup on shutdown — it runs
   * on every suite end, including a FEHLER exit of check().
   *
   * @return    void
   *
   */
  register_shutdown_function( function() use ( $strLogFile ) : void {
    if( file_exists( $strLogFile . '.bak' ) === false ) return;

    copy( $strLogFile . '.bak', $strLogFile );
    unlink( $strLogFile . '.bak' );

    return;
  } );
} else {
  /**
   * This shutdown handler removes the messages.log again on shutdown (it did not
   * exist when the suite started) — it runs on every suite end, including a
   * FEHLER exit of check().
   *
   * @return    void
   *
   */
  register_shutdown_function( function() use ( $strLogFile ) : void {
    if( file_exists( $strLogFile ) ) unlink( $strLogFile );

    return;
  } );
}

/**
 * This function builds the test view: an anonymous subclass that throws
 * in a controlled way in the desired branch — without view id, permission
 * check or real view call: only executeActions/executeView are replaced
 * (reflection swap on the mock).
 *
 * @param     string    $strResultType        The result type ('' = HTML, 'json' = JSON)
 * @param     boolean   $boolCrashInActions   True to crash inside executeActions, false to crash inside executeView
 * @return    View      The anonymous crash view instance
 *
 * @example   $objView = crashView( 'json', false );
 *
 */
function crashView( string $strResultType, bool $boolCrashInActions ) : View {
  return new class( $strResultType, $boolCrashInActions ) extends View {

    /**
     * The crash switch: true lets executeActions throw, false lets executeView throw.
     *
     * @var       boolean
     *
     */
    public bool $boolCrashInActions;

    /**
     * This constructor only carries the parameters into the view properties —
     * the parent constructor (which builds the full view context) is
     * deliberately bypassed.
     *
     * @param     string    $strResultType        The result type ('' = HTML, 'json' = JSON)
     * @param     boolean   $boolCrashInActions   True to crash inside executeActions, false inside executeView
     * @return    void
     *
     */
    public function __construct( string $strResultType, bool $boolCrashInActions ) {
      $this->resultType         = $strResultType;
      $this->boolCrashInActions = $boolCrashInActions;

      return;
    }

    /**
     * This action carrier throws instead of collecting actions when the crash
     * switch targets this branch.
     *
     * @param     Controller         $objController   The controller mock (unused — the crash is the test content)
     * @return    void
     * @throws    RuntimeException   Thrown when boolCrashInActions is true
     *
     */
    public function executeActions( Controller $objController ) : void {
      if( $this->boolCrashInActions ) throw new RuntimeException( 'Testcrash Action' );

      return;
    }

    /**
     * This view stub throws when the crash switch targets this branch,
     * otherwise it returns a marker string for the untouched render path.
     *
     * @param     Controller         $objController      The controller mock (unused — the crash is the test content)
     * @return    string | object    'VIEW-AUSGABE-OK' as the render marker
     * @throws    RuntimeException   Thrown when boolCrashInActions is false
     *
     */
    public function executeView( Controller $objController ) : string | object {
      if( ! $this->boolCrashInActions ) throw new RuntimeException( 'Testcrash View' );

      return 'VIEW-AUSGABE-OK';
    }

  };
}

// --- Constants: generic emergency page without template machinery ---

check( Controller::HARD_ERROR_MESSAGE !== '', 'Härtekanal: generische Meldung non-empty' );
check( str_contains( Controller::HARD_ERROR_HTML, Controller::HARD_ERROR_MESSAGE ), 'Härtekanal: Notfallseite enthält die generische Meldung' );
check( ! str_contains( Controller::HARD_ERROR_HTML, '{{' ), 'Härtekanal: Notfallseite ohne Template-Platzhalter' );

// --- addErrorFromThrowable: id continuation from addError, frontend stays generic ---

$_GET  = [];
$_POST = [];

Request::reset();

$objController = mockController( 'viewUserList' );

$objController->addError( 'Weicher Vorher' );
$objController->addErrorFromThrowable( new RuntimeException( 'Geheim-Kontext Test' ), 'actionUnitTest' );

$arrErrors = $objController->errors();

check( count( $arrErrors ) === 2, 'addErrorFromThrowable: läuft über die addError-Sammlung' );
check( $arrErrors[ 1 ]->id() === 'error2', 'addErrorFromThrowable: Id läuft fortlaufend (error2)' );
check( $arrErrors[ 1 ]->message() === Controller::HARD_ERROR_MESSAGE, 'addErrorFromThrowable: Frontend bleibt generisch' );
check( $arrErrors[ 1 ]->message() !== 'Geheim-Kontext Test', 'addErrorFromThrowable: Exception-Text leakt nicht in den weichen Kanal' );
check( $arrErrors[ 1 ]->severity() === 'error', 'addErrorFromThrowable: Severity error' );
check( $arrErrors[ 1 ]->source() === 'actionUnitTest', 'addErrorFromThrowable: Source läuft durch' );

// --- Action crash: soft route, the page keeps rendering, the error travels the overlay collector ---

Request::reset();

$objActionCrashController = mockController( 'viewUserList' );
( new ReflectionProperty( 'Controller', 'viewObject' ) )->setValue( $objActionCrashController, crashView( '', true ) );

$strHtml     = $objActionCrashController->execute();
$arrErrors   = $objActionCrashController->errors();
$mixResponse = http_response_code();

check( strpos( $strHtml, 'VIEW-AUSGABE-OK' ) !== false, 'Action-Crash: View rendert normal weiter' );
check( strpos( $strHtml, 'Testcrash Action' ) === false, 'Action-Crash: Exception-Text nicht im HTML' );
check( count( $arrErrors ) === 1 && $arrErrors[ 0 ]->id() === 'error1' && $arrErrors[ 0 ]->message() === Controller::HARD_ERROR_MESSAGE, 'Action-Crash: generischer AppError (error1) im weichen Kanal' );
check( $mixResponse === false, 'Action-Crash: kein 500er' );

$arrRawObjects    = $objActionCrashController->rawObjects();
$boolEnvelopeSeen = false;

foreach( $arrRawObjects as $objRawObject ) {
  if( $objRawObject->class !== 'AppError' || $objRawObject->id !== 'error1' ) continue;
  if( $objRawObject->data->message !== Controller::HARD_ERROR_MESSAGE ) continue;

  $boolEnvelopeSeen = true;
}

check( $boolEnvelopeSeen, 'Action-Crash: AppError-Envelope (generisch) im Kollektor' );

// --- View crash HTML: static emergency page instead of abort ---

Request::reset();

$objViewCrashController = mockController( 'viewUserList' );
( new ReflectionProperty( 'Controller', 'viewObject' ) )->setValue( $objViewCrashController, crashView( '', false ) );

$strNotfall = $objViewCrashController->execute();

check( $strNotfall === Controller::HARD_ERROR_HTML, 'View-Crash HTML: Rückgabe ist exakt die Notfallseite' );
check( count( $objViewCrashController->errors() ) === 0, 'View-Crash HTML: kein Zombie-AppError im weichen Kanal' );
check( http_response_code() === 500, 'View-Crash HTML: HTTP-Status 500' );
check( strpos( $strNotfall, 'Testcrash View' ) === false, 'View-Crash HTML: Exception-Text nicht in der Notfallseite' );

// --- View crash JSON: empty 500 ---

Request::reset();

$objJsonCrashController = mockController( 'viewUserList' );
( new ReflectionProperty( 'Controller', 'viewObject' ) )->setValue( $objJsonCrashController, crashView( 'json', false ) );

$strJsonResult = $objJsonCrashController->execute();

// --- Log injection: full details exclusively server-side ---

$strLogContent = file_exists( $strLogFile ) ? file_get_contents( $strLogFile ) : '';

check( strpos( $strLogContent, 'RuntimeException' ) !== false, 'Log: Exception-Klasse landet im messages.log' );
check( strpos( $strLogContent, 'Testcrash Action' ) !== false && strpos( $strLogContent, 'Testcrash View' ) !== false, 'Log: beide Crash-Meldungen landen im messages.log' );
check( strpos( $strLogContent, 'executeActions' ) !== false && strpos( $strLogContent, 'executeView' ) !== false, 'Log: Source-Markierungen (executeActions/executeView) im messages.log' );
check( strpos( $strLogContent, __FILE__ ) !== false, 'Log: Datei-Herkunft (getFile) im messages.log' );

Request::reset();

echo 'Alle Tests bestanden.' . PHP_EOL;

// EOF