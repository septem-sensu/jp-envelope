<?php

declare( strict_types = 1 );

/**
 * This suite is the regression test for the user envelopes: users travel
 * as envelopes into the collector instead of assignTemplateVar, the
 * password hash stays filtered by NOSERIALIZE and the view instance no
 * longer pollutes the collector. SKIP paths: without a local users.json
 * the serialization stays unchecked.
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.10
 * @since     2026-10-18
 *
 * @example   php includes/tests/userListTest.php
 *
 */

require_once ( __DIR__ . '/bootstrap.php' );

$strUserFile = BaseObject::FILEPATHJSON . 'data/users.json';

if( ! file_exists( $strUserFile ) ) {
  echo 'SKIP: keine lokale users.json — User-Serialisierung nicht prüfbar.' . PHP_EOL;
  exit( 0 );
}

$arrAllUser = BaseObject::getObjectsFromClass( 'User' );

if( count( $arrAllUser ) === 0 ) {
  echo 'SKIP: users.json enthält keine User.' . PHP_EOL;
  exit( 0 );
}

// --- NOSERIALIZE: the password hash (SHA256 comparison value) must never be serialized ---

check( in_array( 'password', User::NOSERIALIZE ), 'NOSERIALIZE: password gefiltert' );
check( in_array( 'dataJsonFile', User::NOSERIALIZE ), 'NOSERIALIZE: dataJsonFile weiterhin gefiltert (kein Array-Union-Bug)' );
check( in_array( 'actionNames', User::NOSERIALIZE ), 'NOSERIALIZE: actionNames weiterhin gefiltert' );

$objFirstUser   = $arrAllUser[ 0 ];
$objStdUser     = $objFirstUser->serializeObject();
$arrUserKeyList = array_keys( get_object_vars( $objStdUser ) );

check( ! in_array( 'password', $arrUserKeyList ), 'serializeObject: kein password-Key im serialisierten User' );
check( in_array( 'id', $arrUserKeyList ), 'serializeObject: id bleibt serialisiert' );
check( in_array( 'roleId', $arrUserKeyList ), 'serializeObject: roleId bleibt serialisiert' );

// --- collectUsers: every user as a rawObject instead of assignTemplateVar ---

$objController = mockController();

User::collectUsers( $objController );

$arrRawObjects = $objController->rawObjects();
check( count( $arrRawObjects ) === count( $arrAllUser ), 'collectUsers: jeder User als Envelope im Kollektor' );

$boolAllUser     = true;
$boolNoPassword  = true;
$boolAllDataIds  = true;

for( $i = 0; $i < count( $arrRawObjects ); $i++ ) {
  if( $arrRawObjects[ $i ]->class !== 'User' ) $boolAllUser   = false;
  if( $arrRawObjects[ $i ]->data->class !== 'User' ) $boolAllUser = false;
  if( $arrRawObjects[ $i ]->data->id !== $arrAllUser[ $i ]->id() ) $boolAllDataIds = false;
  if( isset( $arrRawObjects[ $i ]->data->password ) ) $boolNoPassword = false;
}

check( $boolAllUser, 'Envelope: class überall User (PHP-Klassenname groß)' );
check( $boolAllDataIds, 'Envelope: data.id entspricht der jeweiligen Instanz-Id' );
check( $boolNoPassword, 'Envelope: in keinem data ein Passwort-Hash' );

$arrRawObjectsJson = json_decode( getTemplateVar( $objController->presentation(), 'default::rawObjects' ) );
check( count( $arrRawObjectsJson ) === count( $arrAllUser ), 'Template-Var: default::rawObjects trägt alle Envelopes als JSON' );

// --- View instance: view() is only a template carrier and no longer throws itself into the collector ---

$objViewController = mockController( 'viewUserList' );

User::collectUsers( $objViewController );

$objViewUser = new User();
$strContent  = $objViewUser->view( $objViewController );

$arrRawObjects = $objViewController->rawObjects();
check( $strContent !== '', 'view(): rendert die Template-Kette' );
check( count( $arrRawObjects ) === count( $arrAllUser ), 'View-Instanz: kein View-Envelope — nur echte User im Kollektor' );

echo 'Alle Tests bestanden.' . PHP_EOL;

// EOF
