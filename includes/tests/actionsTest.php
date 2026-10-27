<?php

declare( strict_types = 1 );

/**
 * This suite is the regression test for the central action routing of
 * the views (S1 redesign 0.2.11):
 * - S2: the config validation fails loud on drift — every actionNames
 *   entry in views.json must be an object with an existing "Class::method"
 *   plus exactly one routing field (string "trigger" or true "always" —
 *   a missing, doubled or wrongly typed routing field throws), every
 *   permission in roles.json must point to an existing two-segment
 *   method (RuntimeException, caught in execute() as generic soft
 *   error + log).
 * - S1: the router calls triggered actions only on a matching 'action'
 *   parameter and always actions on every request of the view. Lost
 *   method guards are no guards anymore: the routing decision lives in
 *   View::executeActions alone.
 * - S5: a request that explicitly triggers a not-permissioned action
 *   gets a visible AppError with the trigger as source (an alias
 *   trigger decouples trigger and method name, changeUser ->
 *   User::saveUser).
 * - An 'action' parameter matching no trigger of the view gets a
 *   visible unknown action error — the method name of an aliased
 *   action explicitly does NOT trigger the method (alias separation),
 *   and neither leaks a permission hint.
 * Every scenario works on a fresh mock controller (the collected
 * errors start empty, no reset helper needed).
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.12
 * @since     2026-10-24
 *
 * @example   php includes/tests/actionsTest.php
 *
 */

require_once ( __DIR__ . '/bootstrap.php' );

$strUserFile = BaseObject::FILEPATHJSON . 'data/users.json';

if( ! file_exists( $strUserFile ) ) {
  echo 'SKIP: keine lokale users.json — Action-Prinzip ohne Auth-Kontext nicht prüfbar.' . PHP_EOL;
  exit( 0 );
}

$objActionNames = new ReflectionProperty( 'View', 'actionNames' );
$objRole        = new ReflectionProperty( 'Controller', 'role' );
$objView        = new View( 'viewUserList' );

// --- S2: entry as string instead of an object throws fail-loud ---

$_GET  = [];
$_POST = [];

Request::reset();
$objController = mockController( 'viewUserList' );

$objActionNames->setValue( $objView, [ 'User::createUser' ] );

$boolThrown          = false;
$strExceptionMessage = '';

try {
  $objView->executeActions( $objController );
} catch( RuntimeException $objException ) {
  $boolThrown          = true;
  $strExceptionMessage = $objException->getMessage();
}

check( $boolThrown, 'S2: String-Eintrag statt Objekt wirft RuntimeException' );
check( str_contains( $strExceptionMessage, 'viewUserList' ), 'S2: Exception-Meldung nennt die View-Id' );

// --- S2: unknown method throws fail-loud and names the action ---

$objActionNames->setValue( $objView, [ (object) [ 'method' => 'User::doesNotExist', 'trigger' => 'createUser' ] ] );

$boolThrown          = false;
$strExceptionMessage = '';

try {
  $objView->executeActions( $objController );
} catch( RuntimeException $objException ) {
  $boolThrown          = true;
  $strExceptionMessage = $objException->getMessage();
}

check( $boolThrown, 'S2: unbekannte Action im views.json-Eintrag wirft RuntimeException' );
check( str_contains( $strExceptionMessage, 'User::doesNotExist' ), 'S2: Exception-Meldung nennt die ungültige Action' );

// --- S2: method without class segment throws fail-loud ---

$objActionNames->setValue( $objView, [ (object) [ 'method' => 'User', 'trigger' => 'createUser' ] ] );

$boolThrown = false;

try {
  $objView->executeActions( $objController );
} catch( RuntimeException $objException ) {
  $boolThrown = true;
}

check( $boolThrown, 'S2: Eintrag mit Methode ohne Class-Segment wirft RuntimeException' );

// --- S2: entry without method field throws fail-loud ---

$objActionNames->setValue( $objView, [ (object) [ 'trigger' => 'createUser' ] ] );

$boolThrown = false;

try {
  $objView->executeActions( $objController );
} catch( RuntimeException $objException ) {
  $boolThrown = true;
}

check( $boolThrown, 'S2: Eintrag ohne Methode wirft RuntimeException' );

// --- S2: entry without routing field (neither trigger nor always) throws fail-loud ---

$objActionNames->setValue( $objView, [ (object) [ 'method' => 'User::createUser' ] ] );

$boolThrown = false;

try {
  $objView->executeActions( $objController );
} catch( RuntimeException $objException ) {
  $boolThrown = true;
}

check( $boolThrown, 'S2: Eintrag ohne trigger und ohne always wirft RuntimeException' );

// --- S2: entry with trigger AND always throws fail-loud ---

$objActionNames->setValue( $objView, [ (object) [ 'method' => 'User::createUser', 'trigger' => 'createUser', 'always' => true ] ] );

$boolThrown = false;

try {
  $objView->executeActions( $objController );
} catch( RuntimeException $objException ) {
  $boolThrown = true;
}

check( $boolThrown, 'S2: Eintrag mit trigger und gleichzeitig always wirft RuntimeException' );

// --- S2: always not exactly true throws fail-loud ---

$objActionNames->setValue( $objView, [ (object) [ 'method' => 'User::createUser', 'always' => 'ja' ] ] );

$boolThrown = false;

try {
  $objView->executeActions( $objController );
} catch( RuntimeException $objException ) {
  $boolThrown = true;
}

