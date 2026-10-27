<?php

declare( strict_types = 1 );

/**
 * This file is the shared setup for every test suite in includes/tests/:
 * it loads the classes and provides the helpers check(), getTemplateVar()
 * and mockController(). Run a single suite with
 * "php includes/tests/<name>.php" from the repo root — the suites are
 * committable and have SKIP paths when local production data is missing.
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.10
 * @since     2026-10-18
 *
 * @example   php includes/tests/collectorTest.php
 *
 */

require_once ( __DIR__ . '/../classes/controller.php' );

/**
 * This function reads a single template variable from the protected
 * templateVars property of a presentation instance via reflection
 * (e.g. the collector as 'default::rawObjects').
 *
 * @param     Presentation   $objPresentation   The presentation instance to read from
 * @param     string         $strName           The template variable name
 * @return    mixed          The template variable value
 *
 * @example   $mixValue = getTemplateVar( $objPresentation, 'default::rawObjects' );
 *
 */
function getTemplateVar( Presentation $objPresentation, string $strName ) : mixed {
  $objReflection = new ReflectionProperty( 'Presentation', 'templateVars' );

  $objTemplateVars = $objReflection->getValue( $objPresentation );

  return $objTemplateVars->$strName;
}

/**
 * This function is the regression check helper of every suite: a passing
 * condition prints the OK line, a failing condition prints the FEHLER
 * line and stops the suite with exit code 1.
 *
 * @param     boolean   $boolCondition   The condition to check
 * @param     string    $strMessage      The check description printed in both cases
 * @return    void
 *
 * @example   check( $strValue === 'expected', 'value matches the expectation' );
 *
 */
function check( bool $boolCondition, string $strMessage ) : void {
  if( ! $boolCondition ) {
    echo 'FEHLER: ' . $strMessage . PHP_EOL;
    exit( 1 );
  }

  echo 'OK: ' . $strMessage . PHP_EOL;

  return;
}

/**
 * This function builds the mock controller of the suites: an anonymous
 * Controller subclass whose constructor deliberately skips the parent
 * constructor (the real one boots the whole app incl. auth). Without a
 * view id only presentation, user and role exist; with a view id the view
 * object is created and the request scope is set accordingly.
 *
 * @param     string|null   $strViewId   The view id for the scope or null
 * @return    Controller    The mocked controller instance
 *
 * @example   $objController = mockController( 'viewUserList' );
 *
 */
function mockController( string | null $strViewId = null ) : Controller {
  return new class( $strViewId ) extends Controller {

    /**
     * This method is the constructor of the anonymous mock: it wires the
     * presentation, user and role manually (no parent constructor) and
     * sets the view object plus the request scope when a view id is given.
     *
     * @param     string|null   $strViewId   The view id for the scope or null
     * @return    void
     *
     */
    public function __construct( string | null $strViewId = null ) {
      $this->presentation = new Presentation();
      $this->user         = new User();
      $this->role         = $this->user->role();

      if( isset( $strViewId ) ) {
        $this->viewObject = new View( $strViewId );

        Request::setScope( $strViewId );
      }
    }

  };
}

// EOF
