<?php

declare( strict_types = 1 );

/**
 * This suite is the regression test for the user actions createUser,
 * saveUser, saveUserPassword and deleteUser — create, change, set a
 * password, delete — administrator-only via roles.json. Since the S4
 * whitelist removal the request layer no longer filters role values:
 * unknown roles reach the action and turn into a visible AppError there.
 * users.json is a local copy of production data: the suite start creates
 * a .bak backup and the shutdown handler always restores the original
 * state — even after a FEHLER exit of check().
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.11
 * @since     2026-10-19
 *
 * @example   php includes/tests/userSaveDeleteTest.php
 *
 */

require_once ( __DIR__ . '/bootstrap.php' );

$strUserFile = BaseObject::FILEPATHJSON . 'data/users.json';

if( ! file_exists( $strUserFile ) ) {
  echo 'SKIP: keine lokale users.json — Save/Delete nicht prüfbar.' . PHP_EOL;
  exit( 0 );
}

if( count( BaseObject::getObjectsFromClass( 'User' ) ) === 0 ) {
  echo 'SKIP: users.json enthält keine User.' . PHP_EOL;
  exit( 0 );
}

copy( $strUserFile, $strUserFile . '.bak' );

/**
 * This shutdown handler restores the users.json backup on shutdown — it runs
 * on every suite end, including a FEHLER exit of check().
 *
 * @return    void
 *
 */
register_shutdown_function( function() use ( $strUserFile ) : void {
  $strBackupFile = $strUserFile . '.bak';

  if( ! file_exists( $strBackupFile ) ) return;

  copy( $strBackupFile, $strUserFile );
  unlink( $strBackupFile );

  return;
} );

$strOriginalMd5 = md5_file( $strUserFile );
$arrAllUser      = BaseObject::getObjectsFromClass( 'User' );
$strTargetId     = $arrAllUser[ 0 ]->id();
$objSavedUser    = null;

$_GET  = [];
$_POST = [];

Request::reset();
$objController = mockController( 'viewUserList' );
$strCurrentUserId = $objController->user()->id();

// --- guards: permissions only for administrator ---

check( in_array( 'User::saveUser', ( new Role( 'administrator' ) )->permissions() ), 'Permission: administrator darf User::saveUser' );
check( in_array( 'User::saveUserPassword', ( new Role( 'administrator' ) )->permissions() ), 'Permission: administrator darf User::saveUserPassword' );
check( in_array( 'User::deleteUser', ( new Role( 'administrator' ) )->permissions() ), 'Permission: administrator darf User::deleteUser' );
check( in_array( 'User::createUser', ( new Role( 'administrator' ) )->permissions() ), 'Permission: administrator darf User::createUser' );
check( in_array( 'User::saveUserPassword', ( new Role( 'user' ) )->permissions() ?? [] ), 'Permission: Rolle user darf Passwort ändern' );
check( ! in_array( 'User::deleteUser', ( new Role( 'user' ) )->permissions() ?? [] ), 'Permission: Rolle user darf nicht löschen' );

// --- S1: without an 'action' parameter the router calls no triggered action at all ---

Request::reset();
Request::setScope( 'viewUserList' );

$_POST[ 'id' ] = $strTargetId;

$objRoutingView = new View( 'viewUserList' );
$objRoutingView->executeActions( $objController );

check( md5_file( $strUserFile ) === $strOriginalMd5, 'S1: ohne action-Parameter lässt das Routing users.json unverändert' );
check( count( $objController->errors() ) === 0, 'S1: ohne action-Parameter bleibt der Controller ohne Fehler' );

// --- find a target user for foreign access and an administrator ---

$strOtherUserId = null;
$strAdminId     = null;

// Skip the administrator: the 'other' user must never be the administrator itself — otherwise the
// foreign-change block below degenerates into self-degradation, which saveUser correctly rejects
// (happened on 2026-10-20: the first non-guest in users.json was at the same time the only administrator).
foreach( $arrAllUser as $objUser ) {
  if( $objUser->id() === $strCurrentUserId ) continue;
  if( $objUser->roleId() === 'administrator' ) {
    $strAdminId = $objUser->id();

    continue;
  }
  if( ! isset( $strOtherUserId ) ) $strOtherUserId = $objUser->id();
}