check( $boolThrown, 'S2: always mit ungültigem Wert (kein true) wirft RuntimeException' );

// --- S2: trigger not a string throws fail-loud ---

$objActionNames->setValue( $objView, [ (object) [ 'method' => 'User::createUser', 'trigger' => 123 ] ] );

$boolThrown = false;

try {
  $objView->executeActions( $objController );
} catch( RuntimeException $objException ) {
  $boolThrown = true;
}

check( $boolThrown, 'S2: trigger mit ungültigem Typ (kein String) wirft RuntimeException' );

// --- S2: permission drift (permission without existing method) throws fail-loud ---

$objRoleMock = new class extends Role {

  public function permissions() : array | null {
    return [ 'User::doesNotExist' ];
  }

};

$objRole->setValue( $objController, $objRoleMock );

$objView    = new View( 'viewUserList' );
$boolThrown = false;

try {
  $objView->executeActions( $objController );
} catch( RuntimeException $objException ) {
  $boolThrown = true;
}

check( $boolThrown, 'S2: Permission ohne existierende Methode wirft RuntimeException' );

// --- S1: triggered action without a matching request stays silent (even without permission) ---

$_GET              = [];
$_POST             = [];
Request::reset();
$objController     = mockController( 'viewUserList' );
$objRoleMock       = new class extends Role {

  public function permissions() : array | null {
    return [ 'User::deleteUser' ];
  }

};

$objRole->setValue( $objController, $objRoleMock );
$objActionNames->setValue( $objView, [ (object) [ 'method' => 'User::createUser', 'trigger' => 'createUser' ] ] );
$objView->executeActions( $objController );

check( count( $objController->errors() ) === 0, 'S1: getriggerte Action ohne Treffer läuft nicht, ohne Permission bleibt es still' );

// --- S1: always action runs without an action parameter ---

Request::reset();
$objController = mockController( 'viewUserList' );
$objRole->setValue( $objController, new Role( 'administrator' ) );
$objActionNames->setValue( $objView, [ (object) [ 'method' => 'User::createUser', 'always' => true ] ] );
$objView->executeActions( $objController );

$arrErrors = $objController->errors();

check( count( $arrErrors ) >= 1, 'S1: always-Action läuft bei jedem Request ohne action-Parameter' );
check( $arrErrors[ 0 ]->message() === 'Bitte eine E-Mail-Adresse angeben.', 'S1: der Fehler kommt aus dem createUser-Körper (Routing ruft die Methode auf)' );
check( $arrErrors[ 0 ]->source() === 'createUser', 'S1: Source ist der createUser-Trigger' );

// --- S5: triggered action without permission lands as visible AppError ---

$_GET              = [];
$_POST             = [];
$_GET[ 'action' ]  = 'changeUser';

Request::reset();
$objController = mockController( 'viewUserList' );
$objRole->setValue( $objController, $objRoleMock );

$objView         = new View( 'viewUserList' );
$objActionNames->setValue( $objView, [ (object) [ 'method' => 'User::saveUser', 'trigger' => 'changeUser' ] ] );
$objView->executeActions( $objController );

$arrErrors = $objController->errors();

check( count( $arrErrors ) === 1, 'S5: getriggerter saveUser-Alias ohne Permission erzeugt genau einen Fehler' );
check( $arrErrors[ 0 ]->message() === 'Keine Berechtigung für diese Aktion.', 'S5: Meldungstext ist die Berechtigungs-Meldung' );
check( $arrErrors[ 0 ]->source() === 'changeUser', 'S5: Source ist der Alias-Trigger changeUser' );

// --- Alias separation: the method name as trigger ends as visible unknown action error ---

$_GET[ 'action' ] = 'saveUser';

Request::reset();
$objController = mockController( 'viewUserList' );
$objRole->setValue( $objController, $objRoleMock );

$objView->executeActions( $objController );

$arrErrors = $objController->errors();

check( count( $arrErrors ) === 1, 'S1: Methodenname als Trigger matcht nicht (Alias trennt) — genau ein Fehler' );
check( $arrErrors[ 0 ]->message() === 'Unbekannte Aktion.', 'S1: Meldungstext ist die Unbekannt-Meldung, kein Berechtigungs-Leak' );
check( $arrErrors[ 0 ]->source() === 'saveUser', 'S1: Source ist der unbeantwortete action-Parameter' );

// --- Happy path: permissioned trigger runs the own body instead of the permission error ---

$_GET              = [];
$_POST             = [];
$_GET[ 'action' ] = 'createUser';

Request::reset();
$objController = mockController( 'viewUserList' );
$objRole->setValue( $objController, new Role( 'administrator' ) );

$objView = new View( 'viewUserList' );
$objView->executeActions( $objController );

$arrErrors = $objController->errors();

check( count( $arrErrors ) >= 1, 'Happy Path: getriggerter createUser mit Permission läuft (Feld-Fehler statt Permission-Fehler)' );
check( $arrErrors[ 0 ]->message() === 'Bitte eine E-Mail-Adresse angeben.', 'Happy Path: Fehler kommt aus dem createUser-Körper, nicht aus der Permission' );
check( $arrErrors[ 0 ]->source() === 'createUser', 'Happy Path: Source ist der createUser-Trigger' );

Request::reset();

echo 'Alle Tests bestanden.' . PHP_EOL;

// EOF
