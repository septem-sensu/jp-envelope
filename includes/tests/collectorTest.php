<?php

declare( strict_types = 1 );

/**
 * This suite is the regression test for the PHP to JS contract of the
 * rawObjects collector: envelope structure, dedupe with last-wins
 * override, the alternative JS class name and the app context
 * including the throw order of execute().
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.10
 * @since     2026-10-18
 *
 * @example   php includes/tests/collectorTest.php
 *
 */

require_once ( __DIR__ . '/bootstrap.php' );

/**
 * Local test object: a minimal BaseObject subclass with one
 * serializable property for the collector checks.
 *
 */
class TestObject extends BaseObject {

  /**
   * The payload property of the test object.
   *
   * @var       string
   *
   */
  protected string $mixTest = '';

  /**
   * This method sets the payload property of the test object.
   *
   * @param     string    $strValue   The payload value
   * @return    void
   *
   */
  public function setTest( string $strValue ) : void {
    $this->mixTest = $strValue;

    return;
  }

}

$objController = mockController();

$objTestA = new TestObject();
$objTestB = new TestObject();

$objRawObject = $objTestA->rawObject();
check( $objRawObject->class === 'TestObject', 'rawObject: Klassenname im Envelope' );
check( $objRawObject->id === $objTestA->id(), 'rawObject: Id im Envelope' );
check( $objRawObject->data->class === 'TestObject', 'rawObject: Property class serialisiert' );
check( $objRawObject->data->id === $objTestA->id(), 'rawObject: Property id serialisiert' );
check( ! isset( $objRawObject->data->dataJsonFile ), 'rawObject: dataJsonFile gefiltert (NOSERIALIZE)' );

$objController->addRawObject( $objTestA );
$objController->addRawObject( $objTestB );

$arrRawObjects = json_decode( getTemplateVar( $objController->presentation(), 'default::rawObjects' ) );
check( count( $arrRawObjects ) === 2, 'Kollektor: zwei Objekte eingesammelt' );
check( $arrRawObjects[ 0 ]->id === $objTestA->id(), 'Kollektor: Objekt A an Position 1' );
check( $arrRawObjects[ 1 ]->id === $objTestB->id(), 'Kollektor: Objekt B an Position 2' );

$objTestA->setTest( 'zweite Version' );
$objController->addRawObject( $objTestA );

$arrRawObjects = json_decode( getTemplateVar( $objController->presentation(), 'default::rawObjects' ) );
check( count( $arrRawObjects ) === 2, 'Dedupe: doppeltes Objekt erzeugt keinen neuen Eintrag' );
check( $arrRawObjects[ 0 ]->data->mixTest === 'zweite Version', 'Dedupe: letzter Eintrag gewinnt' );
check( $arrRawObjects[ 1 ]->id === $objTestB->id(), 'Dedupe: Position von Objekt B unverändert' );

$objController->addRawObject( $objTestB, 'User' );

$arrRawObjects = json_decode( getTemplateVar( $objController->presentation(), 'default::rawObjects' ) );
check( count( $arrRawObjects ) === 3, 'Override: alternativer JS-Klassenname erzeugt separaten Eintrag' );
check( $arrRawObjects[ 2 ]->class === 'User', 'Override: JS-Klassenname im Envelope' );
check( $arrRawObjects[ 2 ]->data->id === $objTestB->id(), 'Override: Objekt-Id in data bleibt erhalten' );

check( count( $objController->rawObjects() ) === 3, 'Getter rawObjects: Kollektor lesbar' );

// --- App envelope: the JS context access comes from PHP instead of the loader unshift ---

$objConfig        = BaseObject::config();
$objAppController = mockController( 'viewUserList' );
$objApp           = new App( $objAppController );

check( $objApp->id() === $objConfig->appAlias() . '_' . $objConfig->version(), 'App: Id ist appAlias_version aus der configs.json' );

$objRawObject = $objApp->rawObject();

check( $objRawObject->class === 'App', 'App: Klassenname im Envelope' );
check( $objRawObject->id === $objApp->id(), 'App: Id im Envelope' );
check( $objRawObject->data->id === $objApp->id(), 'App: Property id serialisiert' );
check( $objRawObject->data->class === 'App', 'App: Property class serialisiert' );
check( $objRawObject->data->currentUserId === $objAppController->user()->id(), 'App: currentUserId im data-Payload' );
check( $objRawObject->data->currentUserRoleId === $objAppController->user()->roleId(), 'App: currentUserRoleId im data-Payload' );
check( $objRawObject->data->currentView->viewName === $objAppController->viewObject()->viewName(), 'App: currentView ist die serialisierte aktuelle View' );

// execute(): the app stands at position 1, only then the resolved user. Against an anonymous view
// subclass without real action or view execution, so the checks need neither user data nor templates.

$objExecutionView = new class( 'viewUserList' ) extends View {

  /**
   * This method neutralizes the action execution for the test.
   *
   */
  public function executeActions( Controller $objController ) : void {
    return;
  }

  /**
   * This method reports an HTML view for the test.
   *
   */
  public function resultType() : string | null {
    return '';
  }

  /**
   * This method neutralizes the view execution for the test.
   *
   */
  public function executeView( Controller $objController ) : string | object {
    return '';
  }

};

$objController = mockController();
( new ReflectionProperty( 'Controller', 'viewObject' ) )->setValue( $objController, $objExecutionView );

$strResult     = $objController->execute();
$arrRawObjects = $objController->rawObjects();

$arrTemplateRawObjects = json_decode( getTemplateVar( $objController->presentation(), 'default::rawObjects' ) );

check( $strResult === '', 'execute: View-Rückgabewert fließt durch' );
check( count( $arrRawObjects ) === 2, 'execute: wirft App und User in den Kollektor' );
check( $arrRawObjects[ 0 ]->class === 'App', 'execute: App-Envelope an Position 1' );
check( $arrRawObjects[ 0 ]->id === $objApp->id(), 'execute: App-Id identisch zur Standalone-Instanz' );
check( $arrRawObjects[ 1 ]->class === 'User', 'execute: User-Envelope an Position 2' );
check( count( $arrTemplateRawObjects ) === 2 && $arrTemplateRawObjects[ 0 ]->class === 'App', 'execute: Template-Var rawObjects führt App zuerst' );

echo 'Alle Tests bestanden.' . PHP_EOL;

// EOF