// --- saveUserPassword: change the own password ---

Request::reset();
Request::setScope( 'viewUserList' );

$objBeforeUser   = BaseObject::getObject( 'User', $strCurrentUserId );
$strBeforeRoleId = $objBeforeUser->roleId();

$_POST[ 'id' ]        = $strCurrentUserId;
$_POST[ 'password' ]  = 'NeuesTest12!';
$_POST[ 'password2' ] = 'NeuesTest12!';

User::saveUserPassword( $objController );

$objSavedUser = new User( $strCurrentUserId );

check( hash_equals( BaseObject::enCrypteOnly( 'NeuesTest12!' ), $objSavedUser->password() ), 'saveUserPassword: eigenes Passwort neu gespeichert' );
check( $objSavedUser->roleId() === $strBeforeRoleId, 'saveUserPassword: roleId bleibt unberührt' );

$objSavedRaw = BaseObject::loadFileDeCrypted( $strUserFile )->{ $strCurrentUserId };

check( isset( $objSavedRaw->password ), 'saveObject persistiert den Passwort-Hash (NOSERIALIZE greift nur aufs Frontend)' );
check( ! isset( $objSavedRaw->isUserSet ), 'NOSAVE: Laufzeit-Flag isUserSet wird nicht persistiert' );

$strAfterPasswordMd5 = md5_file( $strUserFile );

// --- saveUserPassword: mismatching password confirmation does not write ---

Request::reset();
Request::setScope( 'viewUserList' );

$_POST[ 'id' ]        = $strCurrentUserId;
$_POST[ 'password' ]  = 'NeuesTest12!';
$_POST[ 'password2' ] = 'AnderesPasswort1!';

User::saveUserPassword( $objController );

check( md5_file( $strUserFile ) === $strAfterPasswordMd5, 'saveUserPassword: unterschiedliche Passwort-Wiederholung schreibt nicht' );

$objErrorController = mockController( 'viewUserList' );
User::saveUserPassword( $objErrorController );

$arrErrors = $objErrorController->errors();

check( count( $arrErrors ) === 1 && $arrErrors[ 0 ]->message() === 'Passwort und Passwort-Wiederholung stimmen nicht überein.', 'Fehlerkanal: ungleiche Passwort-Wiederholung wird gemeldet' );

// --- saveUserPassword: weak password does not write (validator from configs.json) ---

Request::reset();
Request::setScope( 'viewUserList' );

$_POST[ 'id' ]        = $strCurrentUserId;
$_POST[ 'password' ]  = 'schwachespasswort';
$_POST[ 'password2' ] = 'schwachespasswort';

User::saveUserPassword( $objController );

check( md5_file( $strUserFile ) === $strAfterPasswordMd5, 'saveUserPassword: schwaches Passwort wird abgewiesen' );

$objErrorController = mockController( 'viewUserList' );
User::saveUserPassword( $objErrorController );

$arrErrors = $objErrorController->errors();

check( count( $arrErrors ) === 1 && $arrErrors[ 0 ]->message() === 'Das Passwort entspricht nicht den Passwort-Regeln.', 'Fehlerkanal: schwaches Passwort wird gemeldet' );

// --- saveUser: self-degradation guard — an administrator does not change the own role ---

