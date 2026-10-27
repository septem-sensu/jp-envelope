<?php

declare( strict_types = 1 );

/**
 * This suite is the regression test for FieldValidator: the server side
 * field validation runs against the same field definitions as the frontend
 * (includes/json/fields/user.json) — mandatory, password companion, match,
 * minlength, password security and select options, in the cascade order
 * above. It also pins the fieldset contract: without a fieldset every
 * field runs with its own mandatory flag, bare fieldset entries stay
 * mandatory, boolean entries downgrade to optional, fields without a
 * definition are skipped silently. The suite needs no production data —
 * every case sets its own request state (Request::reset() + setScope
 * between the scenarios, the cache survives $_GET/$_POST otherwise).
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.12
 * @since     2026-10-25
 *
 * @example   php includes/tests/fieldValidatorTest.php
 *
 */

require_once ( __DIR__ . '/bootstrap.php' );

/**
 * This helper resets the request state, sets the viewUserList scope
 * (the action view of the user management) and applies the given
 * parameters as POST values — the common prelude of every scenario.
 *
 * @param     array    $arrParams   The POST parameters of the scenario
 * @return    void
 *
 * @example   setParams( [ 'id' => 'user@example.com' ] );
 *
 */
function setParams( array $arrParams ) : void {
  $_GET  = [];
  $_POST = $arrParams;

  Request::reset();
  Request::setScope( 'viewUserList' );

  return;
}

// --- S1: without a fieldset every declared field runs, all fields mandatory ---

setParams( [] );

$arrFieldErrors = FieldValidator::validate( 'User' );

check( count( $arrFieldErrors ) === 3, 'S1: leere Anfrage validiert alle drei Felder' );
check( ( $arrFieldErrors[ 'id' ] ?? null ) === 'Bitte eine E-Mail-Adresse angeben.', 'S1: fehlende Id meldet den Override statt der Schablone' );
check( ( $arrFieldErrors[ 'password' ] ?? null ) === 'Bitte Passwort und Passwort-Wiederholung angeben.', 'S1: fehlendes Passwort meldet combined (Companion-Text)' );
check( ( $arrFieldErrors[ 'roleId' ] ?? null ) === 'Bitte Rolle angeben.', 'S1: fehlende Rolle meldet die Schablone mit nameDe' );

// --- S2: a complete valid request collects no errors ---

setParams( [
  'id'        => 'testen@example.com',
  'password'  => 'GanzSicheresPasswort7!',
  'password2' => 'GanzSicheresPasswort7!',
  'roleId'    => 'user'
] );

check( FieldValidator::validate( 'User' ) === [], 'S2: vollständige gültige Anlage collectiert keine Fehler' );

// --- S3: the fieldset downgrades both fields to optional — absence means unchanged ---

setParams( [] );

$arrFieldErrors = FieldValidator::validate( 'User', [ 'password' => false, 'roleId' => false ] );

check( $arrFieldErrors === [], 'S3: optionale Felder ohne Anfrage erzeugen keinen Fehler (saveUser-Fall)' );

// --- S4: password companion asymmetry — only the repeat sent turns into the companion message ---

setParams( [ 'password2' => 'NurWiederholung1!' ] );

$arrFieldErrors = FieldValidator::validate( 'User', [ 'password' => false ] );

check( count( $arrFieldErrors ) === 1 && $arrFieldErrors[ 'password' ] === 'Bitte Passwort und Passwort-Wiederholung angeben.', 'S4: alleinstehende Wiederholung meldet den Companion-Fehler' );

// --- S5: mismatching password confirmation ---

setParams( [ 'password' => 'ErstesPasswort1!', 'password2' => 'ZweiteWiederho2!' ] );

$arrFieldErrors = FieldValidator::validate( 'User', [ 'password' ] );

check( count( $arrFieldErrors ) === 1 && $arrFieldErrors[ 'password' ] === 'Passwort und Passwort-Wiederholung stimmen nicht überein.', 'S5: ungleiche Wiederholung meldet den Match-Fehler' );

// --- S6: weak password reports the security rule (configs passwordRules include minLength 8) ---

setParams( [ 'password' => 'schwachespasswort', 'password2' => 'schwachespasswort' ] );

$arrFieldErrors = FieldValidator::validate( 'User', [ 'password' ] );

check( count( $arrFieldErrors ) === 1 && $arrFieldErrors[ 'password' ] === 'Das Passwort entspricht nicht den Passwort-Regeln.', 'S6: wiederholungsgleiches schwaches Passwort meldet die Passwort-Regeln' );

// --- S7: minLength override of the id field (fields/user.json messages entry) ---

setParams( [ 'id' => 'ab' ] );

$arrFieldErrors = FieldValidator::validate( 'User', [ 'id' ] );

check( count( $arrFieldErrors ) === 1 && $arrFieldErrors[ 'id' ] === 'Die E-Mail-Adresse muss mindestens 3 Zeichen haben.', 'S7: zu kurze Id meldet den minLength-Override' );

// --- S8: short but matching passwords still report the security rule, not minLength ---

setParams( [ 'password' => 'abc12', 'password2' => 'abc12' ] );

$arrFieldErrors = FieldValidator::validate( 'User', [ 'password' ] );

check( count( $arrFieldErrors ) === 1 && $arrFieldErrors[ 'password' ] === 'Das Passwort entspricht nicht den Passwort-Regeln.', 'S8: match und minLength (5) ok — Security (passwordMinLength 8) greift zuerst' );

// --- S9: fieldset entries without a field definition are skipped silently ---

setParams( [] );

check( FieldValidator::validate( 'User', [ 'gibtEsNicht' ] ) === [], 'S9: Fieldset-Eintrag ohne Felddefinition wird still übersprungen' );

Request::reset();

echo 'Alle Tests bestanden.' . PHP_EOL;

// EOF
