<?php

declare( strict_types = 1 );

require_once ( __DIR__ . '/../classes/baseObject.php' );

/**
* This class represents the Diagnostics, which is a test run of all PHP test suites
 * which contains the results of the individual system unit tests in the constant TESTSUITES.
 * The system unit test results include the test suite alias and the test suite name
 * as well as a second name for each test and a short description of what exactly the unit test is
 * includes and the unit test result.
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.14
 * @since     2026-10-26
 *
 * @example   $objDiagnostics = new Diagnostics();
 *
 */
class Diagnostics extends BaseObject {

  /**
   * An associative array with the PHP test suite files without file extension as key and the
   * Test suite name as Value.
   *
   * @var       array
   *
   */
  const TESTSUITES = [
    'actionsTest'        => 'Actions',
    'blueprintTest'      => 'Blueprint',
    'collectorTest'      => 'Collector',
    'errorTest'          => 'Errors',
    'hardErrorTest'      => 'Hard Errors',
    'fieldValidatorTest' => 'Field Validator',
    'requestTest'        => 'Requests',
    'userListTest'       => 'User List',
    'userLoginTest'      => 'User Login',
    'userSaveDeleteTest' => 'User CRUD'
  ];

  /**
   * The collected diagnostics data object for the frontend
   * (suite name, suite alias, unit test name, unit test result, unit test short-description).
   *
   * @var       object
   *
   */
  protected object  $diagnosticsData;

  /**
   * This method sets the collected diagnostics data object
   * (suite name, suite alias, unit test name, unit test result, unit test short-description)
   * on the instance.
   *
   * @param     object    $objDiagnosticsData   The collected diagnostics data object
   * @return    void
   *
   * @example   $objDiagnostics->setDiagnosticsData( $objDiagnosticsData );
   *
   */
  public function setDiagnosticsData( object $objDiagnosticsData ) : void {
    $this->diagnosticsData = $objDiagnosticsData;

    return;
  }

  /**
   * This static method leaves the individual PHP test suites out of the constant
   * TESTSUITES run and creates a standard object with the results
   * Results of the individual unit tests of the test suites.
   * The method creates a new Diagnostics instance and fills it with the generated ones
   * Test results and passes them to the collector for the frontend.
   *
   * @param     Controller    $objController   The controller instance of the request
   * @return    void
   *
   * @example   Diagnostics::collectDiagnostics( $objController );
   *
   */
  public static function collectDiagnostics( Controller $objController ) : void {
    $objDiagnostics     = new Diagnostics();
    $objDiagnosticsData = new stdClass();

    foreach( self::TESTSUITES as $strSuite => $strSuiteName ) {
      $strSuiteContent      = shell_exec( 'php includes/tests/' . $strSuite .  '.php' );
      $arrSuiteContentLines = explode( "\n", $strSuiteContent );
      $objNewSuite          = new stdClass();
      $objNewSuite->name    = $strSuiteName;
      $objNewSuite->alias   = $strSuite;
      $objNewSuite->data    = [];
      $boolSuiteHasErrors   = false;

      for( $i = 0; $i < count( $arrSuiteContentLines ); $i++ ) {
        $arrSuiteContentLine = explode( ':', $arrSuiteContentLines[ $i ] );

        if( count( $arrSuiteContentLine ) < 2 ) continue;

        $objNewSuiteData        = new stdClass();
        $objNewSuiteData->state = trim( $arrSuiteContentLine[ 0 ] );
        $objNewSuiteData->sub   = isset( $arrSuiteContentLine[ 2 ] ) ? trim( $arrSuiteContentLine[ 1 ] ) : '';
        $objNewSuiteData->name  = isset( $arrSuiteContentLine[ 2 ] ) ? trim( $arrSuiteContentLine[ 2 ] ) : trim( $arrSuiteContentLine[ 1 ] );

        if( strtolower( $objNewSuiteData->state ) !== 'ok' ) $boolSuiteHasErrors = true;

        array_push( $objNewSuite->data, $objNewSuiteData );
      }

      $objNewSuite->state            = $boolSuiteHasErrors ? 'FEHLER' : 'OK';
      $objDiagnosticsData->$strSuite = $objNewSuite;
    }

    $objDiagnostics->setDiagnosticsData( $objDiagnosticsData );
    $objController->addRawObject( $objDiagnostics );

    return;
  }

}