if( isset( $strAdminId ) ) {
  $objAdminController = mockController( 'viewUserList' );

  ( new ReflectionProperty( 'Controller', 'user' ) )->setValue( $objAdminController, new User( $strAdminId ) );
  ( new ReflectionProperty( 'Controller', 'role' ) )->setValue( $objAdminController, new Role( 'administrator' ) );

  Request::reset();
  Request::setScope( 'viewUserList' );

  $_POST[ 'id' ]     = $strAdminId;
  $_POST[ 'roleId' ] = 'user';

  User::saveUser( $objAdminController );

  check( ( new User( $strAdminId ) )->roleId() === 'administrator', 'saveUser: Selbstdegradationsschutz hält die eigene Administrator-Rolle' );

  // Isolate password leftovers from the previous block — otherwise saveUser reports the password rules besides the warning.
  unset( $_POST[ 'password' ], $_POST[ 'password2' ] );

  $objAdminErrorController = mockController( 'viewUserList' );

  ( new ReflectionProperty( 'Controller', 'user' ) )->setValue( $objAdminErrorController, new User( $strAdminId ) );
  ( new ReflectionProperty( 'Controller', 'role' ) )->setValue( $objAdminErrorController, new Role( 'administrator' ) );

  User::saveUser( $objAdminErrorController );

  $arrErrors = $objAdminErrorController->errors();

  check( count( $arrErrors ) === 1 && $arrErrors[ 0 ]->severity() === 'warning', 'Fehlerkanal: Selbstdegradation wird als Warning gemeldet' );
  check( $arrErrors[ 0 ]->message() === 'Die eigene Rolle kann nicht geändert werden.', 'Fehlerkanal: Selbstdegradation meldet den passenden Text' );

  if( isset( $strOtherUserId ) ) {
    Request::reset();
    Request::setScope( 'viewUserList' );

    $_POST[ 'id' ]     = $strOtherUserId;
      $_POST[ 'roleId' ] = 'user';

    User::saveUser( $objAdminController );

    check( ( new User( $strOtherUserId ) )->roleId() === 'user', 'saveUser: Administrator darf die Rolle eines anderen Users weiter ändern' );
  }
} else {
  echo 'SKIP: kein Administrator-User — Selbstdegradationsschutz nicht prüfbar.' . PHP_EOL;
}

// --- deleteUser: remove another user ---

$strDeleteId = null;

foreach( $arrAllUser as $objUser ) {
  if( $objUser->id() === $strTargetId ) continue;
  if( $objUser->id() === $strCurrentUserId ) continue;

  $strDeleteId = $objUser->id();
  break;
}

if( isset( $strDeleteId ) ) {
  Request::reset();
  Request::setScope( 'viewUserList' );

  $_POST[ 'id' ]     = $strDeleteId;

  User::deleteUser( $objController );

  check( ! BaseObject::existsObject( 'User', $strDeleteId ), 'deleteUser: User aus users.json entfernt' );
} else {
  echo 'SKIP: kein zweiter nicht-aktueller User — Löschen nicht prüfbar.' . PHP_EOL;
}

// --- deleteUser: self-deletion guard ---

Request::reset();
Request::setScope( 'viewUserList' );

$_POST[ 'id' ]     = $strCurrentUserId;

User::deleteUser( $objController );

check( BaseObject::existsObject( 'User', $strCurrentUserId ), 'deleteUser: Selbstlöschschutz hält den eigenen Account' );

$objErrorController = mockController( 'viewUserList' );
User::deleteUser( $objErrorController );

$arrErrors = $objErrorController->errors();

check( count( $arrErrors ) === 1 && $arrErrors[ 0 ]->message() === 'Der eigene Account kann nicht gelöscht werden.', 'Fehlerkanal: Selbstlöschschutz wird gemeldet' );

// --- createUser: create a new user ---

$strCreateId = 'testenNeuerUser';

if( BaseObject::existsObject( 'User', $strCreateId ) ) {
  echo 'SKIP: Test-User ' . $strCreateId . ' existiert bereits — Anlegen nicht prüfbar.' . PHP_EOL;
} else {
  Request::reset();
  Request::setScope( 'viewUserList' );

  $_POST[ 'id' ]        = $strCreateId;
  $_POST[ 'password' ]  = 'AnlegeTest12!';
  $_POST[ 'password2' ] = 'AnlegeTest12!';
  $_POST[ 'roleId' ]    = 'user';

  User::createUser( $objController );

  check( BaseObject::existsObject( 'User', $strCreateId ), 'createUser: neuer User in users.json angelegt' );

  $objCreatedUser = new User( $strCreateId );

  check( hash_equals( BaseObject::enCrypteOnly( 'AnlegeTest12!' ), $objCreatedUser->password() ), 'createUser: Passwort-Hash gespeichert' );
  check( $objCreatedUser->roleId() === 'user', 'createUser: Rolle gespeichert' );

  $objCreatedRaw = BaseObject::loadFileDeCrypted( $strUserFile )->{ $strCreateId };

  check( isset( $objCreatedRaw->password ), 'createUser: Passwort-Hash persistiert' );
  check( ! isset( $objCreatedRaw->isUserSet ), 'createUser: Laufzeit-Flag isUserSet wird nicht persistiert' );

  $strCreatedMd5 = md5_file( $strUserFile );

  // --- createUser: an occupied id is not overwritten ---

  Request::reset();
  Request::setScope( 'viewUserList' );

  $_POST[ 'id' ]        = $strTargetId;
  $_POST[ 'password' ]  = 'UeberschreibTest4$';
  $_POST[ 'password2' ] = 'UeberschreibTest4$';
  $_POST[ 'roleId' ]    = 'user';

  User::createUser( $objController );

  check( md5_file( $strUserFile ) === $strCreatedMd5, 'createUser: existierende Id wird nicht überschrieben' );

  $objErrorController = mockController( 'viewUserList' );
  User::createUser( $objErrorController );

  $arrErrors = $objErrorController->errors();

  check( count( $arrErrors ) === 1 && $arrErrors[ 0 ]->message() === 'Unter der E-Mail-Adresse existiert bereits ein Account.', 'Fehlerkanal: belegte E-Mail-Adresse wird gemeldet' );

  // --- createUser: mismatching password confirmation does not create ---

  Request::reset();
  Request::setScope( 'viewUserList' );

  $_POST[ 'id' ]        = 'testenAndererUser';
  $_POST[ 'password' ]  = 'IrgendeinPasswort1!';
  $_POST[ 'password2' ] = 'UngleicheWiederhol2!';
  $_POST[ 'roleId' ]    = 'user';

  User::createUser( $objController );

  check( ! BaseObject::existsObject( 'User', 'testenAndererUser' ), 'createUser: ungleiche Passwort-Wiederholung legt nicht an' );

  $objErrorController = mockController( 'viewUserList' );
  User::createUser( $objErrorController );

  $arrErrors = $objErrorController->errors();

  check( count( $arrErrors ) === 1 && $arrErrors[ 0 ]->message() === 'Passwort und Passwort-Wiederholung stimmen nicht überein.', 'Fehlerkanal: ungleiche Passwort-Wiederholung beim Anlegen wird gemeldet' );

  // --- createUser: unknown role does not create ---

  Request::reset();
  Request::setScope( 'viewUserList' );

  $_POST[ 'id' ]        = 'testenDritterUser';
  $_POST[ 'password' ]  = 'IrgendeinPasswort1!';
  $_POST[ 'password2' ] = 'IrgendeinPasswort1!';
  $_POST[ 'roleId' ]    = 'gibtEsNicht';

  User::createUser( $objController );

  check( ! BaseObject::existsObject( 'User', 'testenDritterUser' ), 'createUser: unbekannte Rolle legt nicht an' );

  $objErrorController = mockController( 'viewUserList' );
  User::createUser( $objErrorController );

  $arrErrors = $objErrorController->errors();

  check( count( $arrErrors ) === 1 && $arrErrors[ 0 ]->message() === 'Die ausgewählte Rolle ist ungültig.', 'Fehlerkanal: unbekannte Rolle wird gemeldet' );

  // --- integration: remove the created user again via deleteUser ---

  Request::reset();
  Request::setScope( 'viewUserList' );

  $_POST[ 'id' ]     = $strCreateId;

  User::deleteUser( $objController );

  check( ! BaseObject::existsObject( 'User', $strCreateId ), 'deleteUser: den per createUser angelegten User wieder entfernt' );
}

Request::reset();

echo 'Alle Tests bestanden.' . PHP_EOL;

// EOF
